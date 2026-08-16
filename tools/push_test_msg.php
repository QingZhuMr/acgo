<?php
// 写入一条 push_queue 测试消息（argv: user_id platform device_id payload_json）
$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? '3306') . ";dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'], $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$st = $pdo->prepare('INSERT INTO push_queue (user_id, platform, device_id, payload, pushed, create_time) VALUES (?, ?, ?, ?, 0, ?)');
$st->execute([
    (int) ($argv[1] ?? 0),
    (string) ($argv[2] ?? ''),
    (string) ($argv[3] ?? ''),
    (string) ($argv[4] ?? '{}'),
    time(),
]);
echo $pdo->lastInsertId(), "\n";
