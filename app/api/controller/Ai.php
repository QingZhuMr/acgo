<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\AiIpPool;
use app\common\ContentCheck;
use app\common\Password;
use app\common\Pusher;
use think\facade\Db;

/**
 * AI/机器人专用接口（内部工具）
 *
 * 与常规业务接口不同：**免签名、免设备密钥**，仅用独立 `AI_TOKEN`
 * （.env `AI_TOKEN`，请求头 `X-AI-Token`）保护。供本地自动化脚本
 * （批量建号、发帖、评论、互动等）直接调用，降低脚本复杂度。
 *
 * 注意：中间件对 `ai/*` 前缀直接放行（跳过 HMAC 签名校验），
 * 鉴权完全依赖 `AI_TOKEN`，务必保证 token 安全（生产可随时更换）。
 *
 * 账号绑定 IP 规则：批量建号时为每个账号分配**固定随机国内 IP**
 * （AiIpPool，含省份归属地），存 `user.bind_ip` / `user.bind_location`；
 * AI 发帖/回帖一律使用该账号绑定 IP 落库，保证同账号 IP 恒定、分散全国。
 *
 * 接口：
 *  - POST /api/ai/batchCreate   批量创建用户（含绑定随机国内IP）
 *  - POST /api/ai/uploadAvatar  给指定 uid 上传头像（multipart）
 *  - POST /api/ai/publish       发帖（uid + content + images[]）
 *  - POST /api/ai/comment       回帖/评论（uid + post_id + content，可楼中楼）
 *  - POST /api/ai/like          帖子点赞/取消点赞（uid + post_id）
 *  - POST /api/ai/favorite      帖子收藏/取消收藏（uid + post_id）
 *  - POST /api/ai/commentLike   评论点赞/取消点赞（uid + comment_id）
 *  - POST /api/ai/follow        关注/取关（uid + target_uid）
 *  - GET  /api/ai/accounts      查询账号信息（含绑定IP，按 uid 或 phone）
 *  - GET  /api/ai/postList      帖子列表（供脚本随机选帖评论）
 *  - POST /api/ai/rebindIp      重新分配账号绑定 IP（uid，可选 province）
 */
class Ai extends BaseController
{
    /** 请求头中的 AI token 键 */
    private const TOKEN_HEADER = 'x-ai-token';

    /** 后台创建统一头像默认值 */
    private const DEFAULT_AVATAR = '/static/avatar/default.png';

    /** 合法平台（供后续扩展发帖用） */
    private const PLATFORMS = ['win', 'mac', 'linux', 'android', 'ios', 'ipados', 'harmony'];

    /** 合法头像扩展名 */
    private const AVATAR_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** 帖子正文最大字数 */
    private const CONTENT_MAX = 2000;
    /** 评论最大字数 */
    private const COMMENT_MAX = 500;
    /** 单帖最多图片数 */
    private const IMAGE_MAX = 5;
    /** 单条评论最多图片数 */
    private const COMMENT_IMAGE_MAX = 1;
    /** 单张图片最大原始字节数（10MB） */
    private const IMAGE_SIZE_MAX = 10 * 1024 * 1024;
    /** 图片压缩目标大小（帖子/评论，>1MB 压缩到 ≤1MB） */
    private const IMAGE_COMPRESS_MAX = 1 * 1024 * 1024;
    /** 帖子图片目标最大边长 */
    private const POST_IMAGE_MAX_DIM = 1080;
    /** 评论图片目标最大边长 */
    private const COMMENT_IMAGE_MAX_DIM = 800;
    /** 允许的图片 MIME */
    private const IMAGE_ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    /**
     * 校验 AI_TOKEN（构造器已校验，此处冗余防御）
     */
    private function checkToken(): bool
    {
        $expected = trim((string) env('AI_TOKEN', ''));
        $given = trim((string) ($this->request->header(self::TOKEN_HEADER) ?? ''));
        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * 取账号并校验存在且未封禁；异常直接返回响应数组（json 结果）
     *
     * @return array{user:array}|null 正常返回 null；异常返回 json 数组
     */
    private function requireUser(int $uid): ?array
    {
        if ($uid <= 0) {
            return ['code' => 1, 'msg' => 'uid 无效'];
        }
        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return ['code' => 1, 'msg' => '用户不存在'];
        }
        if (0 === (int) $user['status']) {
            return ['code' => 1, 'msg' => '账号已注销'];
        }
        if (2 === (int) $user['status']) {
            return ['code' => 1, 'msg' => '账号已被封禁'];
        }
        return null;
    }

