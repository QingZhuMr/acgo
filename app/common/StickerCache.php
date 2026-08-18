<?php
declare(strict_types = 1);

namespace app\common;

use think\cache\Driver;
use think\facade\Cache;
use think\facade\Db;

/**
 * 表情包列表缓存（按用户维度）
 *
 * 背景：`sticker/list` 返回**当前用户自己的**表情包列表（自己上传的 + 收藏的，
 * ≤200 条），不同用户看到的内容互不相同（账号隔离）。变化极低频（仅本人
 * 上传/删除/收藏时变）。因此按用户维度将列表本体缓存进 Redis
 * （key 含 uid，TTL 10 分钟），防不同账号串号。
 *
 * 失效时机：sticker/upload、sticker/delete、sticker/add 后调用 forget()
 * （清空所有用户的 key，简单可靠——Redis KEYS 通配匹配）。
 *
 * 降级：Redis 不可用（无 phpredis / 未启动 / 连接失败）时自动直查库，业务不受影响。
 */
class StickerCache
{
    /** 缓存有效期（秒）：10 分钟 */
    public const TTL = 600;

    /** Redis 缓存 store 名（对应 config/cache.php 的 redis 连接） */
    public const STORE = 'redis';

    /** 缓存 key 前缀（完整 key = 前缀 + uid） */
    public const KEY_PREFIX = 'acgo:sticker:list:';

    /** 是否已确认 Redis 不可用 */
    private static bool $redisDown = false;

    /**
     * 获取指定用户的表情包列表（缓存优先，未命中查库并回填）
     *
     * @return array<int, array{id:int,user_id:int,url:string,width:int,height:int,mime:string}>
     */
    public static function get(int $uid): array
    {
        $key = self::KEY_PREFIX . $uid;
        $cache = self::cache();
        if ($cache) {
            try {
                $data = $cache->get($key);
                if (is_array($data)) {
                    return $data;
                }
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        // 账号隔离：只查当前用户自己的表情包（上传的 + 收藏的）
        $rows = Db::name('sticker')
            ->where('user_id', $uid)
            ->where('status', 1)
            ->field('id,user_id,url,width,height,mime')
            ->order('create_time', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'      => (int) $r['id'],
                'user_id' => (int) $r['user_id'],
                'url'     => (string) $r['url'],
                'width'   => (int) $r['width'],
                'height'  => (int) $r['height'],
                'mime'    => (string) $r['mime'],
            ];
        }

        if ($cache) {
            try {
                $cache->set($key, $list, self::TTL);
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        return $list;
    }

    /**
     * 清除指定用户的表情包列表缓存（上传/删除/收藏后调用，立即生效）
     *
     * 只清该用户自己的 key——上传/删除/收藏均只影响本人列表，
     * 无需（也无法在 Driver 抽象层）枚举其他用户的 key。
     */
    public static function forget(int $uid): void
    {
        if (self::$redisDown) {
            return;
        }
        $cache = self::cache();
        if (!$cache) {
            return;
        }
        try {
            $cache->delete(self::KEY_PREFIX . $uid);
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
