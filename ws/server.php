<?php
/**
 * ACGO 实时推送服务（Swoole WebSocket Server）
 *
 * 独立常驻进程，运行于 PHP CLI，不加载 ThinkPHP 框架。
 * 仅依赖 Swoole 扩展 + PDO，共享 acgo 数据库做设备认证与推送队列。
 *
 * 架构：
 *   客户端(Flutter/H5/移动端)  <--WebSocket 长连接(2346)-->  Swoole WebSocket Server
 *   admin/api 后台操作  --写 push_queue 表-->  Server 每秒轮询并推送
 *
 * 认证流程：
 *   1. 客户端建立 WebSocket 连接后，发送 {"type":"auth","device_key":"xxx"}
 *   2. 服务端查 user_device 表校验 device_key 有效且 status=1
 *   3. 校验通过后建立 user_id+platform+device_id -> fd 映射（Swoole\Table 共享，跨 worker 可用）
 *   4. 后台写入 push_queue，服务端定时器轮询后向匹配连接推送
 *
 * 运行命令（Linux / swoole-cli）：
 *   php server.php start        前台启动（开发）
 *   php server.php start -d     守护启动（生产）
 *   php server.php stop         停止
 *   php server.php restart      重启
 *   php server.php status       查看状态
 *
 * 说明：
 *   - Windows 本地开发用 swoole-cli（官方免安装二进制，内置 swoole + pdo_mysql）
 *   - Linux 生产用宝塔 PHP + swoole 扩展（php -m 需包含 swoole）
 *   - 协议与旧版 Workerman 完全一致，客户端无需任何改动
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

// ============================================================
// 命令行参数处理（start / stop / restart / status）
// ============================================================
$argv = $argv ?? [];
$command = strtolower((string) ($argv[1] ?? 'start'));
$daemon = in_array('-d', array_slice($argv, 2), true);
$pidFile = __DIR__ . '/server.pid';

/**
 * 停止服务：读取 pid 文件并杀掉进程
 */
function stopServer(string $pidFile): void
{
    if (!is_file($pidFile)) {
        echo "[ACGO-WS] 没有运行中的进程（无 pid 文件）\n";
        return;
    }
    $pid = (int) trim((string) file_get_contents($pidFile));
    if ($pid <= 0) {
        echo "[ACGO-WS] pid 文件无效\n";
        return;
    }
    $killed = false;
    if (function_exists('posix_kill')) {
        posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        // 等进程退出（最多 5 秒）
        for ($i = 0; $i < 50; $i++) {
            if (!posix_kill($pid, 0)) {
                $killed = true;
                break;
            }
            usleep(100000);
        }
    } elseif (PHP_OS_FAMILY === 'Windows') {
        // Windows：cygwin 版 swoole-cli 的 pid 可能与系统 PID 不一致，
        // 优先尝试 pid 文件里的 pid，失败则按监听端口兜底
        exec('taskkill /F /PID ' . $pid . ' 2>&1', $out, $code);
        if ($code !== 0) {
            exec('netstat -ano | findstr ":2346 LISTENING" 2>&1', $out);
            $winPid = '';
            foreach ($out as $line) {
                if (preg_match('/:2346\s+.*LISTENING\s+(\d+)/', $line, $m)) {
                    $winPid = $m[1];
                    break;
                }
            }
            if ($winPid !== '') {
                exec('taskkill /F /PID ' . $winPid . ' 2>&1', $out2, $code);
            }
        }
        $killed = ($code === 0);
    }
    if (is_file($pidFile)) {
        @unlink($pidFile);
    }
    echo $killed ? "[ACGO-WS] 已停止 (pid {$pid})\n" : "[ACGO-WS] 已发送停止信号 (pid {$pid})\n";
}

if ($command === 'stop') {
    stopServer($pidFile);
    exit(0);
}

