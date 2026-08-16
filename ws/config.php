<?php
/**
 * ACGO 实时推送服务配置（从主项目 .env 读取）
 *
 * 说明：
 *  - 本服务为独立常驻进程，运行于 PHP CLI（不加载 ThinkPHP 框架），
 *    因此自行解析项目根目录的 .env，避免重复维护两份配置。
 *  - 数据库复用 .env 的 DB_*；监听地址与协议使用新增的 WS_* / APP_ENV。
 *
 * WSS 生产方案（推荐）：Swoole 内部始终用 ws:// 监听（生产绑定 127.0.0.1 仅内网），
 * 由 Nginx 反向代理对外提供 wss://（SSL 证书归 Nginx 管理，宝塔可一键申请/部署）。
 * 因此本服务不配置证书；证书在多域名各站点的 Nginx 配置里，指向同一 WS 端口即可。
 */

declare(strict_types=1);

/**
 * 解析 .env 文件为关联数组（兼容 "KEY = VALUE"、#注释、空行）
 * @param string $filePath .env 绝对路径
 * @return array<string,string>
 */
function loadEnvFile(string $filePath): array
{
    $result = [];
    if (!is_file($filePath)) {
        return $result;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $result;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        if (strlen($value) >= 2) {
            if (($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $result[$key] = $value;
    }
    return $result;
}

$env = loadEnvFile(dirname(__DIR__) . '/.env');

// 运行环境：true=开发(false 生产)。决定监听地址与对外协议
$isDev = in_array(strtolower((string) ($env['APP_ENV'] ?? 'true')), ['1', 'true', 'yes', 'on'], true);

return [
    // ===== 运行环境 =====
    'is_dev'          => $isDev,

    // ===== 监听地址 =====
    // 协议始终为 ws（Swoole 内部）；生产 WSS 由 Nginx 反向代理提供
    'ws_scheme'       => 'ws',                                   // Swoole 内部固定 ws
    // 对外协议（domain 接口返回给客户端）：开发 ws，生产 wss
    'public_scheme'   => $isDev ? 'ws' : 'wss',
    // 监听地址：开发 0.0.0.0 直接对外；生产 127.0.0.1 仅内网给 Nginx 反向代理
    'ws_host'         => (string) ($env['WS_HOST'] ?? ($isDev ? '0.0.0.0' : '127.0.0.1')),
    'ws_port'         => (int) ($env['WS_PORT'] ?? 2346),

    // ===== 数据库（复用 .env 的 DB_*）=====
    'db' => [
        'host'     => (string) ($env['DB_HOST'] ?? '127.0.0.1'),
        'port'     => (int) ($env['DB_PORT'] ?? 3306),
        'database' => (string) ($env['DB_NAME'] ?? 'acgo'),
        'username' => (string) ($env['DB_USER'] ?? 'acgo'),
        'password' => (string) ($env['DB_PASS'] ?? ''),
        'charset'  => (string) ($env['DB_CHARSET'] ?? 'utf8mb4'),
    ],

    // ===== 连接参数 =====
    'auth_timeout'       => (int) ($env['WS_AUTH_TIMEOUT'] ?? 10),   // 连接后必须在多少秒内完成认证，否则断开
    'heartbeat_interval' => (int) ($env['WS_HEARTBEAT_INTERVAL'] ?? 30), // 周期性清理失效连接（秒）
    'heartbeat_idle'     => (int) ($env['WS_HEARTBEAT_IDLE'] ?? 90), // Swoole 内置心跳：超过该秒数无数据则断开（客户端 25s 发一次 ping）

    // ===== 进程数 =====
    // 重要：本服务必须保持单进程（worker_num=1）！
    // Swoole\Table 虽跨 worker 共享，但 isEstablished()/push() 等连接操作
    // 只能作用于当前 worker 的连接；多进程下 worker 0 的心跳定时器会把
    // 其他 worker 上的活连接误判为失效、推送也全部失败（delivered=0），
    // 导致后台"在线"状态失真。切勿调大 WS_WORKER_NUM。
    'worker_num'         => (int) ($env['WS_WORKER_NUM'] ?? (PHP_OS_FAMILY === 'Windows' ? 1 : 1)),
];
