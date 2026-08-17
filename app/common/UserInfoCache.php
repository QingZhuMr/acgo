<?php
declare(strict_types = 1);

namespace app\common;

use think\cache\Driver;
use think\facade\Cache;
use think\facade\Db;

/**
 * 用户昵称/头像缓存（去数据库冗余快照）
 *
 * 背景：帖子/评论/通知列表的用户昵称、头像全部改为**实时联表 user 表**获取，
 * 不再在 post_comment / notification 等表存冗余快照。为避免高频列表接口
 * 反复查询 user 表，这里提供一层 Redis 缓存（走 ThinkPHP 缓存门面）：
 *   - redis 可用（phpredis 扩展 + Redis 服务，store 名为 redis）时：读缓存，未命中查库并写回；
 *   - redis 不可用（无扩展 / 未启动 / 连接失败）时：自动降级**直接查库**，
 *     业务完全不受影响，仅缓存红利消失。
 * 缓存 TTL 默认 10 分钟；用户改昵称 / 换头像时调用 forget() 主动失效。
 */
class UserInfoCache
{
    /** 缓存有效期（秒）：10 分钟 */
    public const TTL = 600;

    /** Redis 缓存 store 名（对应 config/cache.php 的 redis 连接） */
    public const STORE = 'redis';

    /** 默认头像（avatar 为空时展示用） */
    public const DEFAULT_AVATAR = '/static/admin/img/default-avatar.png';

    /** 是否已确认 Redis 不可用（避免每次请求都抛异常降级） */
    private static bool $redisDown = false;

    /**
     * 批量获取用户昵称/头像（缓存优先，未命中查库并回填缓存）
     *
     * @param int[] $uids 用户 ID 列表
     * @return array [user_id => ['nickname' => string, 'avatar' => string]]
     *               不存在的用户返回空昵称/头像（同样缓存占位防击穿）
     */
    public static function batch(array $uids): array
    {
        $ids = array_values(array_unique(array_map('intval', $uids)));
        if (!$ids) {
            return [];
        }

        $map   = [];
        $miss  = [];
        $cache = self::cache();

        // 尝试从 Redis 读取（不可用时 self::$redisDown 置 true，后续直接走查库）
        if ($cache) {
            try {
                foreach ($ids as $id) {
                    $info = $cache->get((string) $id);
                    if (is_array($info)) {
                        $map[$id] = $info;
                    } else {
                        $miss[] = $id;
                    }
                }
            } catch (\Throwable $e) {
                // Redis 中途异常：整体降级查库
                self::$redisDown = true;
                $miss = $ids;
            }
        } else {
            $miss = $ids;
        }

        if ($miss) {
            $rows = Db::name('user')
                ->whereIn('id', $miss)
                ->field('id,nickname,avatar')
                ->select()
                ->toArray();
            $found = [];
            foreach ($rows as $row) {
                $info = [
                    'nickname' => (string) ($row['nickname'] ?? ''),
                    'avatar'   => (string) ($row['avatar'] ?? ''),
                ];
                $found[(int) $row['id']] = $info;
            }
            // 查不到的 uid 也写空占位，防缓存击穿
            foreach ($miss as $id) {
                if (!isset($found[$id])) {
                    $found[$id] = ['nickname' => '', 'avatar' => ''];
                }
            }
            $map += $found;

            // 回填 Redis（仅 Redis 可用时）
            if (!self::$redisDown && $cache) {
                try {
                    foreach ($found as $id => $info) {
                        $cache->set((string) $id, $info, self::TTL);
                    }
                } catch (\Throwable $e) {
                    self::$redisDown = true;
                }
            }
        }

        return $map;
    }

    /**
     * 获取单个用户昵称/头像
     */
    public static function get(int $uid): array
    {
        $map = self::batch([$uid]);
        return $map[$uid] ?? ['nickname' => '', 'avatar' => ''];
    }

    /**
     * 主动清除某用户的缓存（改昵称 / 换头像时调用，立即生效）
     */
    public static function forget(int $uid): void
    {
        if ($uid <= 0 || self::$redisDown) {
            return;
        }
        $cache = self::cache();
        if (!$cache) {
            return;
        }
        try {
            $cache->delete((string) $uid);
        } catch (\Throwable $e) {
            // 删除失败不影响业务
        }
    }

    /**
     * 获取 Redis 缓存驱动（不可用返回 null，自动降级）
     */
    private static function cache(): ?Driver
    {
        try {
            // 未安装 phpredis 扩展时直接降级（Redis 驱动依赖该扩展）
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