    /**
     * 获取/初始化账号绑定 IP（不存在则分配固定随机国内IP并落库）
     *
     * @return array{ip:string, location:string}
     */
    private function accountIp(int $uid): array
    {
        $bindIp  = (string) Db::name('user')->where('id', $uid)->value('bind_ip');
        $bindLoc = (string) Db::name('user')->where('id', $uid)->value('bind_location');

        if ($bindIp !== '') {
            return ['ip' => $bindIp, 'location' => $bindLoc !== '' ? $bindLoc : '未知'];
        }

        $ip = AiIpPool::random();
        if ($ip['ip'] === '') {
            return ['ip' => '', 'location' => '未知'];
        }
        Db::name('user')->where('id', $uid)->update([
            'bind_ip'       => $ip['ip'],
            'bind_location' => $ip['location'],
            'update_time'   => time(),
        ]);
        return $ip;
    }

    /**
     * 批量创建用户（机器人/种子账号）
     *
     * POST：prefix（昵称前缀）、count（1-50）、password（可空默认 123456）
     * 手机号从 10000000001 起始递增（格式正确 11 位，不占用真实手机号段，不冲突）。
     * 每个账号分配一个固定随机国内 IP（AiIpPool），返回 data.accounts[]。
     */
    public function batchCreate()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $prefix   = trim((string) $this->request->post('prefix', ''));
        $count    = (int) $this->request->post('count', 0);
        $password = (string) $this->request->post('password', '');

        if ($prefix === '') return json(['code' => 1, 'msg' => '请填写昵称前缀']);
        if ($count < 1 || $count > 50) return json(['code' => 1, 'msg' => '批量数量 1-50']);
        if ($password !== '' && strlen($password) < 6) return json(['code' => 1, 'msg' => '密码至少6位']);
        if ($password === '') $password = '123456';

        // 已占用手机号集合（10000000001 起的号段）
        $used = [];
        $rows = Db::name('user')
            ->where('phone', '>=', '10000000001')
            ->where('phone', '<=', '10000000100')
            ->field('phone')
            ->select()
            ->toArray();
        foreach ($rows as $r) {
            $used[$r['phone']] = true;
        }

        $base = 10000000001;
        while (isset($used[(string) $base])) {
            $base++;
        }

