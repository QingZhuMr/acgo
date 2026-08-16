<?php
declare(strict_types=1);

namespace app\common;

use think\facade\Db;

/**
 * 敏感词库管理 + 匹配引擎
 *
 * 词库来源：https://github.com/konsheng/Sensitive-lexicon
 *
 * 平衡策略（在言论自由与合规之间取舍，避免正常交流被误伤）：
 *  1. 只纳入「高置信度」类别：色情 / 暴恐 / 涉枪涉爆 / 反动 / 政治。
 *     这些是「明显色情暴力」与「过于政治敏感」的内容，必须拦截。
 *  2. 排除「泛化 / 易误伤」类别：民生、广告、疫情、贪腐、非法网址、
 *     网易前端过滤、腾讯零时、GFW 补充等（这类词库往往包含大量日常用语，
 *     全量接入会导致正常发帖被大量误杀，故不予采纳）。
 *  3. 词条二次过滤：跳过单字词（误伤率极高）、超长词、纯 URL/ASCII 词，
 *     白名单放行，并去重。
 *  4. 匹配用 Trie（DFA）算法，时间复杂度 O(文本长度 + 命中数)，
 *     不随词库规模线性增长，适配 2000 字正文与数万词库。
 */
class SensitiveLexicon
{
    /** 本地词库 JSON 文件名（存放于 public/static 静态目录） */
    private const FILE = 'sensitive_lexicon.json';

    /** 词库存放目录（相对 public 静态目录，保持 static 权限体系一致） */
    private const DIR = 'static';

    /** GitHub 仓库默认分支（优先 main，下载失败时回退 master） */
    private const BRANCHES = ['main', 'master'];

    /**
     * 词库下载源（按优先级顺序回退，任一源成功即停止）
     * 国内服务器直连 raw.githubusercontent.com 常无法解析，
     * 故优先走 jsDelivr CDN 与 gh-proxy 代理，最后回退 GitHub 直连。
     */
    private const DOWNLOAD_BASES = [
        'https://cdn.jsdelivr.net/gh/konsheng/Sensitive-lexicon@{branch}/Vocabulary/{file}',
        'https://gh-proxy.com/https://raw.githubusercontent.com/konsheng/Sensitive-lexicon/{branch}/Vocabulary/{file}',
        'https://raw.githubusercontent.com/konsheng/Sensitive-lexicon/{branch}/Vocabulary/{file}',
    ];

    /** 单字词长度下限（单字跳过，避免误伤） */
    private const MIN_LEN = 2;

    /** 词条长度上限（超过视为句子片段，跳过） */
    private const MAX_LEN = 30;

    /** 下载超时（秒） */
    private const TIMEOUT = 30;

    /**
     * 纳入的「高置信度」词库（文件名 => 说明）
     * 只保留明显违规的类别，其余泛化词库一律不采纳。
     */
    private const SOURCES = [
        '色情词库.txt'   => '色情',
        '色情类型.txt'   => '色情',
        '暴恐词库.txt'   => '暴恐',
        '涉枪涉爆.txt'   => '涉枪涉爆',
        '反动词库.txt'   => '政治敏感',
        '政治类型.txt'   => '政治敏感',
    ];

    /**
     * 明确排除的类别及原因（保留说明，便于后续调整）
     */
    private const EXCLUDED = [
        '民生词库.txt'           => '民生议题，正常讨论易误伤',
        '广告类型.txt'           => '广告非违规内容',
        'COVID-19词库.txt'       => '疫情话题，正常讨论',
        '其他词库.txt'           => '泛化词汇',
        '补充词库.txt'           => '泛化词汇',
        '贪腐词库.txt'           => '反腐话题正常讨论',
        '新思想启蒙.txt'         => '概念性词汇',
        '非法网址.txt'           => 'URL 另行处理',
        'GFW补充词库.txt'        => '泛化政治词，误伤极高',
        '网易前端过滤敏感词库.txt' => '前端粗过滤，误伤极高',
        '零时-Tencent.txt'       => '70 万+ 词，误伤极高',
    ];

    /**
     * 白名单：命中这些词条视为放行（覆盖词库中可能误收录的日常词）
     * 键为词条，值为说明。
     */
    private const WHITELIST = [];

