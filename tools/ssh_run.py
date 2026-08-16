# -*- coding: utf-8 -*-
"""临时 SSH 辅助脚本：连接服务器执行命令（用完即删）"""
import sys
import paramiko

HOST = "43.161.217.66"
PORT = 22
USER = "root"
PASS = "Zheng199842"

cmd = sys.argv[1] if len(sys.argv) > 1 else "echo ok"

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, port=PORT, username=USER, password=PASS, timeout=15)
stdin, stdout, stderr = client.exec_command(cmd, timeout=60)
out = stdout.read().decode("utf-8", errors="replace")
err = stderr.read().decode("utf-8", errors="replace")
print("=== STDOUT ===")
print(out)
if err.strip():
    print("=== STDERR ===")
    print(err)
print("=== EXIT:", stdout.channel.recv_exit_status(), "===")
client.close()
