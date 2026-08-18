<?php
declare (strict_types = 1);

namespace app\common;

use think\facade\Db;

/**
 * 管理员操作日志统一管理
 * 集中定义常用行为映射，并提供统一的写日志方法，
 * 确保各操作写入的 action 标准一致，日志显示为中文。
 */
class AdminLog
{
    /**
     * 常用操作行为映射（action => 中文名）
     * 写日志时 action 必须使用此表中的键，保证统一。
     */
    public const ACTION_MAP = [
        // 账号登录相关
        'login'            => '登录',
        'logout'           => '登出',
        'kick'             => '强制下线',

        // 管理员管理
        'admin_create'     => '新增管理员',
        'admin_edit'       => '编辑管理员',
        'admin_delete'     => '删除管理员',
        'admin_reset_pwd'  => '重置管理员密码',
        'admin_ban'        => '禁用管理员',
        'admin_unban'      => '启用管理员',

        // 用户管理
        'user_create'      => '创建用户',
        'user_edit'        => '编辑用户',
        'user_ban'         => '封禁用户',
        'user_unban'       => '解封用户',
        'user_delete'      => '注销用户',

        // 资金操作
        'recharge'         => '充值',
        'withdraw'         => '提现',

        // 广场（帖子）运营
        'post_delete'      => '删除帖子',
        'post_heat'        => '帖子加热',
        'report_handle'    => '处理举报',

        // 版本管理
        'upload'           => '上传安装包',

        // 系统
        'system_setting'   => '系统设置',

        // 日志管理
        'admin_log_clear'  => '清空管理员日志',
        'user_log_clear'   => '清空用户日志',
    ];

    /**
     * 写入管理员操作日志
     * @param int    $adminId  管理员ID
     * @param string $adminName 管理员账号
     * @param string $action    行为（必须是 ACTION_MAP 中的键）
     * @param string $ip        操作IP
     * @param string $remark    备注
     */
    public static function write(int $adminId, string $adminName, string $action, string $ip = '', string $remark = ''): void
    {
        if (!array_key_exists($action, self::ACTION_MAP)) {
            // 未预定义的行为也记录，但保留原值（前端会原样显示）
            $action = $action ?: 'unknown';
        }
        Db::name('admin_log')->insert([
            'admin_id'    => $adminId,
            'username'    => $adminName,
            'action'      => $action,
            'login_ip'    => $ip,
            'user_agent'  => mb_substr($remark, 0, 490),
            'create_time' => time(),
        ]);
    }

    /**
     * 将行为值转为中文（用于列表展示）
     */
    public static function text(string $action): string
    {
        return self::ACTION_MAP[$action] ?? $action;
    }
}
