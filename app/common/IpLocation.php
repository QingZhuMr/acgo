<?php
declare(strict_types = 1);

namespace app\common;

use think\facade\Db;

/**
 * IP 归属地解析（公共方法，支持高德/腾讯/百度三家可切换）
 *
 * 设计：写入时解析一次并落库为 location 快照，读取时直接读 location，零接口调用。
 * 规则：省/直辖市 -> 省份名；内网/国外(无国家)/解析失败/未配置 key -> 「未知」。
 *
 * 服务商由 config 表 ip_loc_provider 决定（amap=高德 / qq=腾讯 / baidu=百度，默认 amap）。
 * - 高德：https://restapi.amap.com/v3/ip          返回根级 province
 * - 腾讯：https://apis.map.qq.com/ws/location/v1/ip 返回 result.ad_info.province，且带 nation（国外可显示国家）
 * - 百度：https://api.map.baidu.com/location/ip    返回 content.address_detail.province
 */
class IpLocation
{
    /** 请求超时（毫秒），查询失败不阻塞发帖 */
    private const TIMEOUT_MS = 1500;

    /** 支持的服务商标识 => 显示名 */
    public const PROVIDERS = [
        'amap'   => '高德',
        'qq'     => '腾讯',
        'baidu'  => '百度',
    ];

    /**
     * 解析 IP -> 归属地名称（省/直辖市），失败返回「未知」
     *
     * @param string $ip 客户端 IP
     */
    public static function resolve(string $ip): string
    {
        $ip = trim($ip);
        if ('' === $ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '未知';
        }

        // 内网/回环/保留地址直接「未知」，不发起外网请求
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '未知';
        }

        $provider = self::config('ip_loc_provider');
        if ('' === $provider) {
            $provider = 'amap';
        }

        return match ($provider) {
            'qq'     => self::resolveTencent($ip),
            'baidu'  => self::resolveBaidu($ip),
            default  => self::resolveAmap($ip),
        };
    }

    /**
     * 高德 IP 定位：status === '1' 成功，根级 province
     */
    private static function resolveAmap(string $ip): string
    {
        $key = self::config('amap_key');
        if ('' === $key) {
            return '未知';
        }

        $url  = 'https://restapi.amap.com/v3/ip?key=' . rawurlencode($key) . '&ip=' . rawurlencode($ip);
        $data = self::getJson($url);
        if (null === $data || '1' !== (string) ($data['status'] ?? '')) {
            return '未知';
        }

        $province = trim((string) ($data['province'] ?? ''));
        return '' === $province ? '未知' : $province;
    }

    /**
     * 腾讯 IP 定位：status === 0 成功，result.ad_info.province；国外用 nation 兜底
     */
    private static function resolveTencent(string $ip): string
    {
        $key = self::config('qqmap_key');
        if ('' === $key) {
            return '未知';
        }

        $path   = '/ws/location/v1/ip';
        $params = ['ip' => $ip, 'key' => $key];
        ksort($params, SORT_STRING); // 参数名升序，签名与最终 URL 顺序保持一致
        $query  = http_build_query($params);

        // 配置了 SK 则启用签名校验（新版 sig 签名）：
        // 2024 年起腾讯已弃用 sn 参数，改用 sig：
        // sig = MD5(path + "?" + URL 编码后的参数串 + SK)，32 位小写，不再整体 urlencode
        $sk = self::config('qqmap_sk');
        if ('' !== $sk) {
            $sig   = md5($path . '?' . $query . $sk);
            $query .= '&sig=' . $sig;
        }

        $url  = 'https://apis.map.qq.com' . $path . '?' . $query;
        $data = self::getJson($url);
        if (null === $data || 0 !== (int) ($data['status'] ?? -1)) {
            return '未知';
        }

        $ad       = is_array($data['result']['ad_info'] ?? null) ? $data['result']['ad_info'] : [];
        $province = trim((string) ($ad['province'] ?? ''));
        if ('' !== $province) {
            return $province;
        }

        // 国内省级为空但返回了国家（国外 IP），用国家名兜底
        $nation = trim((string) ($ad['nation'] ?? ''));
        if ('' !== $nation && '中国' !== $nation) {
            return $nation;
        }

        return '未知';
    }

    /**
     * 百度 IP 定位：status === 0 成功，content.address_detail.province
     */
    private static function resolveBaidu(string $ip): string
    {
        $ak = self::config('baidu_ak');
        if ('' === $ak) {
            return '未知';
        }

        $url  = 'https://api.map.baidu.com/location/ip?ak=' . rawurlencode($ak) . '&ip=' . rawurlencode($ip) . '&coor=bd09ll';
        $data = self::getJson($url);
        if (null === $data || 0 !== (int) ($data['status'] ?? -1)) {
            return '未知';
        }

        $province = trim((string) ($data['content']['address_detail']['province'] ?? ''));
        return '' === $province ? '未知' : $province;
    }

    /**
     * 读取配置项值（不存在返回空串）
     */
    private static function config(string $name): string
    {
        return (string) Db::name('config')->where('name', $name)->value('value');
    }

    /**
     * GET + JSON 解码，失败返回 null
     */
    private static function getJson(string $url): ?array
    {
        $body = self::httpGet($url);
        if ('' === $body) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * GET 请求，优先 cURL，降级 file_get_contents；失败返回空串
     */
    private static function httpGet(string $url): string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL               => $url,
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER    => false,
                CURLOPT_SSL_VERIFYHOST    => 0,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) ? $body : '';
        }

        $ctx  = stream_context_create(['http' => ['timeout' => self::TIMEOUT_MS / 1000]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
}
