# -*- coding: utf-8 -*-
"""推送链路测试：连接+认证 -> 写一条 push_queue -> 收推送消息 -> 清理"""
import json
import socket
import base64
import os
import struct
import subprocess
import sys
import threading

WS_HOST = "127.0.0.1"
WS_PORT = 2346
CLI = r"D:\tools\swoole-cli\v6.2.0\swoole-cli-v6.2.0-cygwin-x64\bin\swoole-cli.exe"


def ws_connect(host, port):
    sock = socket.create_connection((host, port), timeout=10)
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET / HTTP/1.1\r\n"
        f"Host: {host}:{port}\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        "Sec-WebSocket-Version: 13\r\n\r\n"
    )
    sock.sendall(req.encode())
    resp = b""
    while b"\r\n\r\n" not in resp:
        resp += sock.recv(4096)
    if b"101" not in resp.split(b"\r\n", 1)[0]:
        raise RuntimeError("handshake failed")
    return sock


def ws_send(sock, text):
    data = text.encode()
    if len(data) < 126:
        header = bytes([0x81, 0x80 | len(data)])
    elif len(data) < 65536:
        header = bytes([0x81, 0x80 | 126]) + struct.pack(">H", len(data))
    else:
        header = bytes([0x81, 0x80 | 127]) + struct.pack(">Q", len(data))
    mask = os.urandom(4)
    masked = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
    sock.sendall(header + mask + masked)


def ws_recv(sock, timeout=15):
    sock.settimeout(timeout)
    try:
        h = sock.recv(2)
    except socket.timeout:
        return None, None
    if len(h) < 2:
        return None, None
    opcode = h[0] & 0x0F
    ln = h[1] & 0x7F
    if ln == 126:
        ln = struct.unpack(">H", sock.recv(2))[0]
    elif ln == 127:
        ln = struct.unpack(">Q", sock.recv(8))[0]
    data = b""
    while len(data) < ln:
        chunk = sock.recv(ln - len(data))
        if not chunk:
            break
        data += chunk
    return opcode, data.decode(errors="ignore")


def php(code_file, *args):
    r = subprocess.run([CLI, "/cygdrive/d/webroot/acgo/tools/" + code_file, *args],
                       capture_output=True, text=True)
    return r.stdout.strip()


if __name__ == "__main__":
    dev = json.loads(php("get_device_key.php"))
    print("device:", dev["device_key"])

    sock = ws_connect(WS_HOST, WS_PORT)
    ws_send(sock, json.dumps({"type": "auth", "device_key": dev["device_key"]}))
    print("auth:", ws_recv(sock))

    # 写一条 push_queue 测试消息（payload 用测试标记）
    payload = json.dumps({"type": "test_push", "ts": 1}, ensure_ascii=False)
    pid = php("push_test_msg.php", str(dev["user_id"]), str(dev["platform"]), str(dev["device_id"]), payload)
    print("inserted push_queue id:", pid)

    # 等待服务轮询并接收推送
    got = None
    for _ in range(6):
        op, msg = ws_recv(sock, timeout=3)
        print("recv:", msg)
        if msg and "test_push" in msg:
            got = msg
            break
        if not msg:
            break

    # 清理测试数据
    if got:
        php("cleanup_test_msg.php", str(pid))
        print("cleaned push_queue id:", pid)
    else:
        print("WARN: did not receive push within window")

    sock.close()
    print("RESULT:", "PUSH_OK" if got else "PUSH_FAIL")
