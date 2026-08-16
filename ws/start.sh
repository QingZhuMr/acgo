#!/bin/bash
# ACGO 实时推送服务启动脚本（Linux 生产）
# 与 Web 端同一套 PHP（宝塔 PHP 8.3），指向 ws/server.php
# 用法：./start.sh {start|stop|restart|status}
#
# 若服务器 PHP 版本不同，改下面的 PHP_BIN 路径（如 82/84）：
#   宝塔面板 → 软件商店 → PHP 设置 → 安装扩展 → swoole（必须装）
#   CLI 路径一般为 /www/server/php/<版本>/bin/php

PHP_BIN=/www/server/php/83/bin/php
WS_DIR=/www/wwwroot/acgo/ws
LOG_DIR=$WS_DIR/logs

mkdir -p "$LOG_DIR"
cd "$WS_DIR"

# 检查 swoole 扩展是否可用
if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^swoole$'; then
  echo "[ACGO-WS] 错误：PHP 未安装 swoole 扩展。"
  echo "          宝塔面板 → PHP 8.3 → 安装扩展 → swoole"
  exit 1
fi

case "$1" in
  start)
    # -d 为 Swoole 守护模式（daemonize），pid 写入 $WS_DIR/server.pid
    nohup "$PHP_BIN" server.php start -d >> "$LOG_DIR/ws.log" 2>&1 &
    echo "ACGO-WS started (pid file: $WS_DIR/server.pid, log: $LOG_DIR/ws.log)"
    ;;
  stop)
    "$PHP_BIN" server.php stop
    ;;
  restart)
    "$PHP_BIN" server.php restart
    ;;
  status)
    "$PHP_BIN" server.php status
    ;;
  *)
    echo "Usage: $0 {start|stop|restart|status}"
    exit 1
    ;;
esac