    /**
     * 检测文本是否命中敏感词
     *
     * @param string $text 待检测文本
     * @return array{hit:bool, words:string[]}
     */
    public static function check(string $text): array
    {
        $text = trim($text);
        if ('' === $text) {
            return ['hit' => false, 'words' => []];
        }

        $trie = self::loadTrie();
        if (empty($trie)) {
            return ['hit' => false, 'words' => []];
        }

        // 按 UTF-8 拆分为字符数组，避免内层重复 mb_substr
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return ['hit' => false, 'words' => []];
        }

        $hits = [];
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $node = $trie;
            for ($j = $i; $j < $n; $j++) {
                $ch = $chars[$j];
                if (!isset($node[$ch])) {
                    break;
                }
                $node = $node[$ch];
                if (isset($node["\0"])) {
                    $hits[$node["\0"]] = true;
                }
            }
        }

        return ['hit' => !empty($hits), 'words' => array_keys($hits)];
    }

    /**
     * 从 GitHub 拉取并更新本地词库
     *
     * @return array{ok:bool, count:int, sources:int, updated_at:int, error:string}
     */
    public static function update(): array
    {
        $rawWords = [];
        $okSources = 0;
        $lastError = '';

        foreach (array_keys(self::SOURCES) as $file) {
            $content = self::download($file);
            if (null === $content) {
                $lastError = '下载失败：' . $file;
                continue;
            }
            $okSources++;
            $rawWords = array_merge($rawWords, self::parse($content));
        }

        if ($okSources === 0) {
            return [
                'ok'         => false,
                'count'      => 0,
                'sources'    => 0,
                'updated_at' => 0,
                'error'      => '所有词库下载失败（' . $lastError . '），请检查服务器外网连通性',
            ];
        }

        $words = self::filter($rawWords);
        if (empty($words)) {
            return [
                'ok'         => false,
                'count'      => 0,
                'sources'    => $okSources,
                'updated_at' => 0,
                'error'      => '过滤后词条为空，请检查词库内容',
            ];
        }

        $payload = [
            'version'    => 'konsheng/Sensitive-lexicon',
            'updated_at' => time(),
            'sources'    => $okSources,
            'count'      => count($words),
            'words'      => array_values($words),
        ];

        if (!self::save($payload)) {
            return [
                'ok'         => false,
                'count'      => count($words),
                'sources'    => $okSources,
                'updated_at' => $payload['updated_at'],
                'error'      => '写入本地词库 JSON 失败，请检查 public/static 目录权限',
            ];
        }

        return [
            'ok'         => true,
            'count'      => count($words),
            'sources'    => $okSources,
            'updated_at' => $payload['updated_at'],
            'error'      => '',
        ];
    }

    /**
     * 词库元信息
     *
     * @return array{exists:bool, count:int, updated_at:int, sources:int}
     */
    public static function info(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return ['exists' => false, 'count' => 0, 'updated_at' => 0, 'sources' => 0];
        }
        $data = self::read();
        if (!is_array($data)) {
            return ['exists' => false, 'count' => 0, 'updated_at' => 0, 'sources' => 0];
        }
        return [
            'exists'     => true,
            'count'      => (int) ($data['count'] ?? 0),
            'updated_at' => (int) ($data['updated_at'] ?? 0),
            'sources'    => (int) ($data['sources'] ?? 0),
        ];
    }

    /**
     * 加载词条：网络词库（本地 JSON）+ 平台自定义词（数据库 sensitive_word 表）合并去重
     *
     * @return array<int, string>
     */
    private static function loadWords(): array
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }

        $words = [];

        // 1. 网络词库（本地 JSON，由后台「更新网络词库」拉取）
        $data = self::read();
        if (is_array($data) && !empty($data['words'])) {
            $words = array_values($data['words']);
        }

        // 2. 平台自定义敏感词（数据库），始终叠加生效
        try {
            $custom = Db::name('sensitive_word')->column('word');
            if (is_array($custom)) {
                $words = array_merge($words, array_values($custom));
            }
        } catch (\Throwable) {
            // 表不存在等异常时忽略，网络词库仍可生效
        }

        $cache = array_values(array_unique($words));
        return $cache;
    }

    /**
     * 构建/加载 Trie（请求内缓存）
     *
     * @return array<string, mixed>
     */
    private static function loadTrie(): array
    {
        static $trieCache = null;
        if (null !== $trieCache) {
            return $trieCache;
        }

        $trie = [];
        foreach (self::loadWords() as $word) {
            $word = (string) $word;
            if ('' === $word) {
                continue;
            }
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars)) {
                continue;
            }
            $node = &$trie;
            foreach ($chars as $ch) {
                if (!isset($node[$ch])) {
                    $node[$ch] = [];
                }
                $node = &$node[$ch];
            }
            $node["\0"] = $word;
        }
        unset($node);

        $trieCache = $trie;
        return $trieCache;
    }

    /**
     * 下载单个词库文件（返回原始文本，失败返回 null）
     */
    private static function download(string $file): ?string
    {
        foreach (self::DOWNLOAD_BASES as $base) {
            foreach (self::BRANCHES as $branch) {
                $url = str_replace(
                    ['{branch}', '{file}'],
                    [$branch, rawurlencode($file)],
                    $base
                );
                $content = self::httpGet($url);
                if (null !== $content && '' !== trim($content)) {
                    return $content;
                }
            }
        }
        return null;
    }

    /**
     * 解析原始文本为词条数组
     *
     * @return array<int, string>
     */
    private static function parse(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $words = [];
        foreach ((array) $lines as $line) {
            $w = self::normalize($line);
            if ('' !== $w) {
                $words[] = $w;
            }
        }
        return $words;
    }

    /**
     * 词条规范化：去空白、去注释、去引号
     */
    private static function normalize(string $line): string
    {
        $line = trim($line);
        if ('' === $line) {
            return '';
        }
        // 跳过注释行
        if (str_starts_with($line, '#') || str_starts_with($line, '//')) {
            return '';
        }
        // 去首尾引号/逗号等常见分隔符。
        // 注意：不能把全角字符（，；等）放进 trim() 的字符掩码——PHP 会把
        // 掩码当字节集处理，误删合法 UTF-8 词条的尾部字节（如“册”E5 86 8C
        // 的 8C、“军”E5 86 9B 的 9B），导致词条损坏、json_encode 失败。
        // 故 ASCII 标点用 trim 处理，全角标点改用 Unicode 正则。
        $line = trim($line, " \t\n\r\0\x0B,;'\"");
        $line = preg_replace('/^[\x{FF0C}\x{FF1B}\x{3001}]+|[\x{FF0C}\x{FF1B}\x{3001}]+$/u', '', $line);
        return $line;
    }

    /**
     * 二次过滤：单字、超长、纯 URL/ASCII、白名单、去重
     *
     * @param array<int, string> $rawWords
     * @return array<int, string>
     */
    private static function filter(array $rawWords): array
    {
        $result = [];
        $seen = [];
        foreach ($rawWords as $w) {
            $w = self::normalize($w);
            if ('' === $w) {
                continue;
            }

            $len = mb_strlen($w);
            if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
                continue;
            }

            // 纯 ASCII（URL / 英文数字 / 符号）跳过，避免误伤
            if (preg_match('/^[\x00-\x7F]+$/', $w)) {
                continue;
            }

            // 白名单放行
            if (isset(self::WHITELIST[$w])) {
                continue;
            }

            // 去重
            if (isset($seen[$w])) {
                continue;
            }
            $seen[$w] = true;
            $result[] = $w;
        }

        // 按长度降序排序：长词优先匹配，避免短词误命中长词内部
        usort($result, function (string $a, string $b) {
            $la = mb_strlen($a);
            $lb = mb_strlen($b);
            return $lb <=> $la ?: strcmp($a, $b);
        });

        return $result;
    }

    /**
     * 词库 JSON 本地路径（public/static 静态目录）
     */
    private static function path(): string
    {
        $dir = public_path() . self::DIR;
        return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * 读取本地 JSON
     *
     * @return array|null
     */
    private static function read(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 写入本地 JSON
     */
    private static function save(array $payload): bool
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            return false;
        }
        return false !== @file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * HTTP GET（优先 cURL，回退 file_get_contents）
     */
    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'ACGO-SensitiveLexicon/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // PHP 8.0+ 无需 curl_close，8.5 起已废弃
            unset($ch);
            return (false !== $body && $code >= 200 && $code < 300) ? (string) $body : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => self::TIMEOUT,
                'user_agent' => 'ACGO-SensitiveLexicon/1.0',
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return false !== $body ? $body : null;
    }
}
