# -*- coding: utf-8 -*-
"""冒烟测试：启动 Swoole WS -> 检查端口存活 -> 停止"""
import os
import subprocess
import time
import urllib.request

CLI = r"D:\tools\swoole-cli\v6.2.0\swoole-cli-v6.2.0-cygwin-x64\bin\swoole-cli.exe"
WS_DIR = r"d:\webroot\acgo\ws"

# 1. 后台启动（Windows 上 swoole 不能 daemonize，这里用独立进程）
proc = subprocess.Popen(
    [CLI, "server.php", "start"],
    cwd=WS_DIR,
    stdout=subprocess.PIPE,
    stderr=subprocess.STDOUT,
    creationflags=subprocess.CREATE_NEW_PROCESS_GROUP,
)
print("started pid:", proc.pid)

# 2. 等待 3 秒后检查端口 2346
time.sleep(3)
ok = False
try:
    r = urllib.request.urlopen("http://127.0.0.1:2346/", timeout=3)
    ok = True
    print("http probe:", r.status)
except Exception as e:
    print("http probe fail:", e)

# 3. 检查进程是否还活着
alive = proc.poll() is None
print("process alive:", alive)

# 4. 检查 pid 文件
pidf = os.path.join(WS_DIR, "server.pid")
if os.path.exists(pidf):
    print("pid file:", open(pidf).read().strip())

# 5. 通过 status 命令确认
print("--- status ---")
s = subprocess.run([CLI, "server.php", "status"], cwd=WS_DIR, capture_output=True, text=True)
print(s.stdout, s.stderr)

# 6. 停止
print("--- stop ---")
s = subprocess.run([CLI, "server.php", "stop"], cwd=WS_DIR, capture_output=True, text=True)
print(s.stdout, s.stderr)

# 清理残留进程
if proc.poll() is None:
    proc.terminate()
    time.sleep(1)
if proc.poll() is None:
    proc.kill()
print("done")