if ($command === 'status') {
    if (!is_file($pidFile)) {
        echo "[ACGO-WS] 未运行\n";
        exit(1);
    }
    $pid = (int) trim((string) file_get_contents($pidFile));
    $alive = false;
    if (function_exists('posix_kill')) {
        $alive = posix_kill($pid, 0);
    } else {
        exec('tasklist /FI "PID eq ' . $pid . '" 2>&1', $out);
        $alive = in_array(true, array_map(fn ($l) => str_contains($l, (string) $pid), $out), true);
    }
    echo $alive ? "[ACGO-WS] 运行中 (pid {$pid})\n" : "[ACGO-WS] 未运行（pid 文件残留）\n";
    exit($alive ? 0 : 1);
}

if ($command === 'restart') {
    stopServer($pidFile);
    // 等 1 秒再拉起
    sleep(1);
    $command = 'start';
}

// start 需要 Swoole 扩展
if (!extension_loaded('swoole')) {
    fwrite(STDERR, "[ACGO-WS] 错误：未安装 swoole 扩展。\n");
    fwrite(STDERR, "  Linux 生产：宝塔面板 → PHP 设置 → 安装扩展 → swoole\n");
    fwrite(STDERR, "  Windows 本地：使用 swoole-cli（见 docs/后端搭建教程.md）\n");
    exit(1);
}

// ============================================================
// 全局状态（Swoole\Table：worker 间共享连接映射）
// ============================================================
$table = new Swoole\Table(65536);
$table->column('user_id', Swoole\Table::TYPE_INT, 4);
$table->column('platform', Swoole\Table::TYPE_STRING, 16);
$table->column('device_id', Swoole\Table::TYPE_STRING, 64);
$table->column('device_db_id', Swoole\Table::TYPE_INT, 4);
$table->column('authenticated', Swoole\Table::TYPE_INT, 1); // 0/1
$table->create();

// ============================================================
// 工具函数
// ============================================================

/**
 * 获取 PDO（惰性 + 断线自动重连）
 */
function db(): PDO
{
    global $config;
    static $pdo = null;

    $create = function () use ($config): PDO {
        $db = $config['db'];
        return new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset={$db['charset']}",
            $db['username'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ]
        );
    };

    try {
        if ($pdo === null) {
            $pdo = $create();
        }
        $pdo->query('SELECT 1'); // 探活
    } catch (Throwable $e) {
        $pdo = $create();
    }
    return $pdo;
}

/**
 * 发送 JSON 到客户端
 */
