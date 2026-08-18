# -*- coding: utf-8 -*-
"""
ACGO AI 机器人本地自动化脚本（走 AI 专用接口，免签名）

功能：
  1. create      批量建号（每个账号自动绑定随机国内 IP）+ 可选上传动漫头像
  2. publish     批量发帖（支持本地图片 + 随机延迟模拟真人；--ai 按动漫类型自动生成内容）
  3. comment     批量回帖/评论（可楼中楼；--ai 读取帖子内容自动评论回复）
  4. interact    批量点赞/收藏/关注（--ai 用 AI 判断帖子是否值得点赞收藏）
  5. accounts    查询账号信息（含绑定 IP）
  6. rebind-ip   重新绑定账号 IP（可指定省份）

AI 接口鉴权：请求头 X-AI-Token（对应 .env 的 AI_TOKEN），免 HMAC 签名/设备密钥。
接口路径不带 /api/ 前缀，如 https://api.yeahbear.cn/ai/batchCreate

DeepSeek（--ai 模式）：读取 acgo/.env 的 DEEPSEEK_API_KEY=sk-xxx（也可用环境变量），
模型默认 deepseek-chat，可用 DEEPSEEK_MODEL 覆盖。

依赖：Python 3 + requests（pip install requests）

用法示例（Windows）：
  python ai_bot.py create --prefix 二次元酱 --count 10 --password 123456 --save accounts.json --avatar lorelei
  python ai_bot.py publish --accounts accounts.json --content-file posts.txt --max 20 --delay 3-10
  python ai_bot.py publish --accounts accounts.json --ai --genre 热血/恋爱番 --max 20 --delay 5-15
  python ai_bot.py comment --accounts accounts.json --post-id 3 --content-file comments.txt --max 10 --delay 3-8
  python ai_bot.py comment --accounts accounts.json --ai --max 10 --delay 5-15
  python ai_bot.py interact --accounts accounts.json --post-id 3 --like --favorite --follow 5 --delay 3-8
  python ai_bot.py interact --accounts accounts.json --ai --like --favorite --max 10 --delay 5-15
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

# 默认从环境变量取；未设置则尝试读取 ../.env（脚本位于 tools/ 下）
_API_TOKEN_DEFAULT = 'acgo_ai_2026_x9Kq'


def _env_value(key, default=''):
    v = os.environ.get(key)
    if v:
        return v
    env_file = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '.env')
    if os.path.isfile(env_file):
        with open(env_file, encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line.startswith(key):
                    return line.split('=', 1)[1].strip().strip('"').strip("'")
    return default


def _env_token():
    return _env_value('AI_TOKEN', _API_TOKEN_DEFAULT)


API_BASE = os.environ.get('ACGO_API_BASE', 'https://api.yeahbear.cn')
AI_TOKEN = _env_token()
DEEPSEEK_API_KEY = _env_value('DEEPSEEK_API_KEY', '')
# 过滤占位值（.env 里未填 key 时视为未配置）
if DEEPSEEK_API_KEY and ('你的key' in DEEPSEEK_API_KEY or DEEPSEEK_API_KEY == 'sk-' or DEEPSEEK_API_KEY.startswith('sk-your')):
    DEEPSEEK_API_KEY = ''
DEEPSEEK_BASE = os.environ.get('DEEPSEEK_BASE', 'https://api.deepseek.com')

# 头像来源：
#   - photo   真人照片（randomuser.me 官方头像库，随机男女，风格最自然多样）
#   - pravatar 真人照片（i.pravatar.cc，真人头像库）
#   - mix     随机混合：真人照片 + dicebear 多风格卡通（风格最分散）
#   - lorelei/pixel-art/avataaars/bottts/thumbs  dicebear 指定卡通风格
AVATAR_STYLE = 'photo'     # 默认真人照片（原 lorelei 卡通风格太统一）
AVATAR_SIZE = 256
TIMEOUT = 20

# dicebear 卡通风格列表（mix 时随机挑选）
DICEBEAR_STYLES = ['lorelei', 'pixel-art', 'avataaars', 'bottts', 'thumbs', 'adventurer', 'big-ears', 'croodles']
# pravatar 真人头像可用编号范围
PRAVATAR_MAX = 70

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

    def upload_avatar(self, uid, img_bytes, ext='png'):
        ext = ext.lower() if ext else 'png'
        mime = 'image/jpeg' if ext in ('jpg', 'jpeg') else 'image/png'
        return self.post('uploadAvatar', data={'uid': uid},
                         files={'avatar': (f'avatar.{ext}', img_bytes, mime)})

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

    def post_list(self, page=1, page_size=20, full=False):
        params = {'page': page, 'page_size': page_size}
        if full:
            params['full'] = 1
        return self.get('postList', params=params)

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


class DeepSeek:
    """DeepSeek 大模型客户端（用于 AI 生成帖子内容 / 评论 / 点赞判断）

    依赖 .env 的 DEEPSEEK_API_KEY（或环境变量），未配置时仅提示不自动退出。
    模型默认 deepseek-v4-flash（V4 Flash，快速免深度思考版）；
    可用 DEEPSEEK_MODEL 环境变量覆盖。
    """

    MODEL = os.environ.get('DEEPSEEK_MODEL', 'deepseek-v4-flash')
    MAX_TOKENS = 512
    TIMEOUT = 60

    def __init__(self, api_key=None, base=None):
        self.api_key = api_key or DEEPSEEK_API_KEY
        self.base = (base or DEEPSEEK_BASE).rstrip('/')

    @property
    def available(self):
        return bool(self.api_key)

    def chat(self, system, user, temperature=0.9, max_tokens=None):
        """调用 chat/completions，返回生成的文本（strip 后）。失败抛 RuntimeError。"""
        if not self.available:
            raise RuntimeError('未配置 DEEPSEEK_API_KEY（请写入 acgo/.env 或设置环境变量）')
        url = f'{self.base}/chat/completions'
        payload = {
            'model': self.MODEL,
            'messages': [
                {'role': 'system', 'content': system},
                {'role': 'user', 'content': user},
            ],
            'temperature': temperature,
            'max_tokens': max_tokens or self.MAX_TOKENS,
            'stream': False,
        }
        headers = {
            'Authorization': f'Bearer {self.api_key}',
            'Content-Type': 'application/json',
        }
        try:
            r = requests.post(url, json=payload, headers=headers, timeout=self.TIMEOUT)
            j = r.json()
        except Exception as e:
            raise RuntimeError(f'DeepSeek 请求失败: {e}')
        if r.status_code != 200 or 'error' in j:
            raise RuntimeError(f'DeepSeek 错误({r.status_code}): {j.get("error", {}).get("message", r.text[:200])}')
        choices = j.get('choices') or []
        if not choices:
            raise RuntimeError(f'DeepSeek 返回异常: {r.text[:200]}')
        text = (choices[0].get('message') or {}).get('content') or ''
        return text.strip()


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


def _get(url, headers=None):
    try:
        r = requests.get(url, timeout=TIMEOUT, headers=headers)
        if r.status_code == 200 and r.content:
            return r.content
    except Exception:
        pass
    return None


# 常见浏览器 UA（部分图片站有反爬）
_BROWSER_UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
               '(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36')


def _randomuser_photo(seed):
    """randomuser.me 官方真人头像库：男女随机，同一 seed 稳定对应同一张"""
    # 0-99 索引，男女各 100 张；seed 决定男女与编号，保证同账号头像固定
    male = (int(seed) * 7 + 3) % 2 == 0
    idx = (int(seed) * 13 + 5) % 100
    gender = 'men' if male else 'women'
    return _get(f'https://randomuser.me/api/portraits/{gender}/{idx}.jpg'), 'jpg'


def _pravatar_photo(seed):
    """pravatar.cc 真人头像库（1-70 编号）；失败回退 randomuser.me"""
    idx = (int(seed) * 17 + 9) % PRAVATAR_MAX + 1
    img = _get(f'https://i.pravatar.cc/{AVATAR_SIZE}?img={idx}', {'User-Agent': _BROWSER_UA})
    if img:
        return img, 'jpg'
    # pravatar.cc 有反爬（403）时回退到稳定的 randomuser.me
    return _randomuser_photo(seed)


def _dicebear(seed, style):
    url = f'https://api.dicebear.com/9.x/{style}/png?seed={seed}&size={AVATAR_SIZE}'
    return _get(url), 'png'


def download_avatar(seed, style=AVATAR_STYLE, size=AVATAR_SIZE):
    """下载头像，返回 (bytes, ext)。失败返回 (None, '')。

    style:
      - photo / pravatar 真人照片
      - mix：随机混合真人照片 + dicebear 多风格卡通（风格最分散）
      - 其他（lorelei/pixel-art/...）视为 dicebear 指定风格
    """
    style = (style or '').strip().lower()
    if style == 'photo':
        return _randomuser_photo(seed)
    if style == 'pravatar':
        return _pravatar_photo(seed)
    if style == 'mix':
        # 50% 真人照片，50% 随机 dicebear 卡通风格
        if random.random() < 0.5:
            return _randomuser_photo(seed)
        return _dicebear(seed, random.choice(DICEBEAR_STYLES))
    # dicebear 指定风格（默认 photo，容错未知风格）
    return _dicebear(seed, style)


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

    # 可选上传头像（默认 photo=真人照片；lorelei/pixel-art/...=dicebear 卡通；mix=随机混合）
    if args.avatar:
        print(f'[*] 下载并上传头像（来源: {args.avatar}）...')
        for a in accounts:
            try:
                img, ext = download_avatar(a['id'], args.avatar)
                if not img:
                    print(f'  [!!] {a["nickname"]} 头像下载失败')
                    a['avatar'] = ''
                    continue
                jj = api.upload_avatar(a['id'], img, ext)
                if jj.get('code') == 0:
                    a['avatar'] = jj['data']['avatar']
                    print(f'  [ok] {a["nickname"]} -> {a["avatar"]}')
                else:
                    print(f'  [!!] {a["nickname"]} 头像上传失败: {jj.get("msg")}')
                    a['avatar'] = ''
                random_delay(0.3, 0.6)
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
    """批量发帖

    --ai 时用 DeepSeek 按动漫类型（--genre）为每个账号自动生成不同帖子内容；
    否则从 --content-file 或内置素材随机挑。
    """
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    ai = DeepSeek() if args.ai else None
    if args.ai and not _check_ai(ai):
        return
    posts = read_lines(args.content_file, DEFAULT_POSTS)
    n = min(args.max, len(accounts))
    selected = pick_accounts(accounts, args.max)
    mode = f'AI 自动生成（类型: {args.genre}）' if args.ai else f'素材池 {len(posts)} 条'
    print(f'[*] 用 {n} 个账号发帖（内容: {mode}，delay {args.delay}）')

    ok = fail = 0
    for a in selected:
        if args.ai:
            try:
                content = _ai_publish_content(ai, args.genre)
                if not content:
                    print(f'  [!!] {a["nickname"]} AI 生成内容为空')
                    fail += 1
                    random_delay(*[float(x) for x in args.delay.split('-')])
                    continue
            except Exception as e:
                print(f'  [!!] {a["nickname"]} AI 生成失败: {e}')
                fail += 1
                random_delay(*[float(x) for x in args.delay.split('-')])
                continue
        else:
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
                print(f'  [ok] {a["nickname"]}(id={a["id"]}) -> post_id={j["data"]["post_id"]} | {content[:40]}...')
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
    """批量回帖/评论

    --post-id 不传时，自动读取数据库已有帖子随机挑选（每次评论随机选一个帖）。
    --ai 时用 DeepSeek 读取帖子内容自动生成针对性的评论回复。
    """
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    ai = DeepSeek() if args.ai else None
    if args.ai and not _check_ai(ai):
        return
    comments = read_lines(args.content_file, DEFAULT_COMMENTS)
    n = min(args.max, len(accounts))
    selected = pick_accounts(accounts, args.max)

    # 帖子池（--ai 时拉完整内容供 AI 分析；否则仅 id）
    post_pool = []
    if args.post_id:
        if args.ai:
            target = _find_post(api, args.post_id)
            if target:
                post_pool = [target]
            else:
                print(f'[!] 未找到帖子 {args.post_id}（AI 模式需要读取内容）')
                return
        else:
            post_pool = [{'id': args.post_id}]
        print(f'[*] 用 {n} 个账号评论帖子 {args.post_id}（{"AI生成" if args.ai else "素材"}，delay {args.delay}）')
    else:
        print(f'[*] 用 {n} 个账号随机选帖评论（{"AI生成" if args.ai else "素材"}，delay {args.delay}）...')
        post_pool = _fetch_post_pool(api, full=args.ai)
        if not post_pool:
            print('[!] 未获取到帖子，请先发帖或用 --post-id 指定')
            return
        print(f'[i] 帖子池 {len(post_pool)} 条，评论时随机挑选')

    ok = fail = 0
    for a in selected:
        post = random.choice(post_pool)
        post_id = post['id']
        if args.ai:
            try:
                content = _ai_comment_content(ai, post.get('content') or '')
                if not content:
                    print(f'  [!!] {a["nickname"]} AI 生成评论为空')
                    fail += 1
                    random_delay(*[float(x) for x in args.delay.split('-')])
                    continue
            except Exception as e:
                print(f'  [!!] {a["nickname"]} AI 生成失败: {e}')
                fail += 1
                random_delay(*[float(x) for x in args.delay.split('-')])
                continue
        else:
            content = random.choice(comments)
        try:
            j = api.comment(a['id'], post_id, content,
                            parent_id=args.parent_id or 0,
                            reply_to_uid=args.reply_to_uid or 0,
                            notify=0)
            if j.get('code') == 0:
                print(f'  [ok] {a["nickname"]}(id={a["id"]}) 评论 post={post_id} -> comment_id={j["data"]["comment_id"]} | {content[:30]}...')
                ok += 1
            else:
                print(f'  [!!] {a["nickname"]} 评论失败: {j.get("msg")}')
                fail += 1
        except Exception as e:
            print(f'  [!!] {a["nickname"]} 评论异常: {e}')
            fail += 1
        random_delay(*[float(x) for x in args.delay.split('-')])

    print(f'\n[+] 评论完成：成功 {ok}，失败 {fail}')


def _fetch_post_pool(api, pages=3, page_size=50, full=False):
    """拉取帖子池（[{id, content, ...}]），full=True 时含完整内容供 AI 分析"""
    pool = []
    for page in range(1, pages + 1):
        try:
            j = api.post_list(page=page, page_size=page_size, full=full)
            rows = (j.get('data') or {}).get('list') or []
            if not rows:
                break
            pool.extend(rows)
        except Exception:
            break
    return pool


def _find_post(api, post_id):
    """根据 post_id 拉取完整帖子内容（遍历前 3 页）"""
    for page in range(1, 4):
        try:
            j = api.post_list(page=page, page_size=50, full=True)
            rows = (j.get('data') or {}).get('list') or []
            if not rows:
                break
            for r in rows:
                if r.get('id') == post_id:
                    return r
        except Exception:
            break
    return None


# ---------- DeepSeek AI 辅助 ----------

_DS_SYS_PUBLISH = '你是动漫社区用户，发条自然的帖子（30-150字），直接输出正文。'

_DS_SYS_COMMENT = '你是动漫社区用户，针对帖子内容写条自然评论（5-60字），直接输出。'

_DS_SYS_LIKE = '值得点赞吗？只回答"是"或"否"。'


def _ai_publish_content(ai, genre):
    return ai.chat(_DS_SYS_PUBLISH, f'动漫主题/类型：{genre}')


def _ai_comment_content(ai, post_content):
    if not post_content or not post_content.strip():
        raise RuntimeError('帖子内容为空，无法生成评论')
    return ai.chat(_DS_SYS_COMMENT, f'帖子内容：{post_content}')


def _ai_should_like(ai, post_content):
    if not post_content or not post_content.strip():
        return True  # 无内容时默认点赞
    ans = ai.chat(_DS_SYS_LIKE, f'帖子内容：{post_content}', temperature=0.5, max_tokens=16)
    return '是' in ans or '赞' in ans


def _check_ai(ai):
    if not ai.available:
        print('[!] 未配置 DEEPSEEK_API_KEY，请在 acgo/.env 添加：DEEPSEEK_API_KEY=sk-xxx')
        return False
    return True


def cmd_interact(args):
    """批量点赞/收藏/关注

    不指定 --post-id 且启用了 like/favorite 时，自动读取数据库已有帖子随机挑选
    （每个账号点赞/收藏时随机挑一个帖子，不同账号可能不同，更接近真人）。
    --ai 时用 DeepSeek 读取帖子内容判断是否值得点赞/收藏。
    """
    api = AiApi(args.api, args.token)
    accounts = load_accounts(args.accounts)
    selected = pick_accounts(accounts, args.max)
    ai = DeepSeek() if args.ai else None
    if args.ai and not _check_ai(ai):
        return

    # 需要选帖时（启用了 like/favorite），预拉帖子池
    post_pool = []
    if args.like or args.favorite:
        if args.post_id:
            if args.ai:
                target = _find_post(api, args.post_id)
                if not target:
                    print(f'[!] 未找到帖子 {args.post_id}（AI 模式需要读取内容）')
                    return
                post_pool = [target]
            else:
                post_pool = [{'id': args.post_id}]
        else:
            print('[*] 未指定 --post-id，拉取帖子池用于随机点赞/收藏...')
            post_pool = _fetch_post_pool(api, full=args.ai)
            if not post_pool:
                print('[!] 未获取到帖子，请先发帖或用 --post-id 指定')
                return
            print(f'[i] 帖子池 {len(post_pool)} 条')

    print(f'[*] 互动：{len(selected)} 个账号，like={args.like} favorite={args.favorite} follow={args.follow}'
          + ('，AI 判断' if args.ai else ''))

    ok = fail = skip = 0
    for a in selected:
        try:
            if args.like:
                post = random.choice(post_pool)
                post_id = post['id']
                if args.ai and not _ai_should_like(ai, post.get('content') or ''):
                    print(f'  [..] {a["nickname"]} AI 判断不点赞 post={post_id}')
                    skip += 1
                else:
                    j = api.like(a['id'], post_id)
                    print(f'  [ok] {a["nickname"]} 点赞 post={post_id} -> {j.get("msg")}')
                    ok += 1
            if args.favorite:
                post = random.choice(post_pool)
                post_id = post['id']
                if args.ai and not _ai_should_like(ai, post.get('content') or ''):
                    print(f'  [..] {a["nickname"]} AI 判断不收藏 post={post_id}')
                    skip += 1
                else:
                    j = api.favorite(a['id'], post_id)
                    print(f'  [ok] {a["nickname"]} 收藏 post={post_id} -> {j.get("msg")}')
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

    print(f'\n[+] 互动完成：成功 {ok}，跳过 {skip}，失败 {fail}')


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
    p.add_argument('--avatar', default=AVATAR_STYLE,
                   help='头像来源：photo(真人照片,默认)/pravatar(真人)/mix(真人+卡通随机混合)/lorelei/pixel-art/avataaars/bottts/thumbs 等 dicebear 风格；空字符串=不传头像')
    p.add_argument('--save', default='accounts.json', help='账号信息保存文件，默认 accounts.json')
    p.set_defaults(fn=cmd_create)

    # publish
    p = sub.add_parser('publish', help='批量发帖')
    p.add_argument('--accounts', required=True, help='账号信息文件（create --save 生成）')
    p.add_argument('--content-file', default='', help='帖子内容文件（每行一条），不传用内置素材')
    p.add_argument('--image-dir', default='', help='帖子图片目录（可选，随机带图）')
    p.add_argument('--ai', action='store_true', help='用 DeepSeek 按动漫类型自动生成帖子内容')
    p.add_argument('--genre', default='二次元动漫', help='AI 发帖的动漫类型/主题（默认: 二次元动漫）')
    p.add_argument('--max', type=int, default=20, help='最多用多少个账号发帖，默认 20')
    p.add_argument('--delay', default='3-10', help='每个账号间隔秒数（min-max），默认 3-10')
    p.set_defaults(fn=cmd_publish)

    # comment
    p = sub.add_parser('comment', help='批量回帖/评论')
    p.add_argument('--accounts', required=True, help='账号信息文件')
    p.add_argument('--post-id', type=int, default=0, help='目标帖子 id（不传则自动读取数据库已有帖子随机选一个评论）')
    p.add_argument('--content-file', default='', help='评论内容文件（每行一条），不传用内置素材')
    p.add_argument('--ai', action='store_true', help='用 DeepSeek 读取帖子内容自动生成针对性评论')
    p.add_argument('--parent-id', type=int, default=0, help='楼中楼父评论 id（可选）')
    p.add_argument('--reply-to-uid', type=int, default=0, help='楼中楼回复目标 uid（可选）')
    p.add_argument('--max', type=int, default=20, help='最多用多少个账号评论，默认 20')
    p.add_argument('--delay', default='3-8', help='每个账号间隔秒数（min-max），默认 3-8')
    p.set_defaults(fn=cmd_comment)

    # interact
    p = sub.add_parser('interact', help='批量点赞/收藏/关注')
    p.add_argument('--accounts', required=True, help='账号信息文件')
    p.add_argument('--post-id', type=int, default=0, help='目标帖子 id（点赞/收藏用；不传则自动读取数据库已有帖子随机选一个）')
    p.add_argument('--like', action='store_true', help='点赞帖子')
    p.add_argument('--favorite', action='store_true', help='收藏帖子')
    p.add_argument('--ai', action='store_true', help='用 DeepSeek 读取帖子内容判断是否值得点赞/收藏')
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
