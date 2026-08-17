<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\Password;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

/**
 * 广场（帖子）后台管理
 * 功能：帖子列表、预览（含评论）、删除帖子、增加热度
 */
class Post extends BaseController
{
    protected const STATUS_NAME = [
        0 => '已删除',
        1 => '正常',
        2 => '待审核',
    ];

    protected const STATUS_BADGE = [
        0 => 'badge-gray',
        1 => 'badge-green',
        2 => 'badge-warm',
    ];

    /**
     * 广场帖子管理页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 帖子列表（分页 + 搜索 + 排序）
     */
    public function getList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));
        $kw    = trim((string) $this->request->param('keyword', ''));
        $status = $this->request->param('status', '');

        $sortField = trim((string) $this->request->param('field', 'id'));
        $sortOrder = strtolower((string) $this->request->param('order', 'desc'));
        $allowSort = ['id', 'user_id', 'heat', 'comment_count', 'report_count', 'create_time'];
        if (!in_array($sortField, $allowSort, true)) {
            $sortField = 'id';
        }
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $buildWhere = function ($q) use ($kw, $status) {
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('p.content', "%{$kw}%")
                        ->whereOr('u.nickname', 'like', "%{$kw}%");
                });
            }
            if ('' !== $status) {
                $q->where('p.status', (int) $status);
            }
        };

        // 联表 user 实时取发帖人昵称/头像（post 表已不再冗余存 nickname/avatar）
        $total = Db::name('post')->alias('p')
            ->join('user u', 'p.user_id = u.id', 'LEFT')
            ->where($buildWhere)->count();

        $list = Db::name('post')->alias('p')
            ->join('user u', 'p.user_id = u.id', 'LEFT')
            ->field('p.*, u.nickname AS nickname, u.avatar AS avatar')
            ->where($buildWhere)
            ->order('p.' . $sortField, $sortOrder)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        $statusName  = self::STATUS_NAME;
        $statusBadge = self::STATUS_BADGE;
        $likeMap = self::likeCountMap(array_column($list, 'id'));
        foreach ($list as &$item) {
            $item['id']              = (int) $item['id'];
            $item['user_id']         = (int) $item['user_id'];
            $item['heat']            = (int) $item['heat'];
            $item['comment_count']   = (int) $item['comment_count'];
            $item['like_count']      = $likeMap[(int) $item['id']] ?? 0;
            $item['report_count']    = (int) $item['report_count'];
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['status_text']     = $statusName[(int) $item['status']] ?? '未知';
            $item['status_badge']    = $statusBadge[(int) $item['status']] ?? 'badge-gray';
            $item['content_preview'] = mb_substr((string) $item['content'], 0, 60);
            $item['avatar']          = $item['avatar'] ?: '/static/admin/img/default-avatar.png';
            $imgArr = $item['images'] ? json_decode((string) $item['images'], true) : [];
            $item['image_count']     = is_array($imgArr) ? count($imgArr) : 0;
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $list]);
    }

    /**
     * 帖子预览详情（含评论，后台弹框预览用）
     */
    public function detail()
    {
        $postId = (int) $this->request->param('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        $post = Db::name('post')->alias('p')
            ->join('user u', 'p.user_id = u.id', 'LEFT')
            ->field('p.*, u.nickname AS nickname, u.avatar AS avatar')
            ->where('p.id', $postId)
            ->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        $post['id']               = (int) $post['id'];
        $post['user_id']          = (int) $post['user_id'];
        $post['heat']             = (int) $post['heat'];
        $post['comment_count']    = (int) $post['comment_count'];
        $post['like_count']       = self::likeCountMap([$postId])[$postId] ?? 0;
        $post['report_count']     = (int) $post['report_count'];
        $post['create_time_text'] = $post['create_time'] ? date('Y-m-d H:i:s', (int) $post['create_time']) : '-';
        $post['status_text']      = self::STATUS_NAME[(int) $post['status']] ?? '未知';
        $post['avatar']           = $post['avatar'] ?: '/static/admin/img/default-avatar.png';
        $post['content_html']     = $this->renderStickers((string) $post['content']);
        $imgArr = $post['images'] ? json_decode((string) $post['images'], true) : [];
        $post['images']           = is_array($imgArr) ? array_values(array_map('strval', $imgArr)) : [];

        // 联表 user 实时取评论人昵称/头像（post_comment 已不存 nickname/avatar 冗余字段）
        $allComments = Db::name('post_comment')->alias('c')
            ->join('user u', 'c.user_id = u.id', 'LEFT')
            ->field('c.*, u.nickname AS nickname, u.avatar AS avatar')
            ->where('c.post_id', $postId)
            ->order('c.create_time', 'asc')
            ->select()
            ->toArray();
        $formatted = [];
        foreach ($allComments as $c) {
            $c['id']               = (int) $c['id'];
            $c['parent_id']        = (int) $c['parent_id'];
            $c['create_time_text'] = $c['create_time'] ? date('Y-m-d H:i:s', (int) $c['create_time']) : '-';
            $c['status_text']      = 1 === (int) $c['status'] ? '正常' : '已删除';
            $c['avatar']           = $c['avatar'] ?: '/static/admin/img/default-avatar.png';
            // 表情包渲染为图片（预览可见）
            $c['content_html']     = $this->renderStickers((string) $c['content']);
            $formatted[] = $c;
        }

        // 按 parent_id 分组成树形（顶层评论 + 子评论），便于前端楼中楼展示
        $comments    = [];
        $childrenMap = [];
        foreach ($formatted as $c) {
            if (0 === $c['parent_id']) {
                $c['children'] = [];
                $comments[] = $c;
            } else {
                $childrenMap[$c['parent_id']][] = $c;
            }
        }
        foreach ($comments as &$top) {
            $top['children'] = $childrenMap[$top['id']] ?? [];
        }
        unset($top);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['post' => $post, 'comments' => $comments]]);
    }

    /**
     * 删除帖子（软删，需管理员密码二次验证）
     */
    public function delete()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        }
        if (0 === (int) $admin['status']) {
            return json(['code' => 1, 'msg' => '管理员已被禁用']);
        }

        $postId   = (int) $this->request->post('post_id', 0);
        $adminPwd = (string) $this->request->post('admin_password', '');
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '请选择要删除的帖子']);
        }
        if ('' === $adminPwd) {
            return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);
        }
        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        Db::name('post')->where('id', $postId)->update(['status' => 0, 'update_time' => time()]);

        $remark = '删除帖子 #' . $postId . '（' . mb_substr((string) $post['content'], 0, 30) . '）';
        AdminLog::write($adminId, $admin['username'], 'post_delete', $this->request->ip(), $remark);

        return json(['code' => 0, 'msg' => '帖子已删除']);
    }

    /**
     * 删除评论 / 楼中楼（软删，需管理员密码二次验证）
     * POST：comment_id、admin_password
     * 删除后自动扣减帖子评论数（含楼中楼）；删顶层评论级联删其楼中楼。
     */
    public function deleteComment()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        }
        if (0 === (int) $admin['status']) {
            return json(['code' => 1, 'msg' => '管理员已被禁用']);
        }

        $commentId = (int) $this->request->post('comment_id', 0);
        $adminPwd  = (string) $this->request->post('admin_password', '');
        if ($commentId <= 0) {
            return json(['code' => 1, 'msg' => '请选择要删除的评论']);
        }
        if ('' === $adminPwd) {
            return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);
        }
        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        $comment = Db::name('post_comment')->where('id', $commentId)->find();
        if (!$comment) {
            return json(['code' => 1, 'msg' => '评论不存在']);
        }
        if (0 === (int) $comment['status']) {
            return json(['code' => 1, 'msg' => '评论已删除']);
        }

        $postId = (int) $comment['post_id'];

        Db::name('post_comment')->where('id', $commentId)->update(['status' => 0]);
        // 删顶层评论级联删其楼中楼，评论数（含楼中楼）一并扣减
        $decCount = 1;
        if (0 === (int) $comment['parent_id']) {
            $childCount = (int) Db::name('post_comment')->where('parent_id', $commentId)->count();
            Db::name('post_comment')->where('parent_id', $commentId)->update(['status' => 0]);
            $decCount += $childCount;
        }
        if ($decCount > 0) {
            Db::name('post')->where('id', $postId)->dec('comment_count', $decCount)->update();
        }

        $remark = '删除评论 #' . $commentId . '（' . mb_substr((string) $comment['content'], 0, 30) . '）';
        AdminLog::write($adminId, $admin['username'], 'comment_delete', $this->request->ip(), $remark);

        return json(['code' => 0, 'msg' => '评论已删除']);
    }

    /**
     * 批量统计帖子点赞数（post_like 表）
     *
     * @param array $postIds
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
     * 增加帖子热度（影响随机推荐权重，需登录，无需二次密码）
     */
    public function addHeat()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        }

        $postId = (int) $this->request->post('post_id', 0);
        $heat   = (int) $this->request->post('heat', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => '请选择帖子']);
        }
        if ($heat <= 0) {
            return json(['code' => 1, 'msg' => '热度增量必须为正整数']);
        }
        if ($heat > 100000) {
            return json(['code' => 1, 'msg' => '单次热度增量过大']);
        }

        $post = Db::name('post')->where('id', $postId)->find();
        if (!$post) {
            return json(['code' => 1, 'msg' => '帖子不存在']);
        }

        Db::name('post')->where('id', $postId)->inc('heat', $heat)->update(['update_time' => time()]);

        $remark = '帖子 #' . $postId . ' 增加热度 +' . $heat;
        AdminLog::write($adminId, $admin['username'], 'post_heat', $this->request->ip(), $remark);

        return json(['code' => 0, 'msg' => '热度已增加 +' . $heat]);
    }
}
