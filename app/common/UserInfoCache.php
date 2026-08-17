<?php
declare(strict_types = 1);

namespace app\common;

use think\facade\Db;

/**
 * 用户昵称/头像缓存（去数据库冗余快照）
 *
 * 背景：帖子/评论/通知列表的用户昵称、头像全部改为**实时联表 user 表**获取，
 * 不再在 post_comment / notification 等表存冗余快照。为避免高频列表接口
 * 反复查询 user 表，这里提供一层 Redis 缓存：
 *   - redis 可用（phpredis 扩展 + Redis 服务）时：读缓存，未命中查库并写回；
 *   - redis 不可用（无扩展 / 未启动 / 连接失败）时：自动降级**直接查库**，
 *     业务完全不受影响，仅缓存红利消失。
 * 缓存 TTL 默认 10 分钟；用户改昵称 / 换头像时调用 forget() 主动失效。
 */
class UserInfoCache
{
    /** 缓存有效期（秒）：10 分钟 */
    public const TTL = 600;

    /** Redis key 前缀 */
    public const KEY_PREFIX = 'acgo:user_info:';

    /** 默认头像（avatar 为空时展示用） */
    public const DEFAULT_AVATAR = '/static/admin/img/default-avatar.png';

    /** Redis 连接失败冷却时间（秒）：失败后一段时间不再重试连接 */
    private const REDIS_RETRY_GAP = 60;

    private static ?\Redis $redis = null;

    private static int $redisFailAt = 0;

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

        $map  = [];
        $miss = [];
        $r    = self::redis();

        if ($r) {
            try {
                $keys = array_map(fn ($id) => self::KEY_PREFIX . $id, $ids);
                $vals = $r->mget($keys);
                foreach ($ids as $i => $id) {
                    $v = $vals[$i] ?? false;
                    if (is_string($v) && $v !== '') {
                        $info = json_decode($v, true);
                        $map[$id] = is_array($info) ? $info : ['nickname' => '', 'avatar' => ''];
                    } else {
                        $miss[] = $id;
                    }
                }
            } catch (\Throwable $e) {
                // Redis 中途异常：整体降级查库
                $r = null;
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
                if ($r) {
                    self::set($r, (int) $row['id'], $info);
                }
            }
            // 查不到的 uid 也写空占位，防缓存击穿
            foreach ($miss as $id) {
                if (!isset($found[$id])) {
                    $found[$id] = ['nickname' => '', 'avatar' => ''];
                    if ($r) {
                        self::set($r, $id, $found[$id]);
                    }
                }
            }
            $map += $found;
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
        if ($uid <= 0) {
            return;
        }
        $r = self::redis();
        if (!$r) {
            return;
        }
        try {
            $r->del(self::KEY_PREFIX . $uid);
        } catch (\Throwable $e) {
            // 删除失败不影响业务
        }
    }

    /**
     * 写缓存
     */
    private static function set(\Redis $r, int $uid, array $info): void
    {
        try {
            $r->set(self::KEY_PREFIX . $uid, json_encode($info, JSON_UNESCAPED_UNICODE), self::TTL);
        } catch (\Throwable $e) {
            // 缓存写失败不影响业务
        }
    }

    /**
     * 获取 Redis 连接（失败自动降级返回 null）
     *
     * 连接参数读 .env（REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB / REDIS_TIMEOUT）。
     */
    private static function redis(): ?\Redis
    {
        if (self::$redis) {
            return self::$redis;
        }
        // 失败冷却期内不再重试，避免每次请求都尝试连接（PHP-FPM 短生命周期影响有限）
        if (self::$redisFailAt && time() - self::$redisFailAt < self::REDIS_RETRY_GAP) {
            return null;
        }
        try {
            if (!class_exists('Redis')) {
                self::$redisFailAt = time();
                return null;
            }
            $r = new \Redis();
            $host     = (string) env('REDIS_HOST', '127.0.0.1');
            $port     = (int) env('REDIS_PORT', 6379);
            $password = (string) env('REDIS_PASSWORD', '');
            $db       = (int) env('REDIS_DB', 0);
            $timeout  = (float) env('REDIS_TIMEOUT', 1.0);
            if (!$r->connect($host, $port, $timeout)) {
                self::$redisFailAt = time();
                return null;
            }
            if ($password !== '' && !$r->auth($password)) {
                self::$redisFailAt = time();
                return null;
            }
            if ($db > 0) {
                $r->select($db);
            }
            self::$redis = $r;
            return $r;
        } catch (\Throwable $e) {
            self::$redisFailAt = time();
            self::$redis = null;
            return null;
        }
    }
}
