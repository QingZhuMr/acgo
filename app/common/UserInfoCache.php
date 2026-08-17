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

    /** 缓存 key 前缀（store 前缀为空，此处自带完整业务前缀） */
    public const KEY_PREFIX = 'acgo:user_info:';

    /** 默认头像（avatar 为空时展示用） */
    public const DEFAULT_AVATAR = '/static/admin/img/default-avatar.png';

    /** 用户已注销/被删除时的展示昵称（帖子/评论/通知里的幽灵用户兜底） */
    public const GHOST_NAME = '已注销用户';

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
                    $info = $cache->get(self::KEY_PREFIX . $id);
                    if (is_array($info)) {
                        // 兜底清洗历史缓存（旧版本可能存了空昵称/空头像）
                        $info = self::sanitize($id, $info);
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
                $id    = (int) $row['id'];
                $found[$id] = [
                    // 存在但昵称为空时显示「用户+ID」；头像缺失显示默认图
                    'nickname' => (string) ($row['nickname'] ?? '') ?: ('用户' . $id),
                    'avatar'   => (string) ($row['avatar'] ?? '') ?: self::DEFAULT_AVATAR,
                ];
            }
            // 查不到的用户（已注销/被删除）写幽灵占位，防缓存击穿
            foreach ($miss as $id) {
                if (!isset($found[$id])) {
                    $found[$id] = [
                        'nickname' => self::GHOST_NAME,
                        'avatar'   => self::DEFAULT_AVATAR,
                    ];
                }
            }
            $map += $found;

            // 回填 Redis（仅 Redis 可用时）
            if (!self::$redisDown && $cache) {
                try {
                    foreach ($found as $id => $info) {
                        $cache->set(self::KEY_PREFIX . $id, $info, self::TTL);
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
        return $map[$uid] ?? ['nickname' => self::GHOST_NAME, 'avatar' => self::DEFAULT_AVATAR];
    }

    /**
     * 兜底清洗单个缓存信息：昵称/头像为空时补默认值
     *
     * @param int   $uid  用户 ID（存在但昵称空时显示「用户+ID」）
     * @param array $info 缓存里的信息
     * @return array{0:string,1:string}
     */
    private static function sanitize(int $uid, array $info): array
    {
        return [
            'nickname' => (string) ($info['nickname'] ?? '') ?: ('用户' . $uid),
            'avatar'   => (string) ($info['avatar'] ?? '') ?: self::DEFAULT_AVATAR,
        ];
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
            $cache->delete(self::KEY_PREFIX . $uid);
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
