<?php
// 清理测试 push_queue 消息（argv: id）
$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? '3306') . ";dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'], $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$st = $pdo->prepare('DELETE FROM push_queue WHERE id = ?');
$st->execute([(int) ($argv[1] ?? 0)]);
echo 'ok', "\n";