function sendJson(Swoole\WebSocket\Server $server, int $fd, array $payload): void
{
    if ($server->isEstablished($fd)) {
        $server->push($fd, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}

/**
 * 推送在线状态变化给 uid 的互相关注好友（presence 事件）。
 *
 * - online=1：认证成功时调用（有任一设备上线）
 * - online=0：最后一个设备离线时调用
 * 好友 = 互相关注（user_follow 双向存在）。
 */
function notifyFriendsPresence(Swoole\WebSocket\Server $server, Swoole\Table $table, int $uid, int $online): void
{
    if ($uid <= 0) {
        return;
    }
    try {
        $pdo = db();
        // 查 uid 互相关注的好友：我关注了他 且 他关注了我
        $myFollows = $pdo->prepare('SELECT following_id FROM user_follow WHERE follower_id = :uid');
        $myFollows->execute([':uid' => $uid]);
        $following = array_column($myFollows->fetchAll(), 'following_id');
        if (empty($following)) {
            return;
        }
        $in = implode(',', array_map('intval', $following));
        $st = $pdo->query("SELECT follower_id FROM user_follow WHERE following_id = {$uid} AND follower_id IN ({$in})");
        $friends = array_column($st->fetchAll(), 'follower_id');
        if (empty($friends)) {
            return;
        }
        $payload = ['type' => 'presence', 'user_id' => $uid, 'online' => $online];
        foreach ($table as $key => $row) {
            if ((int) ($row['authenticated'] ?? 0) !== 1) {
                continue;
            }
            if (in_array((int) $row['user_id'], $friends, true)) {
                $tFd = (int) $key;
                if ($server->isEstablished($tFd)) {
                    sendJson($server, $tFd, $payload);
                }
            }
        }
    } catch (Throwable $e) {
        // presence 推送失败不影响主流程
    }
}

/**
 * 判断 uid 是否仍有在线连接（任一设备在线）
 */
function hasOnlineConnections(Swoole\Table $table, int $uid): bool
{
    foreach ($table as $row) {
        if ((int) ($row['authenticated'] ?? 0) === 1 && (int) $row['user_id'] === $uid) {
            return true;
        }
    }
    return false;
}

/**
 * 连接认证（在 fd 对应的 worker 中执行）
 */
function doAuth(Swoole\WebSocket\Server $server, Swoole\Table $table, int $fd, string $deviceKey): void
{
    if ($deviceKey === '') {
        sendJson($server, $fd, ['type' => 'auth_result', 'ok' => false, 'msg' => '缺少设备密钥']);
        $server->close($fd);
        return;
    }

    try {
        $st = db()->prepare(
            'SELECT id, user_id, platform, device_id, status
             FROM user_device WHERE device_key = :k LIMIT 1'
        );
        $st->execute([':k' => $deviceKey]);
        $dev = $st->fetch();
    } catch (Throwable $e) {
        sendJson($server, $fd, ['type' => 'auth_result', 'ok' => false, 'msg' => '服务异常']);
        $server->close($fd);
        return;
    }

    if (!$dev || (int) $dev['status'] !== 1) {
        sendJson($server, $fd, ['type' => 'auth_result', 'ok' => false, 'msg' => '设备密钥无效或已下线']);
        $server->close($fd);
        return;
    }

    $table->set((string) $fd, [
        'user_id'      => (int) $dev['user_id'],
        'platform'     => (string) $dev['platform'],
        'device_id'    => (string) $dev['device_id'],
        'device_db_id' => (int) $dev['id'],
        'authenticated'=> 1,
    ]);

    // 认证成功：标记该设备在线，记录最后在线时间（供后台实时查看）
    try {
        db()->prepare('UPDATE user_device SET online_status = 1, last_online_time = :t, update_time = :t WHERE id = :id')
            ->execute([':t' => time(), ':id' => (int) $dev['id']]);
    } catch (Throwable $e) {
        // 在线状态更新失败不影响连接
    }

    sendJson($server, $fd, ['type' => 'auth_result', 'ok' => true, 'msg' => 'ok']);

    // 在线状态推送：该用户有任一设备上线 → 通知互相关注好友
    notifyFriendsPresence($server, $table, (int) $dev['user_id'], 1);
}

/**
 * 按条件向在线连接推送
 * @return int 命中的连接数
 */
function pushTo(Swoole\WebSocket\Server $server, Swoole\Table $table, array $filter, array $data): int
{
    $count = 0;
    foreach ($table as $key => $row) {
        if (!isset($row['authenticated']) || (int) $row['authenticated'] !== 1) {
            continue;
        }
        if (isset($filter['user_id']) && (int) $row['user_id'] !== (int) $filter['user_id']) {
            continue;
        }
        if (isset($filter['platform']) && (string) $row['platform'] !== (string) $filter['platform']) {
            continue;
        }
        if (isset($filter['device_id']) && (string) $row['device_id'] !== (string) $filter['device_id']) {
            continue;
        }
        $fd = (int) $key; // Table key 为 string，fd 需转回 int
        if ($server->isEstablished($fd)) {
            sendJson($server, $fd, $data);
            $count++;
        }
    }
    return $count;
}

/**
 * 轮询推送队列并执行推送（由定时器调用）
 */
function flushQueue(Swoole\WebSocket\Server $server, Swoole\Table $table): void
{
    try {
        $pdo = db();
        $rows = $pdo->query(
            'SELECT id, user_id, platform, device_id, payload
             FROM push_queue WHERE pushed = 0 ORDER BY id ASC LIMIT 100'
        )->fetchAll();
    } catch (Throwable $e) {
        return; // 数据库暂不可用，下轮重试
    }

    if (empty($rows)) {
        return;
    }

    $ids = [];
    foreach ($rows as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $ids[] = (int) $row['id'];
            continue;
        }
        // 构造过滤条件：
        //   - user_id = 0   => 广播给所有在线连接（不能当成"只发给 uid=0"，没有这种连接）
        //   - platform/device_id 为空 => 不按该维度过滤
        $filter = [];
        if ((int) $row['user_id'] > 0) {
            $filter['user_id'] = (int) $row['user_id'];
        }
        if ((string) $row['platform'] !== '') {
            $filter['platform'] = (string) $row['platform'];
        }
        if ((string) $row['device_id'] !== '') {
            $filter['device_id'] = (string) $row['device_id'];
        }
        $hit = pushTo($server, $table, $filter, $payload);
        // 送达日志（便于排查"后台已保存但客户端没收到"类问题）
        file_put_contents(
            __DIR__ . '/push.log',
            date('Y-m-d H:i:s') . " id={$row['id']} type=" . ($payload['type'] ?? '?')
            . " filter=" . json_encode($filter, JSON_UNESCAPED_UNICODE)
            . " delivered={$hit}\n",
            FILE_APPEND
        );
        if ($hit === 0) {
            // 用户当前不在线：通知类消息保留未推送状态，等用户上线后重推
            // （配对/评论/回复/关注等离线通知，登录后要能补收）。
            // force_logout / maintenance 是即时控制消息，离线无需重放，直接视为已推送。
            $type = (string) ($payload['type'] ?? '');
            if ($type === 'force_logout' || $type === 'maintenance') {
                $ids[] = (int) $row['id'];
            }
            continue;
        }
        $ids[] = (int) $row['id'];
    }

    // 标记已推送（按 id 分批更新，避免长 SQL）
    foreach (array_chunk($ids, 50) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        try {
            $pdo->exec("UPDATE push_queue SET pushed = 1 WHERE id IN ({$in})");
        } catch (Throwable $e) {
            // 忽略
        }
    }

    // 过期清理：删除 7 天前仍未推送的记录，防止长期离线用户堆积队列
    try {
        $pdo->exec('DELETE FROM push_queue WHERE pushed = 0 AND create_time < ' . (time() - 604800));
    } catch (Throwable $e) {
        // 忽略
    }
}

// ============================================================
// Swoole WebSocket Server
// ============================================================
// 启动前清理所有在线标记：服务重启后旧长连接已全部断开，
// 若此前 worker 崩溃/被强杀未触发 onClose，online_status 会残留为 1，
// 导致后台"在线"判断永久失真（本次重启前即出现过该问题）。
try {
    db()->prepare('UPDATE user_device SET online_status = 0, update_time = :t WHERE online_status = 1')
        ->execute([':t' => time()]);
} catch (Throwable $e) {
    // 数据库暂不可用不影响服务启动，定时器会在后续周期重试兜底清理
}

$server = new Swoole\WebSocket\Server($config['ws_host'], $config['ws_port']);

$server->set([
    'worker_num'            => (int) ($config['worker_num'] ?? 1),
    'daemonize'             => $daemon && PHP_OS_FAMILY !== 'Windows',
    'log_file'              => __DIR__ . '/swoole.log',
    'pid_file'              => $pidFile,
    'max_wait_time'         => 5,
    'heartbeat_idle_time'   => (int) ($config['heartbeat_idle'] ?? 90),   // 超过该秒数无数据则断开
    'heartbeat_check_interval' => 10,
    'enable_static_handler' => false,
]);

// ---------- 连接建立 ----------
// ---------- 定时清理（每 1 小时） ----------
// 聊天消息 30 天缓存清理：删除 expire_time 已过期的密文消息（服务器零知识，直接删）
Swoole\Timer::tick(3600 * 1000, function () {
    try {
        $pdo = db();
        $pdo->exec("DELETE FROM chat_message WHERE expire_time > 0 AND expire_time < " . time());
    } catch (Throwable $e) {
        // 清理失败静默，下次再试
    }
    // 聊天图片缓存 30 天清理：客户端收到图片即本地缓存，云端图仅作中转，
    // 与消息密文同生命周期（30 天），按文件 mtime 到期删除
    try {
        $chatDir = __DIR__ . '/../public/static/chat';
        if (is_dir($chatDir)) {
            $cutoff = time() - 30 * 86400;
            foreach (scandir($chatDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $fp = $chatDir . '/' . $f;
                if (is_file($fp) && filemtime($fp) < $cutoff) {
                    @unlink($fp);
                }
            }
        }
    } catch (Throwable $e) {
        // 清理失败静默
    }
});

$server->on('open', function (Swoole\WebSocket\Server $server, Swoole\Http\Request $request) use ($config, $table) {
    $fd = $request->fd;
    $table->set((string) $fd, [
        'user_id'      => 0,
        'platform'     => '',
        'device_id'    => '',
        'device_db_id' => 0,
        'authenticated'=> 0,
    ]);
    // 认证超时：连接建立后 N 秒内未完成认证则断开（一次性定时器）
    Swoole\Timer::after($config['auth_timeout'] * 1000, function () use ($server, $table, $fd) {
        $row = $table->get((string) $fd);
        if ($row && (int) ($row['authenticated'] ?? 0) !== 1) {
            sendJson($server, $fd, ['type' => 'auth_result', 'ok' => false, 'msg' => '认证超时']);
            $server->close($fd);
        }
    });
});

// ---------- 收到消息 ----------
$server->on('message', function (Swoole\WebSocket\Server $server, Swoole\WebSocket\Frame $frame) use ($table) {
    $fd = $frame->fd;
    $data = json_decode((string) $frame->data, true);
    if (!is_array($data)) {
        sendJson($server, $fd, ['type' => 'error', 'msg' => '无效消息']);
        return;
    }

    $type = (string) ($data['type'] ?? '');

    switch ($type) {
        case 'auth':
            doAuth($server, $table, $fd, (string) ($data['device_key'] ?? ''));
            break;

        case 'ping':
            sendJson($server, $fd, ['type' => 'pong']);
            break;

        case 'bye':
            $server->close($fd);
            break;

        // typing 输入状态：A 输入时转发给 B 的所有在线连接（不落库）
        case 'typing':
            $info = $table->get((string) $fd);
            $senderId = (int) ($info['user_id'] ?? 0);
            $receiverId = (int) ($data['receiver_id'] ?? 0);
            $chatId = (string) ($data['chat_id'] ?? '');
            if ($senderId > 0 && $receiverId > 0 && $chatId !== '') {
                $toFds = [];
                foreach ($table as $tFd => $tInfo) {
                    if ((int) $tInfo['user_id'] === $receiverId) {
                        $toFds[] = (string) $tFd;
                    }
                }
                $payload = [
                    'type'     => 'typing',
                    'typing'   => [
                        'chat_id'   => $chatId,
                        'sender_id' => $senderId,
                        'receiver_id' => $receiverId,
                    ],
                ];
                foreach ($toFds as $toFd) {
                    if ((string) $toFd === (string) $fd) {
                        continue; // 不回给发送者
                    }
                    sendJson($server, (int) $toFd, $payload);
                }
            }
            break;

        default:
            sendJson($server, $fd, ['type' => 'error', 'msg' => '未知消息类型']);
    }
});

// ---------- 连接关闭 ----------
$server->on('close', function (Swoole\WebSocket\Server $server, int $fd) use ($table) {
    $row = $table->get((string) $fd);
    if ($row) {
        $deviceDbId = (int) ($row['device_db_id'] ?? 0);
        $uid = (int) ($row['user_id'] ?? 0);
        // 连接关闭：标记该设备离线
        if ($deviceDbId > 0) {
            try {
                db()->prepare('UPDATE user_device SET online_status = 0, update_time = :t WHERE id = :id')
                    ->execute([':t' => time(), ':id' => $deviceDbId]);
            } catch (Throwable $e) {
                // 忽略
            }
        }
        $table->del((string) $fd);
        // 该用户所有设备都已离线 → 通知互相关注好友 online=0
        if ($uid > 0 && !hasOnlineConnections($table, $uid)) {
            notifyFriendsPresence($server, $table, $uid, 0);
        }
    }
});

// ---------- Worker 启动（定时器只注册一次） ----------
$server->on('workerStart', function (Swoole\WebSocket\Server $server, int $workerId) use ($config, $table) {
    // 只在 0 号 worker 上注册轮询定时器，避免多 worker 重复处理
    if ($workerId !== 0) {
        return;
    }

    // 每秒轮询推送队列
    Swoole\Timer::tick(1000, function () use ($server, $table) {
        flushQueue($server, $table);
    });

    // 周期性：清理失效连接 + 刷新在线设备时间（让后台"在线"状态保持实时）
    // 注意：本服务按单 worker（worker_num=1）设计。Swoole 的 isEstablished()/push()
    // 只能操作本 worker 的连接，多 worker 下跨 worker 连接会被误判失效，切勿调大 worker_num。
    Swoole\Timer::tick($config['heartbeat_interval'] * 1000, function () use ($server, $table) {
        $aliveIds = []; // 当前存活连接的 device_db_id 集合
        $deadUsers = []; // 连接失效被删除的用户（用于 presence 推送）
        foreach ($table as $key => $row) {
            $fd = (int) $key; // Table key 为 string
            $did = (int) ($row['device_db_id'] ?? 0);
            if ($did <= 0) {
                continue; // 未认证连接，由认证超时定时器处理
            }
            if (!$server->isEstablished($fd)) {
                // 连接失效：标记离线
                $deadUsers[(int) ($row['user_id'] ?? 0)] = true;
                try {
                    db()->prepare('UPDATE user_device SET online_status = 0, update_time = :t WHERE id = :id')
                        ->execute([':t' => time(), ':id' => $did]);
                } catch (Throwable $e) {
                    // 忽略
                }
                $table->del((string) $fd);
                continue;
            }
            $aliveIds[] = $did;
            // 连接存活：刷新最后在线时间（后台据此判断仍在线）
            try {
                db()->prepare('UPDATE user_device SET last_online_time = :t WHERE id = :id')
                    ->execute([':t' => time(), ':id' => $did]);
            } catch (Throwable $e) {
                // 忽略
            }
        }
        // 兜底清理：DB 中标记在线但连接表里已不存在的设备。
        // 服务重启 / worker 崩溃 / 进程被强杀等场景不会触发 onClose，
        // 若不清理 online_status 会残留为 1，后台"在线"状态将永久失真。
        try {
            $now = time();
            if ($aliveIds) {
                $in = implode(',', array_map('intval', array_unique($aliveIds)));
                db()->exec("UPDATE user_device SET online_status = 0, update_time = {$now} WHERE online_status = 1 AND id NOT IN ({$in})");
            } else {
                db()->exec("UPDATE user_device SET online_status = 0, update_time = {$now} WHERE online_status = 1");
            }
        } catch (Throwable $e) {
            // 忽略
        }
        // 连接失效的用户若已无任何在线连接 → 通知好友 offline
        foreach (array_keys($deadUsers) as $deadUid) {
            if ($deadUid > 0 && !hasOnlineConnections($table, $deadUid)) {
                notifyFriendsPresence($server, $table, $deadUid, 0);
            }
        }
    });
});

// ============================================================
// 启动
// ============================================================
echo "[ACGO-WS] Swoole WebSocket 服务启动中...\n";
echo "[ACGO-WS] 监听 {$config['ws_host']}:{$config['ws_port']} (worker_num=" . ($config['worker_num'] ?? 1) . ")\n";
$server->start();
