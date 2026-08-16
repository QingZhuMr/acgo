@echo off
rem ============================================
rem ACGO 实时推送服务 - 停止（Windows）
rem Windows 下 swoole-cli 的 pid 文件与系统 PID 不一致，
rem 直接用 taskkill 按进程名停止（本地单服务，安全可靠）
rem ============================================
echo Stopping ACGO WS service...
taskkill /F /IM swoole-cli.exe >nul 2>&1
if errorlevel 1 (
    echo No swoole-cli process found. (Maybe already stopped)
) else (
    echo ACGO WS service stopped.
)
pause
