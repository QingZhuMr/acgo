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
 * 举报工单处理
 * 功能：举报列表、处理举报（忽略 / 删除被举报内容）
 */
class Report extends BaseController
{
    protected const STATUS_NAME = [
        0 => '待处理',
        1 => '已处理',
        2 => '已删除',
    ];

    protected const STATUS_BADGE = [
        0 => 'badge-red',
        1 => 'badge-green',
        2 => 'badge-gray',
    ];

    protected const REASON_NAME = [
        'spam'      => '垃圾广告',
        'porn'      => '色情低俗',
        'abuse'     => '人身攻击',
        'ad'        => '恶意营销',
        'infringe'  => '侵犯权益',
        'other'     => '其他',
    ];

    /**
     * 举报处理页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 举报列表（分页 + 状态筛选 + 关键词）
     */
    public function getList()
    {
        $page   = max(1, (int) $this->request->param('page', 1));
        $limit  = min(100, max(1, (int) $this->request->param('limit', 20)));
        $status = $this->request->param('status', '');
        $kw     = trim((string) $this->request->param('keyword', ''));

        $buildWhere = function ($q) use ($status, $kw) {
            if ('' !== $status) {
                $q->where('r.status', (int) $status);
            }
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('r.content', "%{$kw}%")
                        ->whereOr('u.nickname', 'like', "%{$kw}%");
                });
            }
        };

        $total = Db::name('post_report')->alias('r')
            ->join('user u', 'r.user_id = u.id', 'LEFT')
            ->where($buildWhere)
            ->count();

        $list = Db::name('post_report')->alias('r')
            ->join('user u', 'r.user_id = u.id', 'LEFT')
            ->join('admin a', 'r.handle_admin_id = a.id', 'LEFT')
            ->field('r.*, u.nickname AS reporter_nickname, a.username AS handler_name')
            ->where($buildWhere)
            ->order('r.id', 'desc')
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        $statusName  = self::STATUS_NAME;
        $statusBadge = self::STATUS_BADGE;
        $reasonName  = self::REASON_NAME;
        $postIds = array_unique(array_filter(array_column($list, 'post_id')));
        $commentIds = array_unique(array_filter(array_column($list, 'comment_id')));

        // 被举报对象信息（帖子作者昵称/内容预览；评论内容预览）
        $postInfo = $postIds ? Db::name('post')->alias('p')
            ->join('user pu', 'p.user_id = pu.id', 'LEFT')
            ->whereIn('p.id', $postIds)
            ->column('pu.nickname AS nickname, p.content AS content, p.status AS status', 'p.id') : [];
        $commentInfo = $commentIds ? Db::name('post_comment')->alias('c')
            ->join('user cu', 'c.user_id = cu.id', 'LEFT')
            ->whereIn('c.id', $commentIds)
            ->column('cu.nickname AS nickname, c.content AS content, c.status AS status', 'c.id') : [];

        foreach ($list as &$item) {
            $item['id']            = (int) $item['id'];
            $item['post_id']       = (int) $item['post_id'];
            $item['comment_id']    = (int) $item['comment_id'];
            $item['user_id']       = (int) $item['user_id'];
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['handle_time_text'] = $item['handle_time'] ? date('Y-m-d H:i:s', (int) $item['handle_time']) : '-';
            $item['handler_name']     = $item['handler_name'] ?: '-';
            $item['status_text']   = $statusName[(int) $item['status']] ?? '未知';
            $item['status_badge']  = $statusBadge[(int) $item['status']] ?? 'badge-gray';
            $item['reason_text']   = $reasonName[(string) $item['reason']] ?? $item['reason'];
            $item['reporter_nickname'] = $item['reporter_nickname'] ?: '未知';
            $item['content_preview']   = mb_substr((string) $item['content'], 0, 60);
            $item['post_type']         = $item['comment_id'] > 0 ? 'comment' : 'post';

            // 被举报对象作者与内容
            if ($item['comment_id'] > 0) {
                $t = $commentInfo[$item['comment_id']] ?? null;
                $item['target_nickname']    = $t['nickname'] ?? '未知';
                $item['target_content']     = $t['content'] ?? '';
                $item['target_content_html'] = $this->renderStickers((string) ($t['content'] ?? ''));
                $item['target_status']      = isset($t['status']) ? (int) $t['status'] : 1;
            } else {
                $t = $postInfo[$item['post_id']] ?? null;
                $item['target_nickname']    = $t['nickname'] ?? '未知';
                $item['target_content']     = $t['content'] ?? '';
                $item['target_content_html'] = $this->renderStickers((string) ($t['content'] ?? ''));
                $item['target_status']      = isset($t['status']) ? (int) $t['status'] : 1;
            }
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $list]);
    }

    /**
     * 处理举报工单
     * POST：report_id、action（ignore=忽略/标记已处理，delete=删除被举报内容）、remark（备注，可选）
     */
    public function handle()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        }
        if (0 === (int) $admin['status']) {
            return json(['code' => 1, 'msg' => '管理员已被禁用']);
        }

        $reportId = (int) $this->request->post('report_id', 0);
        $action   = (string) $this->request->post('action', '');
        $remark   = trim((string) $this->request->post('remark', ''));
        if ($reportId <= 0) {
            return json(['code' => 1, 'msg' => '请选择举报工单']);
        }
        if (!in_array($action, ['ignore', 'delete'], true)) {
            return json(['code' => 1, 'msg' => '请选择处理方式']);
        }
        if (mb_strlen($remark) > 255) {
            return json(['code' => 1, 'msg' => '处理备注最长255字']);
        }

        $report = Db::name('post_report')->where('id', $reportId)->find();
        if (!$report) {
            return json(['code' => 1, 'msg' => '举报工单不存在']);
        }
        if (0 !== (int) $report['status']) {
            return json(['code' => 1, 'msg' => '该工单已处理']);
        }

        $now = time();

        // 忽略：仅标记已处理
        if ('ignore' === $action) {
            Db::name('post_report')->where('id', $reportId)->update([
                'status'          => 1,
                'handle_admin_id' => $adminId,
                'handle_time'     => $now,
                'handle_remark'   => $remark,
            ]);
            $logRemark = '忽略举报 #' . $reportId . '（举报帖子 #' . $report['post_id'] . '）' . ($remark ? '：' . $remark : '');
            AdminLog::write($adminId, $admin['username'], 'report_handle', $this->request->ip(), $logRemark);
            return json(['code' => 0, 'msg' => '已标记为已处理']);
        }

        // 删除：需管理员密码二次验证（参考 Post::delete）
        $adminPwd = (string) $this->request->post('admin_password', '');
        if ('' === $adminPwd) {
            return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);
        }
        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        // 删除：软删被举报对象
        if ($report['comment_id'] > 0) {
            Db::name('post_comment')->where('id', (int) $report['comment_id'])->update(['status' => 0]);
            // 若删的是顶层评论，级联删楼中楼
            $c = Db::name('post_comment')->where('id', (int) $report['comment_id'])->find();
            if ($c && 0 === (int) $c['parent_id']) {
                Db::name('post_comment')->where('parent_id', (int) $report['comment_id'])->update(['status' => 0]);
            }
            $logRemark = '举报 #' . $reportId . ' 删除评论 #' . $report['comment_id'] . ($remark ? '：' . $remark : '');
        } else {
            Db::name('post')->where('id', (int) $report['post_id'])->update(['status' => 0, 'update_time' => $now]);
            // 级联删该帖评论
            Db::name('post_comment')->where('post_id', (int) $report['post_id'])->update(['status' => 0]);
            $logRemark = '举报 #' . $reportId . ' 删除帖子 #' . $report['post_id'] . ($remark ? '：' . $remark : '');
        }

        Db::name('post_report')->where('id', $reportId)->update([
            'status'          => 2,
            'handle_admin_id' => $adminId,
            'handle_time'     => $now,
            'handle_remark'   => $remark,
        ]);
        AdminLog::write($adminId, $admin['username'], 'report_handle', $this->request->ip(), $logRemark);

        return json(['code' => 0, 'msg' => '已删除违规内容并关闭工单']);
    }
}
