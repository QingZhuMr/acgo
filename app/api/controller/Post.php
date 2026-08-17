<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\ContentCheck;
use app\common\ImageProcessor;
use app\common\IpLocation;
use app\common\Pusher;
use app\common\UserInfoCache;
use think\facade\Db;

/**
 * 广场（帖子）前台接口
 *
 * 业务场景：类小红书帖子 —— 发帖 / 回帖 / 举报 / 热度推荐
 *   - list / detail：公开接口（仅签名，游客可浏览）
 *   - publish / comment / report：业务接口（签名 + 设备密钥）
 */
class Post extends BaseController
{
    /** 帖子正文最大字数 */
    protected const CONTENT_MAX = 2000;
    /** 评论最大字数 */
    protected const COMMENT_MAX = 500;
    /** 单帖最多图片数 */
    protected const IMAGE_MAX = 5;
    /** 单条评论最多图片数 */
    protected const COMMENT_IMAGE_MAX = 1;
    /** 单张图片最大原始字节数（10MB） */
    protected const IMAGE_SIZE_MAX = 10 * 1024 * 1024;
    /** 图片压缩目标大小（帖子/评论，>1MB 压缩到 ≤1MB） */
    protected const IMAGE_COMPRESS_MAX = 1 * 1024 * 1024;
    /** 帖子图片目标最大边长（超过先缩到该尺寸再压缩） */
    protected const POST_IMAGE_MAX_DIM = 1080;
    /** 评论图片目标最大边长 */
    protected const COMMENT_IMAGE_MAX_DIM = 800;
    /** 允许的图片 MIME */
    protected const IMAGE_ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    /** 举报原因枚举 */
    protected const REPORT_REASON = ['spam', 'porn', 'abuse', 'ad', 'infringe', 'other'];