        $now = time();
        $accounts = [];
        for ($i = 1; $i <= $count; $i++) {
            $nickname = $prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            while (isset($used[(string) $base])) {
                $base++;
            }
            $phone = (string) $base;
            $used[$phone] = true;
            $base++;

            // 每个账号固定随机国内 IP
            $bind = AiIpPool::random();

            $userId = Db::name('user')->insertGetId([
                'nickname'       => $nickname,
                'password'       => Password::encrypt($password),
                'phone'          => $phone,
                'avatar'         => self::DEFAULT_AVATAR,
                'status'         => 1,
                'bind_ip'        => $bind['ip'],
                'bind_location'  => $bind['location'],
                'register_time'  => $now,
                'update_time'    => $now,
            ]);
            // 同步创建钱包记录（与注册一致）
            Db::name('wallet')->insert([
                'uid'         => $userId,
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $accounts[] = [
                'id'       => $userId,
                'nickname' => $nickname,
                'phone'    => $phone,
                'password' => $password,
                'ip'       => $bind['ip'],
                'location' => $bind['location'],
            ];
        }

        return json([
            'code' => 0,
            'msg'  => "成功创建 {$count} 个用户",
            'data' => ['accounts' => $accounts],
        ]);
    }

    /**
     * 给指定 uid 上传/设置头像
     *
     * POST multipart：uid + avatar（文件，png/jpg/jpeg/gif/webp，≤5MB）
     * 存 public/static/avatar/ai_{uid}.{ext}，覆盖同名；更新 user 表 avatar 字段。
     * 返回：data.avatar = 相对路径
     */
    public function uploadAvatar()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $uid = (int) $this->request->post('uid', 0);
        if ($uid <= 0 || !Db::name('user')->where('id', $uid)->find()) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        $file = $this->request->file('avatar');
        if (!$file) {
            return json(['code' => 1, 'msg' => '请上传头像文件']);
        }
        $size = $file->getSize();
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => '头像不能超过5MB']);
        }
        $ext = strtolower((string) pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($ext, self::AVATAR_EXTS, true)) {
            return json(['code' => 1, 'msg' => '不支持的图片格式：' . $ext]);
        }

        $fileName = 'ai_' . $uid . '.' . $ext;
        $dir = public_path() . 'static' . DIRECTORY_SEPARATOR . 'avatar';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            $file->move($dir, $fileName);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        $avatar = '/static/avatar/' . $fileName;
        Db::name('user')->where('id', $uid)->update([
            'avatar'      => $avatar,
            'update_time' => time(),
        ]);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => ['avatar' => $avatar],
        ]);
    }

    /**
     * AI 发帖
     *
     * POST multipart：uid、content（≤2000字）、images[]（可选，≤5张）
     * IP 使用该账号绑定 IP（bind_ip / bind_location）。
     * 返回：data.post_id
     */
    public function publish()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }

        $content = trim((string) $this->request->post('content', ''));
        if ('' === $content) {
            return json(['code' => 1, 'msg' => '内容不能为空']);
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            return json(['code' => 1, 'msg' => '内容最长' . self::CONTENT_MAX . '字']);
        }
        $check = ContentCheck::check($content);
        if ($check['hit']) {
            return json(['code' => 1, 'msg' => '内容敏感，请重新编辑。']);
        }

        // 图片处理（可选，最多 5 张，压缩逻辑与前台一致）
        $images = [];
        $imageSizes = [];
        $files = $this->request->file('images');
        if ($files) {
            $files = is_array($files) ? $files : [$files];
            if (count($files) > self::IMAGE_MAX) {
                return json(['code' => 1, 'msg' => '最多上传' . self::IMAGE_MAX . '张图片']);
            }
            foreach ($files as $file) {
                $mime = strtolower((string) $file->getMime());
                if (!in_array($mime, self::IMAGE_ALLOWED, true)) {
                    return json(['code' => 1, 'msg' => '仅支持 jpg/png/gif/webp/bmp 图片']);
                }
                if ($file->getSize() > self::IMAGE_SIZE_MAX) {
                    return json(['code' => 1, 'msg' => '单张图片不能超过10MB']);
                }
                $size = [0, 0];
                $url = Post::saveCompressedImage($file, $uid, 'public/static/post', 'p', self::IMAGE_COMPRESS_MAX, self::POST_IMAGE_MAX_DIM, $size);
                if ($url === null) {
                    return json(['code' => 1, 'msg' => '图片处理失败']);
                }
                $images[] = $url;
                $imageSizes[] = [$size[0], $size[1]];
            }
        }

        $ip = $this->accountIp($uid);
        $now = time();
        $postId = Db::name('post')->insertGetId([
            'user_id'       => $uid,
            'content'       => $content,
            'images'        => $images ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'image_sizes'   => $imageSizes ? json_encode($imageSizes) : null,
            'heat'          => 0,
            'comment_count' => 0,
            'report_count'  => 0,
            'status'        => 1,
            'create_time'   => $now,
            'update_time'   => $now,
            'ip'            => $ip['ip'],
            'location'      => $ip['location'],
        ]);

        return json(['code' => 0, 'msg' => '发布成功', 'data' => ['post_id' => (int) $postId]]);
    }

    /**
     * AI 回帖 / 评论
     *
     * POST：uid、post_id、content（≤500字）、parent_id（可选，楼中楼）、
     * reply_to_uid（可选）、images[]（可选，≤1张）、notify（可选 0/1，默认1）
     * notify=1 时与前台一致给帖子作者/被回复人生成通知 + WS 推送。
     * IP 使用该账号绑定 IP。返回：data.comment_id
     */
    public function comment()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }

        $postId     = (int) $this->request->post('post_id', 0);
        $content    = trim((string) $this->request->post('content', ''));
        $parentId   = (int) $this->request->post('parent_id', 0);
        $replyToUid = (int) $this->request->post('reply_to_uid', 0);
        $notify     = (int) $this->request->post('notify', 1) === 1;

        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        if ('' === $content) {
            return json(['code' => 1, 'msg' => '评论内容不能为空']);
        }
        if (mb_strlen($content) > self::COMMENT_MAX) {
            return json(['code' => 1, 'msg' => '评论最长' . self::COMMENT_MAX . '字']);
        }

        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post || 1 !== (int) $post['status']) {
            return json(['code' => 1, 'msg' => '帖子不存在或已删除']);
        }
        $check = ContentCheck::check($content);
        if ($check['hit']) {
            return json(['code' => 1, 'msg' => '内容敏感，请重新编辑。']);
        }

        // 楼中楼：确定回复目标
        $replyToName = '';
        if ($parentId > 0) {
            $parent = Db::name('post_comment')
                ->where('id', $parentId)
                ->where('post_id', $postId)
                ->where('status', 1)
                ->find();
            if (!$parent) {
                return json(['code' => 1, 'msg' => '回复的评论不存在']);
            }
            if ($replyToUid <= 0) {
                $replyToUid = (int) $parent['user_id'];
            }
        }
        if ($replyToUid > 0) {
            $replyToName = (string) Db::name('user')->where('id', $replyToUid)->value('nickname');
            if ('' === $replyToName) {
                $replyToName = '已注销用户';
            }
        }

        // 评论图片处理（可选，最多 1 张，压缩逻辑与前台一致）
        $commentImages = [];
        $files = $this->request->file('images');
        if ($files) {
            $files = is_array($files) ? $files : [$files];
            if (count($files) > self::COMMENT_IMAGE_MAX) {
                return json(['code' => 1, 'msg' => '评论最多上传' . self::COMMENT_IMAGE_MAX . '张图片']);
            }
            foreach ($files as $file) {
                $mime = strtolower((string) $file->getMime());
                if (!in_array($mime, self::IMAGE_ALLOWED, true)) {
                    return json(['code' => 1, 'msg' => '仅支持 jpg/png/gif/webp/bmp 图片']);
                }
                if ($file->getSize() > self::IMAGE_SIZE_MAX) {
                    return json(['code' => 1, 'msg' => '单张图片不能超过10MB']);
                }
                $url = Post::saveCompressedImage($file, $uid, 'public/static/comment', 'c', self::IMAGE_COMPRESS_MAX, self::COMMENT_IMAGE_MAX_DIM);
                if ($url === null) {
                    return json(['code' => 1, 'msg' => '图片处理失败']);
                }
                $commentImages[] = $url;
            }
        }

        $ip = $this->accountIp($uid);
        $now = time();
        $commentId = Db::name('post_comment')->insertGetId([
            'post_id'       => $postId,
            'user_id'       => $uid,
            'parent_id'     => $parentId,
            'reply_to_uid'  => $replyToUid,
            'reply_to_name' => $replyToName,
            'content'       => $content,
            'images'        => $commentImages ? json_encode($commentImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'report_count'  => 0,
            'status'        => 1,
            'create_time'   => $now,
            'ip'            => $ip['ip'],
            'location'      => $ip['location'],
        ]);

        // 帖子热度 +1、评论数 +1
        Db::name('post')->where('id', $postId)->inc('heat')->inc('comment_count')->update(['update_time' => $now]);

        // 通知（与前台一致；notify=0 时关闭）
        if ($notify) {
            $notifyUid = $parentId > 0 ? $replyToUid : (int) $post['user_id'];
            if ($notifyUid > 0 && $notifyUid !== $uid) {
                $postExcerpt = mb_substr((string) $post['content'], 0, 200);
                $fromUser = Db::name('user')->where('id', $uid)->field('nickname,avatar')->find();
                $fromName = (string) ($fromUser['nickname'] ?? '');
                $fromAvatar = (string) ($fromUser['avatar'] ?? '');
                Db::name('notification')->insert([
                    'user_id'          => $notifyUid,
                    'from_uid'         => $uid,
                    'post_id'          => $postId,
                    'post_content'     => $postExcerpt,
                    'comment_id'       => (int) $commentId,
                    'comment_content'  => $content,
                    'type'             => $parentId > 0 ? 2 : 1,
                    'is_read'          => 0,
                    'create_time'      => $now,
                ]);
                Pusher::send(
                    ['user_id' => $notifyUid],
                    [
                        'type'          => 'reply_notice',
                        'notification'  => [
                            'id'              => (int) $commentId,
                            'from_uid'        => $uid,
                            'from_nickname'   => $fromName,
                            'from_avatar'     => $fromAvatar,
                            'post_id'         => $postId,
                            'post_content'    => $postExcerpt,
                            'comment_id'      => (int) $commentId,
                            'comment_content' => $content,
                            'type'            => $parentId > 0 ? 2 : 1,
                            'create_time'     => $now,
                        ],
                    ]
                );
            }
        }

        return json(['code' => 0, 'msg' => '评论成功', 'data' => ['comment_id' => (int) $commentId]]);
    }

    /**
     * AI 帖子点赞 / 取消点赞（切换）
     *
     * POST：uid、post_id、notify（可选 0/1，默认1，首次点赞通知作者）
     * 返回：is_liked、like_count
     */
    public function like()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }
        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }
        $postId = (int) $this->request->post('post_id', 0);
        $notify = (int) $this->request->post('notify', 1) === 1;

        $post = Db::name('post')->where('id', $postId)->where('status', 1)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        if ((int) $post['user_id'] === $uid) {
            return json(['code' => 1, 'msg' => '不能给自己的帖子点赞']);
        }

        $exists = Db::name('post_like')
            ->where('post_id', $postId)
            ->where('user_id', $uid)
            ->find();

        if ($exists) {
            Db::name('post_like')->where('id', $exists['id'])->delete();
            Db::name('post')->where('id', $postId)->dec('like_count')->update();
            $isLiked = false;
        } else {
            Db::name('post_like')->insert([
                'post_id'     => $postId,
                'user_id'     => $uid,
                'create_time' => time(),
            ]);
            Db::name('post')->where('id', $postId)->inc('like_count')->update();
            $isLiked = true;
            if ($notify) {
                $this->notifyInteraction($uid, $post, $postId, 4);
            }
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'is_liked'   => $isLiked,
                'like_count' => (int) Db::name('post_like')->where('post_id', $postId)->count(),
            ],
        ]);
    }

    /**
     * AI 帖子收藏 / 取消收藏（切换）
     *
     * POST：uid、post_id、notify（可选 0/1，默认1，首次收藏通知作者）
     * 返回：is_favorited、favorite_count
     */
    public function favorite()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }
        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }
        $postId = (int) $this->request->post('post_id', 0);
        $notify = (int) $this->request->post('notify', 1) === 1;

        $post = Db::name('post')->where('id', $postId)->where('status', 1)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        if ((int) $post['user_id'] === $uid) {
            return json(['code' => 1, 'msg' => '不能收藏自己的帖子']);
        }

        $exists = Db::name('post_favorite')
            ->where('post_id', $postId)
            ->where('user_id', $uid)
            ->find();

        if ($exists) {
            Db::name('post_favorite')->where('id', $exists['id'])->delete();
            Db::name('post')->where('id', $postId)->dec('favorite_count')->update();
            $isFavorited = false;
        } else {
            Db::name('post_favorite')->insert([
                'post_id'     => $postId,
                'user_id'     => $uid,
                'create_time' => time(),
            ]);
            Db::name('post')->where('id', $postId)->inc('favorite_count')->update();
            $isFavorited = true;
            if ($notify) {
                $this->notifyInteraction($uid, $post, $postId, 5);
            }
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'is_favorited'   => $isFavorited,
                'favorite_count' => (int) Db::name('post_favorite')->where('post_id', $postId)->count(),
            ],
        ]);
    }

    /**
     * AI 评论点赞 / 取消点赞（切换）
     *
     * POST：uid、comment_id
     * 返回：is_liked、like_count
     */
    public function commentLike()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }
        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }
        $commentId = (int) $this->request->post('comment_id', 0);

        $comment = Db::name('post_comment')->where('id', $commentId)->where('status', 1)->find();
        if (!$comment) {
            return json(['code' => 1, 'msg' => '评论不存在']);
        }
        if ((int) $comment['user_id'] === $uid) {
            return json(['code' => 1, 'msg' => '不能给自己的评论点赞']);
        }

        $exists = Db::name('comment_like')
            ->where('comment_id', $commentId)
            ->where('user_id', $uid)
            ->find();

        if ($exists) {
            Db::name('comment_like')->where('id', $exists['id'])->delete();
            Db::name('post_comment')->where('id', $commentId)->dec('like_count')->update();
            $isLiked = false;
        } else {
            Db::name('comment_like')->insert([
                'comment_id'  => $commentId,
                'user_id'     => $uid,
                'create_time' => time(),
            ]);
            Db::name('post_comment')->where('id', $commentId)->inc('like_count')->update();
            $isLiked = true;
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'is_liked'   => $isLiked,
                'like_count' => (int) Db::name('comment_like')->where('comment_id', $commentId)->count(),
            ],
        ]);
    }

    /**
     * AI 关注 / 取关（切换）
     *
     * POST：uid、target_uid、notify（可选 0/1，默认1，关注时通知对方）
     * 拉黑互斥、不能关注自己，规则与前台一致。
     * 返回：is_following、is_mutual
     */
    public function follow()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }
        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }
        $targetUid = (int) $this->request->post('target_uid', 0);
        $notify    = (int) $this->request->post('notify', 1) === 1;

        if ($targetUid <= 0 || $targetUid === $uid) {
            return json(['code' => 1, 'msg' => '不能关注自己']);
        }
        $target = Db::name('user')->where('id', $targetUid)->find();
        if (!$target) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        $now = time();
        $exists = Db::name('user_follow')
            ->where('follower_id', $uid)
            ->where('following_id', $targetUid)
            ->find();

        if ($exists) {
            Db::name('user_follow')->where('id', $exists['id'])->delete();
            $mutual = Db::name('user_follow')
                ->where('follower_id', $targetUid)
                ->where('following_id', $uid)
                ->find();
            return json([
                'code' => 0, 'msg' => '已取消关注', 'data' => [
                    'is_following' => false,
                    'is_mutual'    => (bool) $mutual,
                ],
            ]);
        }

        // 拉黑互斥：任一方向已拉黑则禁止关注
        $blocked = Db::name('user_block')
            ->where(function ($q) use ($uid, $targetUid) {
                $q->where(function ($q1) use ($uid, $targetUid) {
                    $q1->where('blocker_id', $uid)
                        ->where('blocked_id', $targetUid);
                })->whereOr(function ($q2) use ($uid, $targetUid) {
                    $q2->where('blocker_id', $targetUid)
                        ->where('blocked_id', $uid);
                });
            })
            ->count();
        if ($blocked > 0) {
            return json(['code' => 1, 'msg' => '无法关注该用户']);
        }

        Db::name('user_follow')->insert([
            'follower_id'  => $uid,
            'following_id' => $targetUid,
            'create_time'  => $now,
        ]);
        $mutual = Db::name('user_follow')
            ->where('follower_id', $targetUid)
            ->where('following_id', $uid)
            ->find();

        // 被关注通知（notify=0 时关闭）
        if ($notify && $targetUid !== $uid) {
            $fromUser = Db::name('user')->where('id', $uid)->field('nickname,avatar')->find();
            $fromName = (string) ($fromUser['nickname'] ?? '');
            $fromAvatar = (string) ($fromUser['avatar'] ?? '');
            Db::name('notification')->insert([
                'user_id'      => $targetUid,
                'from_uid'     => $uid,
                'post_id'      => 0,
                'post_content' => '',
                'comment_id'   => 0,
                'comment_content' => '',
                'type'         => 3,
                'is_read'      => 0,
                'create_time'  => $now,
            ]);
            Pusher::send(
                ['user_id' => $targetUid],
                [
                    'type'          => 'follow_notice',
                    'notification'  => [
                        'from_uid'       => $uid,
                        'from_nickname'  => $fromName,
                        'from_avatar'    => $fromAvatar,
                        'type'           => 3,
                        'create_time'    => $now,
                    ],
                ]
            );
        }

        return json([
            'code' => 0, 'msg' => '关注成功', 'data' => [
                'is_following' => true,
                'is_mutual'    => (bool) $mutual,
            ],
        ]);
    }

    /**
     * 查询账号信息（含绑定 IP）
     *
     * GET：uid（单个 或 逗号分隔多个）或 phone（单个）。
     * 返回：data.accounts[] = {id, nickname, phone, avatar, status, bind_ip, bind_location}
     */
    public function accounts()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $uids  = trim((string) $this->request->get('uid', ''));
        $phone = trim((string) $this->request->get('phone', ''));

        $query = Db::name('user')->field('id,nickname,phone,avatar,status,bind_ip,bind_location');

        if ($phone !== '') {
            $query->where('phone', $phone);
        } elseif ($uids !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $uids))));
            if (!$ids) {
                return json(['code' => 1, 'msg' => 'uid 无效']);
            }
            $query->whereIn('id', $ids);
        } else {
            return json(['code' => 1, 'msg' => '请传 uid 或 phone']);
        }

        $list = $query->limit(100)->select()->toArray();
        foreach ($list as &$item) {
            $item['id']       = (int) $item['id'];
            $item['status']   = (int) $item['status'];
            $item['bind_ip']  = (string) ($item['bind_ip'] ?? '');
            $item['bind_location'] = (string) ($item['bind_location'] ?? '');
            unset($item['password']);
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['accounts' => $list]]);
    }

    /**
     * 重新分配账号绑定 IP
     *
     * POST：uid、province（可选，指定省份；不传随机省份）
     * 返回：data.ip / data.location
     */
    public function rebindIp()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }
        $uid = (int) $this->request->post('uid', 0);
        if (($err = $this->requireUser($uid)) !== null) {
            return json($err);
        }
        $province = trim((string) $this->request->post('province', ''));

        $bind = AiIpPool::random($province);
        if ($bind['ip'] === '') {
            return json(['code' => 1, 'msg' => 'IP 生成失败']);
        }
        Db::name('user')->where('id', $uid)->update([
            'bind_ip'       => $bind['ip'],
            'bind_location' => $bind['location'],
            'update_time'   => time(),
        ]);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => ['ip' => $bind['ip'], 'location' => $bind['location']],
        ]);
    }

    /**
     * 帖子列表（AI 脚本随机选帖评论用）
     *
     * GET：page（页码，默认1）、page_size（每页 1-50，默认 20）
     * 仅返回正常（status=1）帖子，按时间倒序；供 comment 脚本不指定 post_id 时随机挑选。
     * 返回：data.list[] { id, content(截断200), comment_count, create_time }
     */
    public function postList()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->get('page_size', 20)));

        $rows = Db::name('post')
            ->where('status', 1)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->field('id,content,comment_count,create_time')
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'            => (int) $row['id'],
                'content'       => mb_substr((string) $row['content'], 0, 200),
                'comment_count' => (int) $row['comment_count'],
                'create_time'   => (int) $row['create_time'],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list]]);
    }

    /**
     * 点赞/收藏互动通知（type=4 点赞 / type=5 收藏），与前台 Post 逻辑一致
     */
    private function notifyInteraction(int $uid, array $post, int $postId, int $type)
    {
        $targetUid = (int) $post['user_id'];
        if ($targetUid <= 0 || $targetUid === $uid) {
            return;
        }
        $now = time();
        $postExcerpt = mb_substr((string) $post['content'], 0, 200);
        $from = Db::name('user')->where('id', $uid)->field('nickname,avatar')->find();
        $fromName = (string) ($from['nickname'] ?? '');
        $fromAvatar = (string) ($from['avatar'] ?? '');

        Db::name('notification')->insert([
            'user_id'          => $targetUid,
            'from_uid'         => $uid,
            'post_id'          => $postId,
            'post_content'     => $postExcerpt,
            'comment_id'       => 0,
            'comment_content'  => '',
            'type'             => $type,
            'is_read'          => 0,
            'create_time'      => $now,
        ]);

        Pusher::send(
            ['user_id' => $targetUid],
            [
                'type'          => $type === 5 ? 'favorite_notice' : 'like_notice',
                'notification'  => [
                    'id'              => (int) $postId,
                    'from_uid'        => $uid,
                    'from_nickname'   => $fromName,
                    'from_avatar'     => $fromAvatar,
                    'post_id'         => $postId,
                    'post_content'    => $postExcerpt,
                    'comment_id'      => 0,
                    'comment_content' => '',
                    'type'            => $type,
                    'create_time'     => $now,
                ],
            ]
        );
    }
}
