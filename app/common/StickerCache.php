<?php
declare(strict_types = 1);

namespace app\common;

use think\cache\Driver;
use think\facade\Cache;
use think\facade\Db;

/**
 * 表情包列表缓存
 *
 * 背景：`sticker/list` 返回全局正常表情包列表（≤200 条），所有用户看到的内容
 * 相同（仅 is_mine 按当前用户区分），且变化极低频（仅上传/删除时变）。因此
 * 将列表本体缓存进 Redis（TTL 10 分钟），请求时只缓存一次查库，is_mine 在
 * 返回时按 uid 实时计算（不入缓存）。
 *
 * 失效时机：sticker/upload 与 sticker/delete 后调用 forget()。
 *
 * 降级：Redis 不可用（无 phpredis / 未启动 / 连接失败）时自动直查库，业务不受影响。
 */
class StickerCache
{
    /** 缓存有效期（秒）：10 分钟 */
    public const TTL = 600;

    /** Redis 缓存 store 名（对应 config/cache.php 的 redis 连接） */
    public const STORE = 'redis';

    /** 缓存 key */
    public const KEY = 'acgo:sticker:list';

    /** 是否已确认 Redis 不可用 */
    private static bool $redisDown = false;

    /**
     * 获取表情包列表（缓存优先，未命中查库并回填）
     *
     * @return array<int, array{id:int,user_id:int,url:string,width:int,height:int,mime:string}>
     */
    public static function get(): array
    {
        $cache = self::cache();
        if ($cache) {
            try {
                $data = $cache->get(self::KEY);
                if (is_array($data)) {
                    return $data;
                }
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        $rows = Db::name('sticker')
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
                $cache->set(self::KEY, $list, self::TTL);
            } catch (\Throwable $e) {
                self::$redisDown = true;
            }
        }

        return $list;
    }

    /**
     * 清除表情包列表缓存（上传/删除后调用，立即生效）
     */
    public static function forget(): void
    {
        if (self::$redisDown) {
            return;
        }
        $cache = self::cache();
        if (!$cache) {
            return;
        }
        try {
            $cache->delete(self::KEY);
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
