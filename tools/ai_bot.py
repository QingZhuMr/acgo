# -*- coding: utf-8 -*-
"""
ACGO AI 机器人本地自动化脚本（走 AI 专用接口，免签名）

功能：
  1. create      批量建号（每个账号自动绑定随机国内 IP）+ 可选上传动漫头像
  2. publish     批量发帖（支持本地图片 + 随机延迟模拟真人）
  3. comment     批量回帖/评论（可楼中楼）
  4. interact    批量点赞/收藏/关注
  5. accounts    查询账号信息（含绑定 IP）
  6. rebind-ip   重新绑定账号 IP（可指定省份）

AI 接口鉴权：请求头 X-AI-Token（对应 .env 的 AI_TOKEN），免 HMAC 签名/设备密钥。
接口路径不带 /api/ 前缀，如 https://api.yeahbear.cn/ai/batchCreate

依赖：Python 3 + requests（pip install requests）

用法示例（Windows）：
  python ai_bot.py create --prefix 二次元酱 --count 10 --password 123456 --save accounts.json --avatar lorelei
  python ai_bot.py publish --accounts accounts.json --content-file posts.txt --max 20 --delay 3-10
  python ai_bot.py comment --accounts accounts.json --post-id 3 --content-file comments.txt --max 10 --delay 3-8
  python ai_bot.py interact --accounts accounts.json --post-id 3 --like --favorite --follow 5 --delay 3-8
  python ai_bot.py accounts --uid 1,2,3
  python ai_bot.py rebind-ip --uid 1 --province 广东
"""
import argparse
import json
import os
import random
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests

# 默认从环境变量取；未设置则尝试读取 ../.env 的 AI_TOKEN（脚本位于 tools/ 下）
_API_TOKEN_DEFAULT = 'acgo_ai_2026_x9Kq'


