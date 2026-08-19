<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;
use app\common\Password;

/**
 * 通知管理（覆盖已有通知功能）
 *  - notification 表：用户收到的评论 / 回复通知
 */
class Notification extends BaseController
{
    public function index()
    {
        return view('notification/index');
    }

    public function getList()
    {
        $uid     = (int) $this->request->param('uid', 0);
        $fromUid = (int) $this->request->param('from_uid', 0);
        $type    = $this->request->param('type', '');
        $isRead  = $this->request->param('is_read', '');

        $query = Db::name('notification')->alias('n')
            ->join('user u', 'u.id = n.user_id', 'LEFT')
            ->join('user u2', 'u2.id = n.from_uid', 'LEFT');

        if ($uid > 0) {
            $query->where('n.user_id', $uid);
        }
        if ($fromUid > 0) {
            $query->where('n.from_uid', $fromUid);
        }
        if ($type !== '') {
            $query->where('n.type', (int) $type);
        }
        if ($isRead !== '') {
            $query->where('n.is_read', (int) $isRead);
        }

        $count = $query->count();
        $list  = $query->field('n.*, u.nickname as user_nickname, u2.nickname as from_nickname')
            ->order('n.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    /** 标记已读 */
    public function read()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('notification')->where('id', $id)->update(['is_read' => 1, 'update_time' => time()]);
        $this->log('notice_read', 'notice_id=' . $id);
        return json(['code' => 0, 'msg' => '已标记已读']);
    }

    /** 按用户标记全部已读 */
    public function readAll()
    {
        $uid = (int) $this->request->post('uid', 0);
        if ($uid <= 0) {
            return json(['code' => 1, 'msg' => '请填写用户ID']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('notification')->where('user_id', $uid)->where('is_read', 0)->update(['is_read' => 1, 'update_time' => time()]);
        $this->log('notice_read_all', 'uid=' . $uid);
        return json(['code' => 0, 'msg' => '已标记全部已读']);
    }

    /** 删除通知（物理删除） */
    public function del()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('notification')->where('id', $id)->delete();
        $this->log('notice_del', 'notice_id=' . $id);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    protected function checkPwd(): bool
    {
        $plain = (string) $this->request->post('admin_password', '');
        $admin = Db::name('admin')->where('id', Session::get('admin_id'))->find();
        if (!$admin) {
            return false;
        }
        return Password::verify($plain, $admin['password']);
    }

    protected function log(string $action, string $target): void
    {
        $admin = Db::name('admin')->where('id', Session::get('admin_id'))->find();
        Db::name('admin_log')->insert([
            'admin_id'    => Session::get('admin_id'),
            'admin_name'  => $admin['username'] ?? '',
            'action'      => $action,
            'target'      => $target,
            'ip'          => $this->request->ip(),
            'create_time' => time(),
        ]);
    }
}
