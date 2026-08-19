<?php
declare(strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;
use app\common\Password;

/**
 * 社交关系管理（配对 / 绑定 / 黑名单 / 关注）
 *
 * 覆盖已有功能：
 *  - user_find      星球寻找状态（0寻找中/1已配对/2已停止）
 *  - user_bind      配对绑定关系（1绑定中/0已解除）
 *  - user_block     黑名单（拉黑）关系
 *  - user_follow    关注（好友=互相关注）关系
 */
class Social extends BaseController
{
    public function index()
    {
        return view('social/index');
    }

    // ===================== 列表接口 =====================

    /** 寻找记录 */
    public function findList()
    {
        $uid      = (int) $this->request->param('uid', 0);
        $planetId = (int) $this->request->param('planet_id', 0);
        $status   = $this->request->param('status', '');

        $query = Db::name('user_find')->alias('f')
            ->join('user u', 'u.id = f.user_id', 'LEFT')
            ->join('user u2', 'u2.id = f.matched_uid', 'LEFT')
            ->join('planet p', 'p.id = f.planet_id', 'LEFT');

        if ($uid > 0) {
            $query->where('f.user_id', $uid);
        }
        if ($planetId > 0) {
            $query->where('f.planet_id', $planetId);
        }
        if ($status !== '' && $status !== null) {
            $query->where('f.status', (int) $status);
        }

        $count = $query->count();
        $list  = $query->field('f.*, u.nickname as user_nickname, u2.nickname as matched_nickname, p.name as planet_name')
            ->order('f.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        foreach ($list->items() as &$item) {
            $item['target_text'] = $this->targetText($item['target']);
        }
        unset($item);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    /** 绑定关系 */
    public function bindList()
    {
        $uid = (int) $this->request->param('uid', 0);

        $query = Db::name('user_bind')->alias('b')
            ->join('user ua', 'ua.id = b.user_a', 'LEFT')
            ->join('user ub', 'ub.id = b.user_b', 'LEFT')
            ->join('planet p', 'p.id = b.planet_id', 'LEFT');

        if ($uid > 0) {
            $query->where(function ($q) use ($uid) {
                $q->where('b.user_a', $uid)->whereOr('b.user_b', $uid);
            });
        }

        $count = $query->count();
        $list  = $query->field('b.*, ua.nickname as a_nick, ub.nickname as b_nick, p.name as planet_name')
            ->order('b.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    /** 黑名单 */
    public function blockList()
    {
        $uid = (int) $this->request->param('uid', 0);

        $query = Db::name('user_block')->alias('k')
            ->join('user ua', 'ua.id = k.blocker_id', 'LEFT')
            ->join('user ub', 'ub.id = k.blocked_id', 'LEFT');

        if ($uid > 0) {
            $query->where(function ($q) use ($uid) {
                $q->where('k.blocker_id', $uid)->whereOr('k.blocked_id', $uid);
            });
        }

        $count = $query->count();
        $list  = $query->field('k.*, ua.nickname as blocker_nick, ub.nickname as blocked_nick')
            ->order('k.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    /** 关注关系 */
    public function followList()
    {
        $uid = (int) $this->request->param('uid', 0);

        $query = Db::name('user_follow')->alias('f')
            ->join('user ua', 'ua.id = f.follower_id', 'LEFT')
            ->join('user ub', 'ub.id = f.following_id', 'LEFT');

        if ($uid > 0) {
            $query->where(function ($q) use ($uid) {
                $q->where('f.follower_id', $uid)->whereOr('f.following_id', $uid);
            });
        }

        $count = $query->count();
        $list  = $query->field('f.*, ua.nickname as follower_nick, ub.nickname as following_nick')
            ->order('f.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    // ===================== 操作接口 =====================

    /** 停止寻找（status 0 -> 2） */
    public function stopFind()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        $row = Db::name('user_find')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '记录不存在']);
        }
        if ((int) $row['status'] !== 0) {
            return json(['code' => 1, 'msg' => '仅寻找中的任务可停止']);
        }
        Db::name('user_find')->where('id', $id)->update(['status' => 2, 'stop_time' => time()]);
        $this->log('social_stop_find', 'find_id=' . $id);
        return json(['code' => 0, 'msg' => '已停止寻找']);
    }

    /** 删除寻找记录（物理删除） */
    public function delFind()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('user_find')->where('id', $id)->delete();
        $this->log('social_del_find', 'find_id=' . $id);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /** 解除绑定（status 1 -> 0） */
    public function unbind()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        $row = Db::name('user_bind')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '记录不存在']);
        }
        if ((int) $row['status'] !== 1) {
            return json(['code' => 1, 'msg' => '该绑定已解除']);
        }
        Db::name('user_bind')->where('id', $id)->update(['status' => 0, 'unbind_time' => time()]);
        $this->log('social_unbind', 'bind_id=' . $id);
        return json(['code' => 0, 'msg' => '已解除绑定']);
    }

    /** 删除绑定记录（物理删除） */
    public function delBind()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('user_bind')->where('id', $id)->delete();
        $this->log('social_del_bind', 'bind_id=' . $id);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /** 解除黑名单（删除关系） */
    public function delBlock()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('user_block')->where('id', $id)->delete();
        $this->log('social_del_block', 'block_id=' . $id);
        return json(['code' => 0, 'msg' => '已解除黑名单']);
    }

    /** 取消关注（删除关注关系） */
    public function delFollow()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('user_follow')->where('id', $id)->delete();
        $this->log('social_del_follow', 'follow_id=' . $id);
        return json(['code' => 0, 'msg' => '已取消关注']);
    }

    // ===================== 内部方法 =====================

    protected function targetText($json): string
    {
        $t = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($t) || empty($t)) {
            return '';
        }
        $gender = [0 => '不限', 1 => '男', 2 => '女'];
        $parts  = [];
        if (!empty($t['gender'])) {
            $parts[] = '性别=' . ($gender[(int) $t['gender']] ?? $t['gender']);
        }
        if (!empty($t['age_min']) || !empty($t['age_max'])) {
            $parts[] = '年龄=' . ((int) ($t['age_min'] ?? 18)) . '-' . ((int) ($t['age_max'] ?? 99));
        }
        if (!empty($t['income_range'])) {
            $parts[] = '收入=' . $t['income_range'];
        }
        if (!empty($t['personality'])) {
            $parts[] = '性格=' . $t['personality'];
        }
        if (!empty($t['city'])) {
            $parts[] = '城市=' . $t['city'];
        }
        if (!empty($t['face_score'])) {
            $parts[] = '颜值≥' . $t['face_score'];
        }
        if (!empty($t['edu_level'])) {
            $parts[] = '学历=' . $t['edu_level'];
        }
        if (!empty($t['same_city'])) {
            $parts[] = '同城';
        }
        return implode('，', $parts);
    }

    /** 校验管理员密码（业务操作二次确认） */
    protected function checkPwd(): bool
    {
        $plain  = (string) $this->request->post('admin_password', '');
        $admin  = Db::name('admin')->where('id', Session::get('admin_id'))->find();
        if (!$admin) {
            return false;
        }
        return Password::verify($plain, $admin['password']);
    }

    /** 管理员操作日志 */
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
