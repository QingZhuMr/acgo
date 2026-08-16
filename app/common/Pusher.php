<?php
declare(strict_types = 1);

namespace app\common;

use think\facade\Db;

/**
 * 实时推送封装：向 Swoole 推送服务投递主动推送消息。
 *
 * 实现方式：写入 push_queue 队列表（MySQL），Swoole WS 服务每秒轮询该表
 * 并向在线设备推送。这样 admin/api（PHP Web）与 WS 服务（PHP CLI）
 * 完全解耦，仅通过共享数据库协作，无需额外的内网 HTTP 通道。
 *
 * 使用场景（admin/api 后台操作后）：
 *   - 发布/上线新版本  -> pushUpdate
 *   - 封禁/注销/踢下线 -> forceLogout
 *   - 后台修改密码    -> forceLogout(密码已变更)
 *
 * 安全：写入走 ThinkPHP 数据层，无需暴露内部令牌；WS 服务只读本表。
 */
class Pusher
{
    /**
     * 写入一条待推送消息到队列。
     *
     * @param array $filter  目标：user_id(必填) / platform(可空) / device_id(可空)
     * @param array $payload 推送给客户端的消息体（需含 type 字段）
     * @return bool 是否成功入队
     */
    public static function send(array $filter, array $payload): bool
    {
        $userId = (int) ($filter['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            Db::name('push_queue')->insert([
                'user_id'     => $userId,
                'platform'    => (string) ($filter['platform'] ?? ''),
                'device_id'   => (string) ($filter['device_id'] ?? ''),
                'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'pushed'      => 0,
                'create_time' => time(),
            ]);
            return true;
        } catch (\Throwable $e) {
            // 入队失败静默（业务本身不依赖推送结果）
            return false;
        }
    }

    /**
     * 便捷：强制某设备下线（异地登录/封禁/注销/密码变更时调用）
     */
    public static function forceLogout(int $userId, string $platform, ?string $deviceId, string $reason): bool
    {
        return self::send(
            ['user_id' => $userId, 'platform' => $platform, 'device_id' => $deviceId],
            [
                'type'   => 'force_logout',
                'reason' => $reason,
                'time'   => time(),
            ]
        );
    }

    /**
     * 便捷：推送新版本更新（按用户+平台）
     */
    public static function pushUpdate(int $userId, string $platform, array $update): bool
    {
        return self::send(
            ['user_id' => $userId, 'platform' => $platform],
            [
                'type'   => 'update',
                'update' => $update,
            ]
        );
    }

    /**
     * 便捷：推送钱包余额更新（充值/扣减后实时同步到客户端）
     *
     * @param int   $userId 目标用户
     * @param array $wallet 最新余额：['paw' => int 熊爪, 'cny' => float 人民币]
     */
    public static function pushWallet(int $userId, array $wallet): bool
    {
        return self::send(
            ['user_id' => $userId],
            [
                'type'   => 'wallet',
                'wallet' => $wallet,
            ]
        );
    }

    /**
     * 便捷：向某平台的所有在线用户广播更新（用于新版本上线，推送全量用户）
     */
    public static function broadcastUpdate(string $platform, array $update): int
    {
        try {
            $userIds = Db::name('user_device')
                ->where('platform', $platform)
                ->where('status', 1)
                ->column('user_id');
            $userIds = array_unique(array_map('intval', $userIds));
        } catch (\Throwable $e) {
            return 0;
        }

        $count = 0;
        foreach ($userIds as $uid) {
            if (self::pushUpdate($uid, $platform, $update)) {
                $count++;
            }
        }
        return $count;
    }
}
