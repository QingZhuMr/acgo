<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name
    'name'           => 'PHPSESSID',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 过期时间（后台会话兜底 6 小时；每次操作由 CheckLogin 中间件续期 1 小时）
    'expire'         => 21600,
    // 前缀
    'prefix'         => '',
    // 固定 session 存储路径（绝对路径），避免 multi-app 切换 runtime 目录导致读写路径不一致
    'path'           => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR,
];
