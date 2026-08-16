# -*- coding: utf-8 -*-
"""临时 SSH 辅助脚本 v2：从本地文件读取命令执行（避免 PowerShell 转义问题）"""
import sys
import paramiko

HOST = "43.161.217.66"
PORT = 22
USER = "root"
PASS = "Zheng199842"

cmd_file = sys.argv[1] if len(sys.argv) > 1 else ""

with open(cmd_file, "r", encoding="utf-8") as f:
    cmd = f.read()

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, port=PORT, username=USER, password=PASS, timeout=15)
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
out = stdout.read().decode("utf-8", errors="replace")
err = stderr.read().decode("utf-8", errors="replace")
print("=== STDOUT ===")
print(out)
if err.strip():
    print("=== STDERR ===")
    print(err)
print("=== EXIT:", stdout.channel.recv_exit_status(), "===")
client.close()
