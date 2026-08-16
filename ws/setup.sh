#!/bin/bash
# ============================================================
# ACGO WS 一键部署脚本（Linux 生产）
# 自动完成：环境检查 → 启动 WS 服务 → 设置计划任务守护 + 开机自启
#
# 用法（在服务器 /www/wwwroot/acgo/ws 下执行）：
#   chmod +x setup.sh
#   sudo ./setup.sh
#
# 前置要求：.env 已配置好（cp .env.production .env 并填写【必改】项）
# ============================================================

set -u

# 脚本所在目录（ws/）与项目根目录
WS_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT_DIR=$(cd "$WS_DIR/.." && pwd)
# 宝塔 PHP 8.3 CLI 路径，版本不同请改这里
PHP_BIN=/www/server/php/83/bin/php
LOG_DIR=$WS_DIR/logs
PGGREP=$(command -v pgrep || echo /usr/bin/pgrep)

echo "=============================================="
echo " ACGO WS 一键部署"
echo "  项目目录 : $ROOT_DIR"
echo "  PHP      : $PHP_BIN"
echo "=============================================="

# 0. PHP 路径存在？
if [ ! -x "$PHP_BIN" ]; then
  echo "[ERROR] 未找到 $PHP_BIN，请把本脚本里的 PHP_BIN 改成实际 PHP 8.x CLI 路径"
  exit 1
fi

# 1. 检查 .env 是否存在（ws 与 Web 共用根目录 .env）
if [ ! -f "$ROOT_DIR/.env" ]; then
  echo "[ERROR] 未找到 $ROOT_DIR/.env"
  echo "       请先：cp $ROOT_DIR/.env.production $ROOT_DIR/.env"
  echo "       并修改 .env 中标注【必改】的项（数据库 / 域名）"
  exit 1
else
  echo "[OK] .env 已存在"
fi

# 2. 检查 swoole 扩展
if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^swoole$'; then
  echo "[ERROR] PHP 未安装 swoole 扩展"
  echo "       宝塔面板 → PHP 8.3 → 安装扩展 → swoole"
  exit 1
fi
echo "[OK] swoole 扩展已安装"

# 3. 权限
chmod +x "$WS_DIR/start.sh"

# 4. 启动 WS 服务
echo "[..] 启动 WS 服务 ..."
"$WS_DIR/start.sh" start
sleep 2

# 5. 验证是否已运行
if "$PGGREP" -f "server.php start" >/dev/null 2>&1; then
  echo "[OK] WS 服务已运行："
  "$WS_DIR/start.sh" status
else
  echo "[WARN] WS 服务未运行，日志如下（$LOG_DIR/ws.log）："
  tail -n 20 "$LOG_DIR/ws.log" 2>/dev/null
fi

# 6. 设置计划任务守护 + 开机自启（等价宝塔「计划任务」）
CRON_DAEMON="* * * * * $PGGREP -f \"server.php start\" >/dev/null 2>&1 || /bin/bash $WS_DIR/start.sh start >>$LOG_DIR/ws.log 2>&1"
CRON_BOOT="@reboot /bin/bash $WS_DIR/start.sh start >>$LOG_DIR/ws.log 2>&1"

if crontab -l 2>/dev/null | grep -q "server.php start"; then
  echo "[OK] 计划任务已存在，跳过（如需调整可 crontab -e 手动编辑）"
else
  {
    crontab -l 2>/dev/null
    echo "# ACGO WS auto-start (managed by setup.sh)"
    echo "$CRON_DAEMON"
    echo "$CRON_BOOT"
  } | crontab -
  echo "[OK] 计划任务已设置：每分钟自检拉起 + @reboot 开机自启"
  echo "     等价宝塔「计划任务」→ 周期「每分钟」+「开机」"
fi

echo ""
echo "=============================================="
echo " 完成！后续常用命令："
echo "   ws/start.sh status    查看运行状态"
echo "   ws/start.sh stop      停止服务"
echo "   ws/start.sh restart   重启服务"
echo "   crontab -l            查看已设置的计划任务"
echo "=============================================="
