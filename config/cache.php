<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // Redis 缓存（用户昵称/头像、表情包列表、客户端版本，见 app/common/ 下对应 Cache 类）
        // 参数读 .env（REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB / REDIS_TIMEOUT）。
        // 未安装 phpredis 扩展或未启动 Redis 时，Redis 驱动会抛异常，由各 Cache 类
        // 捕获后自动降级直查库，业务不受影响（仅失去缓存红利）。
        'redis' => [
            // 驱动方式
            'type'      => 'Redis',
            // 服务器地址
            'host'      => env('REDIS_HOST', '127.0.0.1'),
            // 端口
            'port'      => env('REDIS_PORT', 6379),
            // 密码（无密码留空）
            'password'  => env('REDIS_PASSWORD', ''),
            // 选择的库
            'select'    => env('REDIS_DB', 0),
            // 超时时间（秒）
            'timeout'   => env('REDIS_TIMEOUT', 1.0),
            // 有效期（秒）0表示永久缓存
            'expire'    => 0,
            // 缓存前缀（各 Cache 类自带完整业务前缀，如 acgo:user_info: / acgo:sticker: / acgo:update:）
            'prefix'    => '',
            // 缓存标签前缀
            'tag_prefix'=> 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize' => ['serialize', 'unserialize'],
        ],
    ],
];
