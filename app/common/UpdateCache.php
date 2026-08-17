<?php
declare(strict_types = 1);

namespace app\common;

use think\cache\Driver;
use think\facade\Cache;
use think\facade\Db;

/**
 * 客户端版本信息缓存
 *
 * 背景：`update/check` 按平台返回最新上线版本。该数据全局共享、变化极低频
 * （仅后台发布/编辑/上下线版本时变），而客户端启动/定时检查频繁调用。因此
 * 按平台将最新版本行缓存进 Redis（TTL 10 分钟）。
 *
 * 失效时机：admin/Update 的 add/edit/delete/toggle 后调用 forget(platform)。
 *
 * 降级：Redis 不可用（无 phpredis / 未启动 / 连接失败）时自动直查库，业务不受影响。
 */
class UpdateCache
{
    /** 缓存有效期（秒）：10 分钟 */
    public const TTL = 600;

    /** Redis 缓存 store 名（对应 config/cache.php 的 redis 连接） */
    public const STORE = 'redis';

    /** 缓存 key 前缀 */
    public const KEY_PREFIX = 'acgo:update:latest:';

    /** 是否已确认 Redis 不可用 */
    private static bool $redisDown = false;

    /**
     * 获取某平台最新上线版本（缓存优先，未命中查库并回填）
     *
     * @return array|null 版本行（含 id/platform/version/title/message/download_url/mandatory），无则 null
     */
    public static function latest(string $platform): ?array
    {
        $cache = self::cache();
        if ($cache) {
            try {
                $data = $cache->get(self::KEY_PREFIX . $platform);
                if (is_array($data)) {
                    return $data ?: null;
                }
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        $row = Db::name('update_version')
            ->where('platform', $platform)
            ->where('status', 1)
            ->order('id', 'desc')
            ->find();

        // 缓存无结果的占位（空数组），避免频繁查库；TTL 后自然失效
        $val = $row ?: [];
        if ($cache) {
            try {
                $cache->set(self::KEY_PREFIX . $platform, $val, self::TTL);
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        return $row ?: null;
    }

    /**
     * 清除某平台（或全部平台）的版本缓存
     *
     * @param string|null $platform 平台；null 清全部
     */
    public static function forget(?string $platform = null): void
    {
        if (self::$redisDown) {
            return;
        }
        $cache = self::cache();
        if (!$cache) {
            return;
        }
        try {
            if ($platform === null) {
                foreach (['win', 'mac', 'ios', 'android'] as $p) {
                    $cache->delete(self::KEY_PREFIX . $p);
                }
            } else {
                $cache->delete(self::KEY_PREFIX . $platform);
            }
        } catch (\Throwable $e) {
            self::$redisDown = true;
        }
    }

    /**
     * 获取 Redis 缓存驱动（不可用返回 null，自动降级）
     */
    private static function cache(): ?Driver
    {
        try {
            if (!extension_loaded('redis')) {
                self::$redisDown = true;
                return null;
            }
            return Cache::store(self::STORE);
        } catch (\Throwable $e) {
            self::$redisDown = true;
            return null;
        }
    }
}
