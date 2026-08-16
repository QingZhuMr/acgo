<?php
// 查询一个有效 device_key 供 WS 测试用
$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_NAME'] ?? 'acgo';
$user = $env['DB_USER'] ?? 'acgo';
$pass = $env['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $r = $pdo->query(
        'SELECT device_key, user_id, platform, device_id FROM user_device WHERE status = 1 ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $r = ['note' => 'no active device in user_device'];
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo 'ERR: ', $e->getMessage(), "\n";
}