def _env_token():
    v = os.environ.get('AI_TOKEN')
    if v:
        return v
    env_file = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '.env')
    if os.path.isfile(env_file):
        with open(env_file, encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line.startswith('AI_TOKEN'):
                    return line.split('=', 1)[1].strip().strip('"').strip("'")
    return _API_TOKEN_DEFAULT


API_BASE = os.environ.get('ACGO_API_BASE', 'https://api.yeahbear.cn')
AI_TOKEN = _env_token()

AVATAR_STYLE = 'lorelei'   # dicebear 风格：lorelei/pixel-art/avataaars/bottts/thumbs
AVATAR_SIZE = 256
TIMEOUT = 20

# 内置默认帖子素材（无文件时使用；格式：[标题\n正文...]，随机拼接）
DEFAULT_POSTS = [
    '分享一下今天的快乐小事',
    '记录一下最近的好心情',
    '随手拍一张窗外的风景，感觉生活很美好',
    '周末在家做了一顿好吃的，厨艺见长',
    '今天天气不错，出去走了一圈，心情都变好了',
    '推荐一部最近在看的剧，真的很好看',
    '分享一个省钱小技巧，亲测有效',
    '今天的咖啡格外好喝，开启元气满满的一天',
    '路过一家超可爱的小店，忍不住拍了照',
    '坚持运动一个月了，感觉状态好了很多',
]

# 内置默认评论素材（无文件时使用）
DEFAULT_COMMENTS = [
    '太棒了，支持！',
    '哈哈哈哈好真实',
    '学到了，谢谢分享',
    '同感同感，我也是这么觉得的',
    '这个可以收藏一下，以后用得上',
    '拍得真好看！',
    '不错不错，继续加油',
    '来了来了，给博主点赞',
    '哈哈原来不止我一个人这样',
    '好喜欢这条，点个赞',
]

# 省份列表（rebind-ip 可指定；不传随机）
PROVINCES = ['北京', '上海', '广东', '浙江', '江苏', '山东', '河南', '湖北', '湖南',
             '四川', '福建', '安徽', '河北', '陕西', '广西', '江西', '辽宁', '重庆',
             '天津', '山西', '吉林', '黑龙江', '贵州', '云南', '甘肃', '内蒙古',
             '新疆', '海南', '宁夏', '青海', '西藏']


class AiApi:
    """AI 接口客户端（免签名，仅 X-AI-Token）"""

    def __init__(self, base=API_BASE, token=AI_TOKEN):
        self.base = base.rstrip('/')
        self.token = token
        self.sess = requests.Session()
        self.sess.headers.update({'X-AI-Token': self.token})

    def _url(self, path):
        return f'{self.base}/ai/{path.lstrip("/")}'

    def _req(self, method, path, **kw):
        kw.setdefault('timeout', TIMEOUT)
        r = self.sess.request(method, self._url(path), **kw)
        try:
            j = r.json()
        except ValueError:
            raise RuntimeError(f'HTTP {r.status_code}: {r.text[:200]}')
        if j.get('code') == 401:
            raise RuntimeError(f'AI token 无效（401）：请检查 .env 的 AI_TOKEN')
        return j

    def get(self, path, params=None):
        return self._req('GET', path, params=params)

    def post(self, path, data=None, files=None):
        return self._req('POST', path, data=data, files=files)

    # ---- 具体接口 ----
    def batch_create(self, prefix, count, password):
        return self.post('batchCreate', data={'prefix': prefix, 'count': count, 'password': password})

    def upload_avatar(self, uid, img_bytes):
        return self.post('uploadAvatar', data={'uid': uid},
                         files={'avatar': ('avatar.png', img_bytes, 'image/png')})

    def publish(self, uid, content, images=None):
        data = {'uid': uid, 'content': content}
        files = None
        if images:
            files = [('images[]', (os.path.basename(p), open(p, 'rb'), 'image/jpeg')) for p in images]
        return self.post('publish', data=data, files=files)

    def comment(self, uid, post_id, content, parent_id=0, reply_to_uid=0, notify=0):
        return self.post('comment', data={
            'uid': uid, 'post_id': post_id, 'content': content,
            'parent_id': parent_id, 'reply_to_uid': reply_to_uid, 'notify': notify,
        })

    def like(self, uid, post_id, notify=0):
        return self.post('like', data={'uid': uid, 'post_id': post_id, 'notify': notify})

    def favorite(self, uid, post_id, notify=0):
        return self.post('favorite', data={'uid': uid, 'post_id': post_id, 'notify': notify})

    def comment_like(self, uid, comment_id):
        return self.post('commentLike', data={'uid': uid, 'comment_id': comment_id})

    def follow(self, uid, target_uid, notify=0):
        return self.post('follow', data={'uid': uid, 'target_uid': target_uid, 'notify': notify})

    def accounts(self, uid=None, phone=None):
        params = {}
        if uid:
            params['uid'] = uid if isinstance(uid, str) else ','.join(map(str, uid))
        if phone:
            params['phone'] = phone
        return self.get('accounts', params=params)

    def rebind_ip(self, uid, province=''):
        data = {'uid': uid}
        if province:
            data['province'] = province
        return self.post('rebindIp', data=data)


# ---------- 通用工具 ----------

def load_accounts(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def save_accounts(path, accounts):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(accounts, f, ensure_ascii=False, indent=2)


def random_delay(min_s, max_s):
    s = random.uniform(min_s, max_s)
    print(f'  [..] 休息 {s:.1f}s 模拟真人节奏')
    time.sleep(s)


def read_lines(path, default):
    if path and os.path.isfile(path):
        lines = [l.strip() for l in open(path, encoding='utf-8') if l.strip()]
        if lines:
            return lines
    return default


def download_avatar(seed, style=AVATAR_STYLE, size=AVATAR_SIZE):
    url = f'https://api.dicebear.com/9.x/{style}/png?seed={seed}&size={size}'
    r = requests.get(url, timeout=TIMEOUT)
    if r.status_code != 200:
        return None
    return r.content


def pick_accounts(accounts, max_n, shuffle=True):
    if shuffle:
        return random.sample(accounts, min(max_n, len(accounts)))
    return accounts[:max_n]


# ---------- 各子命令 ----------

def cmd_create(args):
    """批量建号 + 可选上传头像"""
    api = AiApi(args.api, args.token)
    print(f'[*] 批量创建 {args.count} 个账号（前缀: {args.prefix}）')
    j = api.batch_create(args.prefix, args.count, args.password)
    if j.get('code') != 0:
        print(f'[!] 建号失败: {j.get("msg")}')
        sys.exit(1)
    accounts = j['data']['accounts']
    print(f'[+] 建号成功：{len(accounts)} 个，均绑定随机国内 IP')
    for a in accounts:
        print(f'    id={a["id"]} {a["nickname"]} phone={a["phone"]} ip={a["ip"]}({a["location"]})')

    # 可选上传头像
    if args.avatar:
        print(f'[*] 下载并上传头像（风格: {args.avatar}）...')
        for a in accounts:
            try:
                img = download_avatar(f'bot{a["id"]}', args.avatar)
                if img is None:
                    print(f'  [!!] {a["nickname"]} 头像下载失败')
                    a['avatar'] = ''
                    continue
                jj = api.upload_avatar(a['id'], img)
                if jj.get('code') == 0:
                    a['avatar'] = jj['data']['avatar']
                    print(f'  [ok] {a["nickname"]} -> {a["avatar"]}')
                else:
                    print(f'  [!!] {a["nickname"]} 头像上传失败: {jj.get("msg")}')
                    a['avatar'] = ''
                random_delay(0.5, 1.0)
            except Exception as e:
                print(f'  [!!] {a["nickname"]} 头像异常: {e}')
                a['avatar'] = ''
    else:
        for a in accounts:
            a['avatar'] = ''

    if args.save:
        save_accounts(args.save, accounts)
        print(f'[+] 账号信息已保存到 {args.save}')

    print(f'\n[+] 完成：创建 {len(accounts)} 个账号')
    print('[i] 后续可用 --accounts 文件执行 publish/comment/interact')


def cmd_publish(args):
    """批量发帖"""
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    posts = read_lines(args.content_file, DEFAULT_POSTS)
    n = min(args.max, len(accounts))
    selected = pick_accounts(accounts, args.max)
    print(f'[*] 用 {n} 个账号发帖（内容源: {len(posts)} 条，delay {args.delay}）')

    ok = fail = 0
    for a in selected:
        content = random.choice(posts)
        images = []
        if args.image_dir and os.path.isdir(args.image_dir):
            imgs = [os.path.join(args.image_dir, f) for f in os.listdir(args.image_dir)
                    if f.lower().endswith(('.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp'))]
            if imgs:
                images = random.sample(imgs, min(random.randint(1, 5), len(imgs)))
        try:
            j = api.publish(a['id'], content, images)
            if j.get('code') == 0:
                print(f'  [ok] {a["nickname"]}(id={a["id"]}) -> post_id={j["data"]["post_id"]}')
                ok += 1
            else:
                print(f'  [!!] {a["nickname"]} 发帖失败: {j.get("msg")}')
                fail += 1
        except Exception as e:
            print(f'  [!!] {a["nickname"]} 发帖异常: {e}')
            fail += 1
        random_delay(*[float(x) for x in args.delay.split('-')])

    print(f'\n[+] 发帖完成：成功 {ok}，失败 {fail}')


def cmd_comment(args):
    """批量回帖/评论"""
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    comments = read_lines(args.content_file, DEFAULT_COMMENTS)
    n = min(args.max, len(accounts))
    selected = pick_accounts(accounts, args.max)
    print(f'[*] 用 {n} 个账号评论帖子 {args.post_id}（delay {args.delay}）')

    ok = fail = 0
    for a in selected:
        content = random.choice(comments)
        try:
            j = api.comment(a['id'], args.post_id, content,
                            parent_id=args.parent_id or 0,
                            reply_to_uid=args.reply_to_uid or 0,
                            notify=0)
            if j.get('code') == 0:
                print(f'  [ok] {a["nickname"]}(id={a["id"]}) -> comment_id={j["data"]["comment_id"]}')
                ok += 1
            else:
                print(f'  [!!] {a["nickname"]} 评论失败: {j.get("msg")}')
                fail += 1
        except Exception as e:
            print(f'  [!!] {a["nickname"]} 评论异常: {e}')
            fail += 1
        random_delay(*[float(x) for x in args.delay.split('-')])

    print(f'\n[+] 评论完成：成功 {ok}，失败 {fail}')


def cmd_interact(args):
    """批量点赞/收藏/关注"""
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    selected = pick_accounts(accounts, args.max)
    print(f'[*] 互动：{len(selected)} 个账号，like={args.like} favorite={args.favorite} follow={args.follow}')

    ok = fail = 0
    for a in selected:
        try:
            if args.like and args.post_id:
                j = api.like(a['id'], args.post_id)
                print(f'  [ok] {a["nickname"]} 点赞 post={args.post_id} -> {j.get("msg")}')
                ok += 1
            if args.favorite and args.post_id:
                j = api.favorite(a['id'], args.post_id)
                print(f'  [ok] {a["nickname"]} 收藏 post={args.post_id} -> {j.get("msg")}')
                ok += 1
            if args.follow:
                for t in str(args.follow).split(','):
                    t = int(t.strip())
                    if t == a['id']:
                        continue
                    j = api.follow(a['id'], t)
                    print(f'  [ok] {a["nickname"]} 关注 {t} -> {j.get("msg")}')
                    ok += 1
        except Exception as e:
            print(f'  [!!] {a["nickname"]} 互动异常: {e}')
            fail += 1
        random_delay(*[float(x) for x in args.delay.split('-')])

    print(f'\n[+] 互动完成：成功 {ok}，失败 {fail}')


def cmd_accounts(args):
    """查询账号信息"""
    api = AiApi(args.api, args.token)
    j = api.accounts(uid=args.uid, phone=args.phone)
    if j.get('code') != 0:
        print(f'[!] 查询失败: {j.get("msg")}')
        sys.exit(1)
    rows = j['data']['accounts']
    if not rows:
        print('[i] 未找到账号')
        return
    for a in rows:
        print(f'id={a["id"]} {a["nickname"]} phone={a["phone"]} status={a["status"]} '
              f'ip={a["bind_ip"]}({a["bind_location"]}) avatar={a["avatar"]}')


def cmd_rebind(args):
    """重新绑定账号 IP"""
    api = AiApi(args.api, args.token)
    for uid in str(args.uid).split(','):
        uid = int(uid.strip())
        province = args.province if args.province else ''
        j = api.rebind_ip(uid, province)
        if j.get('code') == 0:
            print(f'[ok] id={uid} -> ip={j["data"]["ip"]}({j["data"]["location"]})')
        else:
            print(f'[!!] id={uid} 重绑失败: {j.get("msg")}')


# ---------- 入口 ----------

def main():
    ap = argparse.ArgumentParser(description='ACGO AI 机器人本地自动化脚本（走 AI 专用接口）')
    ap.add_argument('--api', default=API_BASE, help=f'API 域名，默认 {API_BASE}')
    ap.add_argument('--token', default=AI_TOKEN, help='X-AI-Token（默认取环境变量 AI_TOKEN）')
    sub = ap.add_subparsers(dest='cmd', required=True)

    # create
    p = sub.add_parser('create', help='批量建号（每个账号绑定随机国内IP）')
    p.add_argument('--prefix', required=True, help='昵称前缀（昵称=前缀+序号）')
    p.add_argument('--count', type=int, required=True, help='创建数量 1-50')
    p.add_argument('--password', default='123456', help='统一密码，默认 123456')
    p.add_argument('--avatar', default='', help='头像风格（lorelei/pixel-art/avataaars/bottts/thumbs），空=不传头像')
    p.add_argument('--save', default='accounts.json', help='账号信息保存文件，默认 accounts.json')
    p.set_defaults(fn=cmd_create)

    # publish
    p = sub.add_parser('publish', help='批量发帖')
    p.add_argument('--accounts', required=True, help='账号信息文件（create --save 生成）')
    p.add_argument('--content-file', default='', help='帖子内容文件（每行一条），不传用内置素材')
    p.add_argument('--image-dir', default='', help='帖子图片目录（可选，随机带图）')
    p.add_argument('--max', type=int, default=20, help='最多用多少个账号发帖，默认 20')
    p.add_argument('--delay', default='3-10', help='每个账号间隔秒数（min-max），默认 3-10')
    p.set_defaults(fn=cmd_publish)

    # comment
    p = sub.add_parser('comment', help='批量回帖/评论')
    p.add_argument('--accounts', required=True, help='账号信息文件')
    p.add_argument('--post-id', type=int, required=True, help='目标帖子 id')
    p.add_argument('--content-file', default='', help='评论内容文件（每行一条），不传用内置素材')
    p.add_argument('--parent-id', type=int, default=0, help='楼中楼父评论 id（可选）')
    p.add_argument('--reply-to-uid', type=int, default=0, help='楼中楼回复目标 uid（可选）')
    p.add_argument('--max', type=int, default=20, help='最多用多少个账号评论，默认 20')
    p.add_argument('--delay', default='3-8', help='每个账号间隔秒数（min-max），默认 3-8')
    p.set_defaults(fn=cmd_comment)

    # interact
    p = sub.add_parser('interact', help='批量点赞/收藏/关注')
    p.add_argument('--accounts', required=True, help='账号信息文件')
    p.add_argument('--post-id', type=int, default=0, help='目标帖子 id（点赞/收藏用）')
    p.add_argument('--like', action='store_true', help='点赞帖子')
    p.add_argument('--favorite', action='store_true', help='收藏帖子')
    p.add_argument('--follow', default='', help='关注的目标 uid（逗号分隔，可多个）')
    p.add_argument('--max', type=int, default=20, help='最多用多少个账号，默认 20')
    p.add_argument('--delay', default='3-8', help='每个账号间隔秒数（min-max），默认 3-8')
    p.set_defaults(fn=cmd_interact)

    # accounts
    p = sub.add_parser('accounts', help='查询账号信息（含绑定 IP）')
    p.add_argument('--uid', default='', help='用户 id（单个或逗号分隔）')
    p.add_argument('--phone', default='', help='手机号（与 --uid 二选一）')
    p.set_defaults(fn=cmd_accounts)

    # rebind-ip
    p = sub.add_parser('rebind-ip', help='重新绑定账号 IP')
    p.add_argument('--uid', required=True, help='用户 id（单个或逗号分隔）')
    p.add_argument('--province', default='', help='指定省份（不传随机省份）')
    p.set_defaults(fn=cmd_rebind)

    args = ap.parse_args()
    try:
        args.fn(args)
    except RuntimeError as e:
        print(f'[!!] {e}')
        sys.exit(1)
    except KeyboardInterrupt:
        print('\n[i] 已中断')
        sys.exit(130)


if __name__ == '__main__':
    main()
