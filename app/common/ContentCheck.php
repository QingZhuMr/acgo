<?php
declare(strict_types=1);

namespace app\common;

/**
 * 文字内容审核
 *
 * 当前实现：本地敏感词库（SensitiveLexicon，Trie 匹配）。
 * 词库由后台「更新敏感词库」从 GitHub 拉取并二次过滤后落盘 JSON，
 * 回退到数据库 sensitive_word 表。
 * 后续接入第三方审核（腾讯云 / 百度 / 网易易盾 / 阿里云等）时，
 * 只需在 check() 方法内追加调用即可，方法签名保持不变，调用方无需改动。
 */
class ContentCheck
{
    /**
     * 检测文本是否包含敏感词
     *
     * @param string $text 待检测文本
     * @return array{hit:bool, words:string[]} hit=true 表示命中敏感词，words 为命中的词列表
     */
    public static function check(string $text): array
    {
        return SensitiveLexicon::check($text);
    }
}
