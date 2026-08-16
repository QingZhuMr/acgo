@echo off
rem ============================================
rem ACGO 实时推送服务 - 启动（Windows）
rem 使用 swoole-cli（官方免安装二进制，内置 swoole + pdo_mysql）
rem 修改路径请确认 swoole-cli 解压后的 bin 目录
rem ============================================
set SWOOLE_CLI=D:\tools\swoole-cli\v6.2.0\swoole-cli-v6.2.0-cygwin-x64\bin\swoole-cli.exe
set WORK_DIR=%~dp0

cd /d "%WORK_DIR%"
echo Starting ACGO WS service (swoole-cli)...
"%SWOOLE_CLI%" server.php start
if errorlevel 1 (
    echo Failed to start. See swoole.log
)
pause