    /**
     * 广场帖子列表（公开，仅签名）
     *
     * 随机推荐：每次刷新结果都不同；热度越高出现概率越高。
     * 实现：ORDER BY RAND() * (heat + 1) DESC —— 每条记录生成随机数乘以热度权重，
     * 热度高的帖子排序期望更靠前，同时 RAND() 每次重新生成保证结果不重复。
     *
     * 参数：page / page_size / exclude_ids（逗号分隔，用于"加载更多"排除已看的帖子）
     */
    public function list()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->param('page_size', 10)));
        $exclude  = trim((string) $this->request->param('exclude_ids', ''));
        $keyword  = trim((string) $this->request->param('keyword', ''));

        $query = Db::name('post')->where('status', 1);

        if ('' !== $keyword) {
            $query->whereLike('content', '%' . $keyword . '%');
        }

        if ('' !== $exclude) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $exclude))));
            if ($ids) {
                $query->whereNotIn('id', $ids);
            }
        }

        // 列表不返回 heat（热度为后台隐性推荐因子，不对用户展示）
        // 排序：搜索时按时间倒序；浏览时用热度权重随机推荐
        $order = '' !== $keyword ? 'create_time DESC' : 'RAND() * (heat + 1) DESC';
        $list = $query
            ->field('id,user_id,content,images,comment_count,create_time,location')
            ->orderRaw($order)
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 批量统计点赞数（post_like 表），避免 N+1
        $likeMap = self::likeCountMap(array_column($list, 'id'));
        // 批量查询发帖人昵称/头像（实时联表 user，避免冗余）
        $userMap = self::userInfoMap(array_column($list, 'user_id'));
        // 当前用户（可选登录态）已点赞的帖子 ID 集合，用于前端渲染空心/实心爱心
        $likedIds = self::likedPostIds($this->currentUserId(), array_column($list, 'id'));

        foreach ($list as &$item) {
            $u                     = $userMap[$item['user_id']] ?? null;
            $item['id']            = (int) $item['id'];
            $item['user_id']       = (int) $item['user_id'];
            $item['comment_count'] = (int) $item['comment_count'];
            $item['like_count']    = $likeMap[(int) $item['id']] ?? 0;
            $item['is_liked']      = in_array((int) $item['id'], $likedIds, true);
            $item['create_time']   = (int) $item['create_time'];
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i', $item['create_time']) : '';
            $item['images']        = self::decodeImages($item['images'] ?? '');
            $item['location']      = (string) ($item['location'] ?? '');
            $item['nickname']      = $u['nickname'] ?? '';
            $item['avatar']        = ($u['avatar'] ?? '') ?: '/static/admin/img/default-avatar.png';
            // 列表仅截取正文预览，避免传输过大
            $item['content']       = mb_substr((string) $item['content'], 0, 80);
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list, 'page' => $page]]);
    }

    /**
     * 帖子详情（公开，仅签名）：帖子 + 评论（顶层分页 + 子评论）
     *
     * 参数：post_id / page（评论分页，默认1）/ page_size
     */
    public function detail()
    {
        $postId   = (int) $this->request->param('post_id', 0);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->param('page_size', 20)));

        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        $post = Db::name('post')->where('id', $postId)->where('status', 1)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在或已删除']);
        }

        $uid = $this->currentUserId();
        $post['id']               = (int) $post['id'];
        $post['user_id']          = (int) $post['user_id'];
        $post['comment_count']    = (int) $post['comment_count'];
        $post['favorite_count']   = (int) ($post['favorite_count'] ?? 0);
        $post['like_count']       = self::likeCountMap([$postId])[$postId] ?? 0;
        $post['is_liked']         = $uid > 0
            ? (bool) Db::name('post_like')
                ->where('post_id', $postId)
                ->where('user_id', $uid)
                ->count()
            : false;
        $post['is_favorited']     = $uid > 0
            ? (bool) Db::name('post_favorite')
                ->where('post_id', $postId)
                ->where('user_id', $uid)
                ->count()
            : false;
        $post['create_time']      = (int) $post['create_time'];
        $post['create_time_text'] = $post['create_time'] ? date('Y-m-d H:i', $post['create_time']) : '';
        $post['images']           = self::decodeImages($post['images'] ?? '');
        $post['location']         = (string) ($post['location'] ?? '');
        unset($post['ip']); // 不向客户端暴露原始 IP，仅返回归属地

        // 发帖人昵称/头像实时联表 user 查询（不再冗余存于 post 表）
        $userMap = self::userInfoMap([$post['user_id']]);
        $u       = $userMap[$post['user_id']] ?? null;
        $post['nickname'] = $u['nickname'] ?? '';
        $post['avatar']   = ($u['avatar'] ?? '') ?: '/static/admin/img/default-avatar.png';

        // 顶层评论分页
        $topTotal = Db::name('post_comment')
            ->where('post_id', $postId)
            ->where('status', 1)
            ->where('parent_id', 0)
            ->count();

        // 评论总数（含楼中楼子评论）—— 楼中楼也算评论，与 post.comment_count 口径一致
        $commentTotal = Db::name('post_comment')
            ->where('post_id', $postId)
            ->where('status', 1)
            ->count();

        $topList = Db::name('post_comment')
            ->where('post_id', $postId)
            ->where('status', 1)
            ->where('parent_id', 0)
            ->order('create_time', 'asc')
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        // 子评论（楼中楼）
        $children = [];
        $topIds   = array_column($topList, 'id');
        if ($topIds) {
            $childList = Db::name('post_comment')
                ->where('post_id', $postId)
                ->where('status', 1)
                ->whereIn('parent_id', $topIds)
                ->order('create_time', 'asc')
                ->select()
                ->toArray();
            foreach ($childList as $c) {
                $children[(int) $c['parent_id']][] = $c;
            }
        }

        // 评论点赞数批量统计 + 当前用户已点赞评论集合（含子评论，避免 N+1）
        $allCommentIds = array_column($topList, 'id');
        foreach ($children as $list) {
            foreach ($list as $c) {
                $allCommentIds[] = $c['id'];
            }
        }
        $commentLikeMap = self::commentLikeCountMap($allCommentIds);
        $likedCommentIds = self::likedCommentIds($uid, $allCommentIds);

        // 评论用户昵称/头像实时联表 user（post_comment 已不存 nickname/avatar 冗余字段）
        $commentUserIds = [];
        foreach ($topList as $t) {
            $commentUserIds[] = (int) $t['user_id'];
        }
        foreach ($children as $list) {
            foreach ($list as $c) {
                $commentUserIds[] = (int) $c['user_id'];
            }
        }
        $commentUserMap = self::userInfoMap($commentUserIds);

        $comments = [];
        foreach ($topList as $t) {
            $t = self::formatComment($t, $commentUserMap, $commentLikeMap, $likedCommentIds);
            $formatted = [];
            foreach (($children[(int) $t['id']] ?? []) as $c) {
                $formatted[] = self::formatComment($c, $commentUserMap, $commentLikeMap, $likedCommentIds);
            }
            $t['children'] = $formatted;
            $comments[] = $t;
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'post'              => $post,
                'comments'          => $comments,
                'comment_total'     => (int) $commentTotal,
                // 顶层评论总数（分页基于顶层评论，客户端用其判断"加载更多"是否还有剩余）
                'comment_top_total' => (int) $topTotal,
                'page'              => $page,
            ],
        ]);
    }

    /**
     * 发帖（业务接口：签名 + 设备密钥）
     * multipart/form-data：content（正文）、images[]（图片，可多张，可选）
     */
    public function publish()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return json(['code' => 401, 'msg' => '账号不存在']);
        }
        if (0 === (int) $user['status']) {
            return json(['code' => 401, 'msg' => '账号已注销']);
        }
        if (2 === (int) $user['status']) {
            return json(['code' => 1, 'msg' => '账号已被封禁，暂不能发帖']);
        }

        $content = trim((string) $this->request->post('content', ''));
        if ('' === $content) {
            return json(['code' => 1, 'msg' => '内容不能为空']);
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            return json(['code' => 1, 'msg' => '内容最长' . self::CONTENT_MAX . '字']);
        }

        // 文字审核
        $check = ContentCheck::check($content);
        if ($check['hit']) {
            return json(['code' => 1, 'msg' => '内容敏感，请重新编辑。']);
        }

        // 图片处理（原始 ≤10MB，上传后 >1MB 压缩到 ≤1MB）
        $images = [];
        $files  = $this->request->file('images');
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
                $url = self::saveCompressedImage($file, $uid, 'public/static/post', 'p', self::IMAGE_COMPRESS_MAX, self::POST_IMAGE_MAX_DIM);
                if ($url === null) {
                    return json(['code' => 1, 'msg' => '图片处理失败']);
                }
                $images[] = $url;
            }
        }

        $now = time();
        $ip  = $this->clientIp();
        $postId = Db::name('post')->insertGetId([
            'user_id'       => $uid,
            'content'       => $content,
            'images'        => $images ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'heat'          => 0,
            'comment_count' => 0,
            'report_count'  => 0,
            'status'        => 1,
            'create_time'   => $now,
            'update_time'   => $now,
            'ip'            => $ip,
            'location'      => IpLocation::resolve($ip),
        ]);

        return json(['code' => 0, 'msg' => '发布成功', 'data' => ['id' => (int) $postId]]);
    }

    /**
     * 回帖 / 评论（业务接口：签名 + 设备密钥）
     * POST：post_id、content、parent_id（可选，楼中楼）、reply_to_uid（可选）
     */
    public function comment()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $postId    = (int) $this->request->post('post_id', 0);
        $content   = trim((string) $this->request->post('content', ''));
        $parentId  = (int) $this->request->post('parent_id', 0);
        $replyToUid = (int) $this->request->post('reply_to_uid', 0);

        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        if ('' === $content) {
            return json(['code' => 1, 'msg' => '评论内容不能为空']);
        }
        if (mb_strlen($content) > self::COMMENT_MAX) {
            return json(['code' => 1, 'msg' => '评论最长' . self::COMMENT_MAX . '字']);
        }

        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return json(['code' => 401, 'msg' => '账号不存在']);
        }
        if (2 === (int) $user['status']) {
            return json(['code' => 1, 'msg' => '账号已被封禁，暂不能评论']);
        }

        // 帖子必须存在且正常
        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post || 1 !== (int) $post['status']) {
            return json(['code' => 1, 'msg' => '帖子不存在或已删除']);
        }

        // 文字审核
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
            // 回复目标已注销/被删除时兜底展示
            if ('' === $replyToName) {
                $replyToName = UserInfoCache::GHOST_NAME;
            }
        }

        // 评论图片处理（可选，最多 1 张，原始 ≤10MB，>1MB 压缩到 ≤1MB）
        $commentImages = [];
        $files         = $this->request->file('images');
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
                $url = self::saveCompressedImage($file, $uid, 'public/static/comment', 'c', self::IMAGE_COMPRESS_MAX, self::COMMENT_IMAGE_MAX_DIM);
                if ($url === null) {
                    return json(['code' => 1, 'msg' => '图片处理失败']);
                }
                $commentImages[] = $url;
            }
        }

        $now = time();
        $ip  = $this->clientIp();
        $commentId = Db::name('post_comment')->insertGetId([
            'post_id'       => $postId,
            'user_id'       => $uid,
            // 'nickname'      => $user['nickname'],
            // 'avatar'        => $user['avatar'],
            'parent_id'     => $parentId,
            'reply_to_uid'  => $replyToUid,
            'reply_to_name' => $replyToName,
            'content'       => $content,
            'images'        => $commentImages ? json_encode($commentImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'report_count'  => 0,
            'status'        => 1,
            'create_time'   => $now,
            'ip'            => $ip,
            'location'      => IpLocation::resolve($ip),
        ]);

        // 帖子热度 +1、评论数 +1（互动提升热度，后台也可手动加）
        Db::name('post')->where('id', $postId)->inc('heat')->inc('comment_count')->update(['update_time' => $now]);

        // ========== 生成回复通知 ==========
        // 通知对象：
        //   - 顶层评论（parent_id=0）→ 通知帖子作者（type=1 评论了我的帖子）
        //   - 楼中楼回复（parent_id>0）→ 通知被回复人 reply_to_uid（type=2 回复了我的评论）
        // 排除给自己发通知（自己评论自己的帖子 / 自己回复自己）。
        $notifyUid = $parentId > 0 ? $replyToUid : (int) $post['user_id'];
        if ($notifyUid > 0 && $notifyUid !== $uid) {
            $postExcerpt = mb_substr((string) $post['content'], 0, 200);
            // 触发者昵称/头像不冗余入库（notification 表已无这两个快照字段），
            // 仅推送时携带实时值供客户端立即展示；列表接口经 UserInfoCache 实时联表。
            $fromName   = (string) $user['nickname'];
            $fromAvatar = (string) ($user['avatar'] ?? '');
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

            // 实时推送：对方在线则立即收到桌面/应用内通知（离线则由客户端下次轮询拉取）
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

        return json(['code' => 0, 'msg' => '评论成功', 'data' => ['id' => (int) $commentId]]);
    }

    /**
     * 举报（业务接口：签名 + 设备密钥）
     * POST：post_id、comment_id（可选，0=举报帖子）、reason、content（可选）
     */
    public function report()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $postId    = (int) $this->request->post('post_id', 0);
        $commentId = (int) $this->request->post('comment_id', 0);
        $reason    = trim((string) $this->request->post('reason', ''));
        $content   = trim((string) $this->request->post('content', ''));

        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '举报对象不存在']);
        }
        if (!in_array($reason, self::REPORT_REASON, true)) {
            return json(['code' => 1, 'msg' => '请选择举报原因']);
        }
        if (mb_strlen($content) > 500) {
            return json(['code' => 1, 'msg' => '举报说明最长500字']);
        }

        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        // 举报评论时，评论必须属于该帖子
        if ($commentId > 0) {
            $comment = Db::name('post_comment')->where('id', $commentId)->where('post_id', $postId)->find();
            if (!$comment) {
                return json(['code' => 1, 'msg' => '被举报的评论不存在']);
            }
        }

        // 防重复举报（同一人、同一对象、待处理中）
        $exists = Db::name('post_report')
            ->where('post_id', $postId)
            ->where('comment_id', $commentId)
            ->where('user_id', $uid)
            ->where('status', 0)
            ->find();
        if ($exists) {
            return json(['code' => 1, 'msg' => '您已举报过该内容，请勿重复举报']);
        }

        $now = time();
        Db::name('post_report')->insert([
            'post_id'     => $postId,
            'comment_id'  => $commentId,
            'user_id'     => $uid,
            'reason'      => $reason,
            'content'     => $content,
            'status'      => 0,
            'create_time' => $now,
        ]);

        // 被举报对象举报计数 +1
        if ($commentId > 0) {
            Db::name('post_comment')->where('id', $commentId)->inc('report_count')->update();
        } else {
            Db::name('post')->where('id', $postId)->inc('report_count')->update();
        }

        return json(['code' => 0, 'msg' => '举报成功，我们会尽快处理']);
    }

    /**
     * 获取客户端真实 IP：优先 REMOTE_ADDR，若为内网/回环（反代/CDN 场景）则回退代理头
     */
    private function clientIp(): string
    {
        $ip = trim((string) $this->request->server('REMOTE_ADDR', ''));
        if ('' === $ip || false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $xff = trim(explode(',', (string) $this->request->header('x-forwarded-for', ''))[0]);
            if ('' !== $xff && filter_var($xff, FILTER_VALIDATE_IP)) {
                return $xff;
            }
            $xr = trim((string) $this->request->header('x-real-ip', ''));
            if ('' !== $xr && filter_var($xr, FILTER_VALIDATE_IP)) {
                return $xr;
            }
        }
        return $ip;
    }

    /**
     * 当前用户（可选登录态）ID；未登录返回 0
     *
     * list / detail 为公开接口（仅签名，中间件不注入 deviceInfo），
     * 客户端可顺带携带 device_key，用于返回 is_liked 字段。
     */
    private function currentUserId(): int
    {
        $deviceKey = (string) $this->request->header('x-device-key', '');
        if ('' === $deviceKey) {
            $deviceKey = (string) $this->request->param('device_key', '');
        }
        if ('' === $deviceKey) {
            return 0;
        }
        $device = Db::name('user_device')
            ->where('device_key', $deviceKey)
            ->where('status', 1)
            ->field('user_id')
            ->find();
        return $device ? (int) $device['user_id'] : 0;
    }

    /**
     * 批量查询当前用户已点赞的帖子 ID 集合
     *
     * @param int   $uid     用户 ID（<=0 时直接返回空）
     * @param array $postIds 帖子 ID 列表
     * @return int[]
     */
    private static function likedPostIds(int $uid, array $postIds): array
    {
        if ($uid <= 0) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $postIds)));
        if (!$ids) {
            return [];
        }
        return array_map('intval', Db::name('post_like')
            ->where('user_id', $uid)
            ->whereIn('post_id', $ids)
            ->column('post_id'));
    }

    /**
     * 批量统计帖子点赞数（post_like 表）
     *
     * @param array $postIds 帖子 ID 列表
     * @return array [post_id => like_count]
     */
    private static function likeCountMap(array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $postIds)));
        $map = [];
        if (!$ids) {
            return $map;
        }
        $rows = Db::name('post_like')
            ->whereIn('post_id', $ids)
            ->field('post_id, COUNT(*) AS cnt')
            ->group('post_id')
            ->select()
            ->toArray();
        foreach ($rows as $r) {
            $map[(int) $r['post_id']] = (int) $r['cnt'];
        }
        return $map;
    }

    /**
     * 批量查询用户昵称/头像（实时联表 user，避免冗余）
     *
     * 走 UserInfoCache：Redis 缓存优先（TTL 10 分钟），无 Redis 时自动降级直查库。
     *
     * @param array $uids 用户 ID 列表
     * @return array [user_id => ['nickname' => string, 'avatar' => string]]
     */
    private static function userInfoMap(array $uids): array
    {
        return UserInfoCache::batch($uids);
    }

    /**
     * 解码 images 字段（JSON 数组），容错
     *
     * @return string[]
     */
    /**
     * 保存并压缩上传图片，返回访问 URL。
     *
     * @param \think\file\UploadedFile $file     上传文件
     * @param int    $uid      用户 ID
     * @param string $dir      相对根目录路径（如 public/static/post）
     * @param string $prefix   文件名前缀（p/c/u/s）
     * @param int    $maxBytes 压缩目标大小（> 该值则压缩）
     * @return string|null 访问 URL（失败返回 null）
     */
    private static function saveCompressedImage($file, int $uid, string $dir, string $prefix, int $maxBytes, int $maxDim = 0): ?string
    {
        $base = app()->getRootPath() . trim($dir, '/');
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }

        // 直接读取上传临时文件压缩（上传临时文件已落在本地磁盘，无需再 move；
        // 之前 move($tmp) 把文件路径当目录用，必然抛异常导致"图片处理失败"）
        $srcPath = $file->getPathname();
        if ($srcPath === '' || !is_file($srcPath)) {
            return null;
        }

        $bytes = ImageProcessor::compressToMaxBytes($srcPath, $maxBytes, $maxDim);
        if ($bytes === '') {
            return null;
        }

        // 按字节头判定扩展名
        $ext = self::detectExtByBytes($bytes) ?: 'jpg';
        $name = $prefix . $uid . '_' . date('YmdHis') . '_' . substr(md5((string) microtime(true) . rand()), 0, 8) . '.' . $ext;
        $target = $base . DIRECTORY_SEPARATOR . $name;
        $ok = @file_put_contents($target, $bytes);
        if ($ok === false) {
            return null;
        }
        @chmod($target, 0666 & ~umask());

        // public/static/post → /static/post/xxx
        return '/' . trim(str_replace('public', '', $dir), '/') . '/' . $name;
    }

    /** 根据字节头判定图片扩展名 */
    private static function detectExtByBytes(string $bytes): string
    {
        if (strlen($bytes) >= 8 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'png';
        }
        if (strlen($bytes) >= 6 && (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a')) {
            return 'gif';
        }
        if (strlen($bytes) >= 4 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xD8) {
            return 'jpg';
        }
        return '';
    }

    private static function decodeImages(string $images): array
    {
        if ('' === $images) {
            return [];
        }
        $arr = json_decode($images, true);
        return is_array($arr) ? array_values(array_map('strval', $arr)) : [];
    }

    /**
     * 格式化评论字段（类型转换 + 时间文案 + 点赞信息）
     */
    private static function formatComment(array $c, array $userMap, array $likeMap = [], array $likedIds = []): array
    {
        $c['id']               = (int) $c['id'];
        $c['post_id']          = (int) $c['post_id'];
        $c['user_id']          = (int) $c['user_id'];
        $c['parent_id']        = (int) $c['parent_id'];
        $c['reply_to_uid']     = (int) $c['reply_to_uid'];
        $c['report_count']     = (int) $c['report_count'];
        $c['like_count']       = $likeMap[(int) $c['id']] ?? 0;
        $c['is_liked']         = in_array((int) $c['id'], $likedIds, true);
        $c['create_time']      = (int) $c['create_time'];
        $c['create_time_text'] = $c['create_time'] ? date('Y-m-d H:i', $c['create_time']) : '';
        // 昵称/头像实时联表 user 查询（post_comment 已不存 nickname/avatar 冗余字段）
        $u = $userMap[(int) $c['user_id']] ?? null;
        $c['nickname'] = $u['nickname'] ?? '';
        $c['avatar']   = ($u['avatar'] ?? '') ?: '/static/admin/img/default-avatar.png';
        // 楼中楼回复目标昵称兜底：历史数据或目标已注销时可能为空，用联表结果补齐
        $c['reply_to_name'] = (string) ($c['reply_to_name'] ?? '');
        if ('' === $c['reply_to_name'] && (int) $c['reply_to_uid'] > 0) {
            $c['reply_to_name'] = ($userMap[(int) $c['reply_to_uid']]['nickname'] ?? '') ?: UserInfoCache::GHOST_NAME;
        }
        $c['location'] = (string) ($c['location'] ?? '');
        unset($c['ip']); // 不向客户端暴露原始 IP，仅返回归属地
        $c['images']   = self::decodeImages($c['images'] ?? '');
        return $c;
    }

    /**
     * 帖子点赞 / 取消点赞（切换，业务接口）
     * POST：post_id
     * 返回：is_liked、like_count
     */
    public function like()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $postId = (int) $this->request->post('post_id', 0);
        $post = Db::name('post')->where('id', $postId)->where('status', 1)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        // 不允许给自己的帖子点赞
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
     * 帖子收藏 / 取消收藏（切换，业务接口）
     * POST：post_id
     * 返回：is_favorited、favorite_count
     */
    public function favorite()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $postId = (int) $this->request->post('post_id', 0);
        $post = Db::name('post')->where('id', $postId)->where('status', 1)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        // 不允许收藏自己的帖子
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
     * 我的收藏列表（业务接口）
     *
     * 返回当前用户收藏的帖子，按收藏时间倒序，只取正常状态帖子。
     * 参数：page / page_size
     * 返回：list（字段同广场列表，含 is_favorited=true）、page
     */
    public function favorites()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->param('page_size', 10)));

        // 收藏表 join 帖子表：按收藏时间倒序，只取正常状态帖子
        $list = Db::name('post_favorite')
            ->alias('f')
            ->join('post p', 'p.id = f.post_id')
            ->where('f.user_id', $uid)
            ->where('p.status', 1)
            ->field('p.id,p.user_id,p.content,p.images,p.comment_count,p.create_time,p.location,p.favorite_count')
            ->order('f.create_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 批量统计点赞数（post_like 表），避免 N+1
        $likeMap = self::likeCountMap(array_column($list, 'id'));
        // 批量查询发帖人昵称/头像（实时联表 user）
        $userMap = self::userInfoMap(array_column($list, 'user_id'));
        // 当前用户已点赞的帖子 ID 集合，用于前端渲染空心/实心爱心
        $likedIds = self::likedPostIds($uid, array_column($list, 'id'));

        foreach ($list as &$item) {
            $u                     = $userMap[$item['user_id']] ?? null;
            $item['id']            = (int) $item['id'];
            $item['user_id']       = (int) $item['user_id'];
            $item['comment_count'] = (int) $item['comment_count'];
            $item['like_count']    = $likeMap[(int) $item['id']] ?? 0;
            $item['is_liked']      = in_array((int) $item['id'], $likedIds, true);
            $item['is_favorited']  = true; // 收藏列表里的必然是已收藏
            $item['favorite_count'] = (int) ($item['favorite_count'] ?? 0);
            $item['create_time']   = (int) $item['create_time'];
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i', $item['create_time']) : '';
            $item['images']        = self::decodeImages($item['images'] ?? '');
            $item['location']      = (string) ($item['location'] ?? '');
            $item['nickname']      = $u['nickname'] ?? '';
            $item['avatar']        = ($u['avatar'] ?? '') ?: '/static/admin/img/default-avatar.png';
            // 列表仅截取正文预览，避免传输过大
            $item['content']       = mb_substr((string) $item['content'], 0, 80);
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list, 'page' => $page]]);
    }

    /**
     * 我的动态列表（业务接口）
     *
     * 返回当前用户发布的帖子，按发布时间倒序，只取正常状态帖子。
     * 参数：page / page_size
     * 返回：list（字段同广场列表，is_favorited 恒 false——自己的帖子不能收藏）、page
     */
    public function myPosts()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->param('page_size', 10)));

        $list = Db::name('post')
            ->where('user_id', $uid)
            ->where('status', 1)
            ->field('id,user_id,content,images,comment_count,favorite_count,create_time,location')
            ->order('create_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 批量统计点赞数（post_like 表），避免 N+1
        $likeMap = self::likeCountMap(array_column($list, 'id'));
        // 批量查询发帖人昵称/头像（实时联表 user）
        $userMap = self::userInfoMap(array_column($list, 'user_id'));
        // 当前用户已点赞的帖子 ID 集合（自己的帖子不能给自己点赞，保留统一处理逻辑）
        $likedIds = self::likedPostIds($uid, array_column($list, 'id'));

        foreach ($list as &$item) {
            $u                     = $userMap[$item['user_id']] ?? null;
            $item['id']            = (int) $item['id'];
            $item['user_id']       = (int) $item['user_id'];
            $item['comment_count'] = (int) $item['comment_count'];
            $item['like_count']    = $likeMap[(int) $item['id']] ?? 0;
            $item['is_liked']      = in_array((int) $item['id'], $likedIds, true);
            $item['is_favorited']  = false; // 自己的帖子不能被收藏
            $item['favorite_count'] = (int) ($item['favorite_count'] ?? 0);
            $item['create_time']   = (int) $item['create_time'];
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i', $item['create_time']) : '';
            $item['images']        = self::decodeImages($item['images'] ?? '');
            $item['location']      = (string) ($item['location'] ?? '');
            $item['nickname']      = $u['nickname'] ?? '';
            $item['avatar']        = ($u['avatar'] ?? '') ?: '/static/admin/img/default-avatar.png';
            // 列表仅截取正文预览，避免传输过大
            $item['content']       = mb_substr((string) $item['content'], 0, 80);
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list, 'page' => $page]]);
    }

    /**
     * 评论点赞 / 取消点赞（切换，业务接口）
     * POST：comment_id
     * 返回：is_liked、like_count
     */
    public function commentLike()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $commentId = (int) $this->request->post('comment_id', 0);
        $comment = Db::name('post_comment')->where('id', $commentId)->where('status', 1)->find();
        if (!$comment) {
            return json(['code' => 1, 'msg' => '评论不存在']);
        }
        // 不允许给自己的评论点赞
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
     * 删除自己的帖子（业务接口）
     * POST：post_id
     * 只能删除自己发布的帖子；软删（status=0）。
     */
    public function deletePost()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $postId = (int) $this->request->post('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }
        // 只能删除自己的帖子
        if ((int) $post['user_id'] !== $uid) {
            return json(['code' => 1, 'msg' => '只能删除自己的帖子']);
        }
        if (0 === (int) $post['status']) {
            return json(['code' => 1, 'msg' => '帖子已删除']);
        }
        Db::name('post')->where('id', $postId)->update([
            'status'      => 0,
            'update_time' => time(),
        ]);
        // 级联软删该帖子的所有评论
        Db::name('post_comment')->where('post_id', $postId)->update(['status' => 0]);

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 删除自己的评论 / 楼中楼（业务接口）
     * POST：comment_id
     * 只能删除自己发布的评论；软删（status=0）。楼中楼删除其下的子评论。
     */
    public function deleteComment()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $commentId = (int) $this->request->post('comment_id', 0);
        if ($commentId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $comment = Db::name('post_comment')->where('id', $commentId)->find();
        if (!$comment) {
            return json(['code' => 1, 'msg' => '评论不存在']);
        }
        // 只能删除自己的评论
        if ((int) $comment['user_id'] !== $uid) {
            return json(['code' => 1, 'msg' => '只能删除自己的评论']);
        }
        if (0 === (int) $comment['status']) {
            return json(['code' => 1, 'msg' => '评论已删除']);
        }

        $postId = (int) $comment['post_id'];

        Db::name('post_comment')->where('id', $commentId)->update(['status' => 0]);
        // 删除的评论数：楼中楼也算评论。删顶层评论级联删其楼中楼，数量含楼中楼。
        $decCount = 1;
        if (0 === (int) $comment['parent_id']) {
            $childCount = (int) Db::name('post_comment')->where('parent_id', $commentId)->count();
            Db::name('post_comment')->where('parent_id', $commentId)->update(['status' => 0]);
            $decCount += $childCount;
        }

        // 自动扣减帖子评论数（评论数含楼中楼，与 comment() 累加口径一致），避免为负
        if ($decCount > 0) {
            Db::name('post')->where('id', $postId)->dec('comment_count', $decCount)->update();
        }

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 批量统计评论点赞数（comment_like 表）
     *
     * @param array $commentIds 评论 ID 列表
     * @return array [comment_id => like_count]
     */
    private static function commentLikeCountMap(array $commentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $commentIds)));
        $map = [];
        if (!$ids) {
            return $map;
        }
        $rows = Db::name('comment_like')
            ->whereIn('comment_id', $ids)
            ->field('comment_id, COUNT(*) AS cnt')
            ->group('comment_id')
            ->select()
            ->toArray();
        foreach ($rows as $r) {
            $map[(int) $r['comment_id']] = (int) $r['cnt'];
        }
        return $map;
    }

    /**
     * 批量查询当前用户已点赞的评论 ID 集合
     *
     * @param int   $uid        用户 ID（<=0 时直接返回空）
     * @param array $commentIds 评论 ID 列表
     * @return int[]
     */
    private static function likedCommentIds(int $uid, array $commentIds): array
    {
        if ($uid <= 0) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $commentIds)));
        if (!$ids) {
            return [];
        }
        return array_map('intval', Db::name('comment_like')
            ->where('user_id', $uid)
            ->whereIn('comment_id', $ids)
            ->column('comment_id'));
    }
}
