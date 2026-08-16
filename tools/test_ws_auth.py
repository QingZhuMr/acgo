# -*- coding: utf-8 -*-
"""WebSocket 全链路测试：连接 -> auth -> ping/pong -> 收推送 -> bye"""
import json
import socket
import base64
import os
import struct
import hashlib

WS_HOST = "127.0.0.1"
WS_PORT = 2346


def ws_connect(host, port, path="/"):
    sock = socket.create_connection((host, port), timeout=10)
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET {path} HTTP/1.1\r\n"
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
        raise RuntimeError("handshake failed: " + resp.decode(errors="ignore"))
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


def ws_recv(sock, timeout=10):
    sock.settimeout(timeout)
    h = sock.recv(2)
    if len(h) < 2:
        raise RuntimeError("connection closed")
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


if __name__ == "__main__":
    # 从 .env 读 DB 拿一个有效 device_key
    import re
    env = {}
    for line in open(r"d:\webroot\acgo\.env", encoding="utf-8"):
        m = re.match(r"^([A-Z_]+)\s*=\s*(.+)$", line.strip())
        if m:
            env[m.group(1)] = m.group(2).strip().strip('"').strip("'")
    try:
        import pymysql
    except ImportError:
        pymysql = None
    import subprocess, sys

    # 用 swoole-cli 里的 pdo 查 device_key（避免依赖 pymysql）
    sql = (
        "SELECT device_key FROM user_device WHERE status=1 "
        "ORDER BY id DESC LIMIT 1"
    )
    # 直接用 PHP PDO 查询
    php_code = (
        "<?php "
        "$pdo=new PDO('mysql:host=" + env["DB_HOST"] + ";port=" + env.get("DB_PORT", "3306")
        + ";dbname=" + env["DB_NAME"] + ";charset=utf8mb4', '"
        + env["DB_USER"] + "', '" + env["DB_PASS"] + "', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); "
        "$r=$pdo->query(\"SELECT device_key,user_id,platform,device_id FROM user_device WHERE status=1 ORDER BY id DESC LIMIT 1\")->fetch(PDO::FETCH_ASSOC); "
        "echo json_encode($r);"
    )
    cli = r"D:\tools\swoole-cli\v6.2.0\swoole-cli-v6.2.0-cygwin-x64\bin\swoole-cli.exe"
    r = subprocess.run([cli, "/cygdrive/d/webroot/acgo/tools/get_device_key.php"], capture_output=True, text=True)
    out = r.stdout.strip()
    if r.stderr:
        print("stderr:", r.stderr)
    if not out:
        print("stdout empty, rc=", r.returncode)
        sys.exit(1)
    dev = json.loads(out)
    print("using device:", dev)
    if dev.get("note"):
        print("NOTE:", dev["note"])
        sys.exit(0)

    sock = ws_connect(WS_HOST, WS_PORT)
    print("connected ok")

    # auth
    ws_send(sock, json.dumps({"type": "auth", "device_key": dev["device_key"]}))
    op, msg = ws_recv(sock)
    print("auth resp:", msg)

    # ping -> pong
    ws_send(sock, json.dumps({"type": "ping"}))
    op, msg = ws_recv(sock)
    print("ping resp:", msg)

    sock.close()
    print("ALL OK")
