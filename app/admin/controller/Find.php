<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\Password;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

/**
 * 星球寻人 · 后台运营
 *
 * 路由（自动绑定）：
 *   GET  /admin/find/grant     赠送免费次数页面
 *   POST /admin/find/doGrant   赠送免费次数（密码二次验证）
 *   GET  /admin/find/userFree  查询某账号在各星球的免费次数余额
 */
class Find extends BaseController
{
    /**
     * 赠送免费次数页面
     */
    public function grant()
    {
        $planets = Db::name('planet')
            ->field('id, name')
            ->order('sort asc, id desc')
            ->select()
            ->toArray();
        View::assign('planets', $planets);
        return View::fetch();
    }

    /**
     * 赠送免费次数（按 账号+星球，永久累计，可多次赠送叠加）
     * POST：target(账号ID或手机号)、planet_id、count(>0)、admin_password
     */
    public function doGrant()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        }

        $adminPwd = (string) $this->request->post('admin_password', '');
        if ($adminPwd === '') {
            return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);
        }
        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        $target   = trim((string) $this->request->post('target', ''));
        $planetId = (int) $this->request->post('planet_id', 0);
        $count    = (int) $this->request->post('count', 0);

        if ($target === '' || $planetId <= 0 || $count <= 0) {
            return json(['code' => 1, 'msg' => '参数错误（账号/手机号、星球、次数均需有效）']);
        }
        if ($count > 100000) {
            return json(['code' => 1, 'msg' => '单次赠送次数过大']);
        }

        // 解析目标账号
        if (is_numeric($target)) {
            $user = Db::name('user')->where('id', (int) $target)->find();
        } else {
            $user = Db::name('user')->where('phone', $target)->find();
        }
        if (!$user) {
            return json(['code' => 1, 'msg' => '账号/手机号不存在']);
        }
        $uid = (int) $user['id'];

        $planet = Db::name('planet')->where('id', $planetId)->find();
        if (!$planet) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        // 累计赠送（永久免费次数，叠加）
        $row = Db::name('user_find_free')
            ->where('user_id', $uid)
            ->where('planet_id', $planetId)
            ->find();
        if ($row) {
            Db::name('user_find_free')
                ->where('id', (int) $row['id'])
                ->inc('free_total', $count)
                ->update(['update_time' => time()]);
        } else {
            Db::name('user_find_free')->insert([
                'user_id'     => $uid,
                'planet_id'   => $planetId,
                'free_total'  => $count,
                'free_used'   => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $newRow    = Db::name('user_find_free')
            ->where('user_id', $uid)
            ->where('planet_id', $planetId)
            ->find();
        $remaining = max(0, (int) $newRow['free_total'] - (int) $newRow['free_used']);

        $this->log('find_grant_free',
            '赠送寻人免费次数 uid=' . $uid . ' planet=' . $planetId . ' +' . $count . ' 剩余=' . $remaining);

        return json([
            'code' => 0,
            'msg'  => '已为「' . $user['nickname'] . '」赠送 ' . $count . ' 次（当前剩余 ' . $remaining . ' 次）',
            'data' => ['uid' => $uid, 'remaining' => $remaining],
        ]);
    }

    /**
     * 查询某账号在各星球的免费次数余额
     * GET：target(账号ID或手机号)
     */
    public function userFree()
    {
        $target = trim((string) $this->request->get('target', ''));
        if ($target === '') {
            return json(['code' => 1, 'msg' => '请填写账号/手机号']);
        }
        if (is_numeric($target)) {
            $user = Db::name('user')->where('id', (int) $target)->find();
        } else {
            $user = Db::name('user')->where('phone', $target)->find();
        }
        if (!$user) {
            return json(['code' => 1, 'msg' => '账号/手机号不存在']);
        }
        $uid = (int) $user['id'];

        $planets = Db::name('planet')
            ->field('id, name')
            ->order('sort asc, id desc')
            ->select()
            ->toArray();
        $rows = Db::name('user_find_free')->where('user_id', $uid)->select()->toArray();
        $map  = [];
        foreach ($rows as $r) {
            $map[(int) $r['planet_id']] = $r;
        }

        $list = [];
        foreach ($planets as $p) {
            $r      = $map[(int) $p['id']] ?? null;
            $total  = $r ? (int) $r['free_total'] : 0;
            $used   = $r ? (int) $r['free_used'] : 0;
            $list[] = [
                'planet_id'      => (int) $p['id'],
                'planet_name'    => $p['name'],
                'free_total'     => $total,
                'free_used'      => $used,
                'free_remaining' => max(0, $total - $used),
            ];
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'uid'      => $uid,
                'nickname' => $user['nickname'],
                'list'     => $list,
            ],
        ]);
    }

    /**
     * 写管理员操作日志（admin_log）
     */
    protected function log(string $adminAction, string $remark = ''): void
    {
        $operator = (string) Session::get('admin_username', 'admin');
        $adminId  = (int) Session::get('admin_id', 0);
        AdminLog::write($adminId, $operator, $adminAction, $this->request->ip(), $remark);
    }
}
