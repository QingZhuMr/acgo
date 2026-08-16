#!/bin/bash
# ACGO 服务器部署脚本：从 GitHub 拉取最新代码
# 用法：bash ws/deploy.sh
# 前提：服务器已配置 acgo_deploy 密钥（~/.ssh/config）访问 github.com

set -e
cd /www/wwwroot/acgo

echo "[deploy] 拉取最新代码..."
git fetch origin
git reset --hard origin/master

echo "[deploy] 清理 ThinkPHP 运行时缓存..."
rm -rf runtime/* 2>/dev/null || true

echo "[deploy] 检查 WS 服务状态..."
if ! pgrep -f "server.php start" > /dev/null 2>&1; then
    echo "[deploy] WS 未运行，启动中..."
    bash /www/wwwroot/acgo/ws/start.sh start
else
    echo "[deploy] WS 运行中（代码更新不影响，如需重启：bash ws/start.sh restart）"
fi

echo "[deploy] 完成"
