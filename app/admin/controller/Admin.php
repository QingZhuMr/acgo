<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;
use app\common\Password;

class Admin extends BaseController
{
    /**
     * 管理员管理页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 管理员列表（含分页、搜索）
     */
    public function getList()
    {
        $page   = max(1, (int) $this->request->param('page', 1));
        $limit  = min(100, max(1, (int) $this->request->param('limit', 10)));
        $kw     = trim((string) $this->request->param('keyword', ''));
        $level  = (int) $this->request->param('level', 0);
        $status = $this->request->param('status', '');
        // 排序：field/order（点击表头排序）
        $sortField = trim((string) $this->request->param('field', 'id'));
        $sortOrder = strtolower((string) $this->request->param('order', 'asc'));
        $allowSort = ['id', 'username', 'nickname', 'level', 'status', 'last_login_time', 'last_login_ip', 'create_time'];
        if (!in_array($sortField, $allowSort, true)) $sortField = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'asc';

        // 统一构建查询条件（count 与 list 共用，保证搜索一致）
        $buildWhere = function ($q) use ($kw, $level, $status) {
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('username', "%{$kw}%")
                        ->whereOr('nickname', 'like', "%{$kw}%");
                });
            }
            if ($level > 0) {
                $q->where('level', $level);
            }
            if ('' !== $status) {
                $q->where('status', (int) $status);
            }
        };

        $total = Db::name('admin')->where($buildWhere)->count();

        $list = Db::name('admin')
            ->field('id, username, nickname, level, status, login_token, last_login_time, last_login_ip, create_time, update_time')
            ->order($sortField, $sortOrder)
            ->where($buildWhere)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        // 补充字段：不返回密码
        foreach ($list as &$item) {
            $item['last_login_time'] = $item['last_login_time'] ? date('Y-m-d H:i:s', (int) $item['last_login_time']) : '-';
            $item['create_time']     = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['level_name']      = $this->levelName((int) $item['level']);
            $item['status_text']     = (int) $item['status'] === 1 ? '正常' : '禁用';
            unset($item['password']);
        }
        unset($item);

        return json([
            'code'  => 0,
            'msg'   => 'ok',
            'count' => $total,
            'data'  => $list,
        ]);
    }

    /**
     * 新增管理员
     */
    public function add()
    {
        $username = trim((string) $this->request->post('username', ''));
        $nickname = trim((string) $this->request->post('nickname', ''));
        $password = (string) $this->request->post('password', '');
        $level    = (int) $this->request->post('level', 1);
        $status   = (int) $this->request->post('status', 1);

        if ('' === $username) {
            return json(['code' => 1, 'msg' => '账号不能为空']);
        }
        if ('' === $nickname) {
            return json(['code' => 1, 'msg' => '昵称不能为空']);
        }
        if (strlen($password) < 6) {
            return json(['code' => 1, 'msg' => '密码长度至少6位']);
        }

        // 账号唯一性
        if (Db::name('admin')->where('username', $username)->find()) {
            return json(['code' => 1, 'msg' => '该账号已存在']);
        }

        Db::name('admin')->insert([
            'username'    => $username,
            'nickname'    => $nickname,
            'password'    => Password::encrypt($password),
            'level'       => $level,
            'status'      => $status,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->adminLog('admin_create', '新增管理员：' . $username);

        return json(['code' => 0, 'msg' => '新增成功']);
    }

    /**
     * 获取单个管理员（编辑回显，不含密码）
     */
    public function getOne()
    {
        $id = (int) $this->request->param('id', 0);
        $admin = Db::name('admin')->where('id', $id)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在']);
        }
        unset($admin['password'], $admin['login_token']);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $admin]);
    }

    /**
     * 编辑管理员（基本资料，不含密码）
     */
    public function edit()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $admin = Db::name('admin')->where('id', $id)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在']);
        }

        $nickname = trim((string) $this->request->post('nickname', ''));
        $level    = (int) $this->request->post('level', 1);
        $status   = (int) $this->request->post('status', 1);

        if ('' === $nickname) {
            return json(['code' => 1, 'msg' => '昵称不能为空']);
        }

        Db::name('admin')->where('id', $id)->update([
            'nickname'    => $nickname,
            'level'       => $level,
            'status'      => $status,
            'update_time' => time(),
        ]);

        $this->adminLog('admin_edit', '编辑管理员：' . $admin['username']);

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 删除管理员（不能删除自己，不能删除超管）
     */
    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $currentId = (int) Session::get('admin_id', 0);
        if ($id === $currentId) {
            return json(['code' => 1, 'msg' => '不能删除当前登录账号']);
        }

        $admin = Db::name('admin')->where('id', $id)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在']);
        }
        if (9 === (int) $admin['level']) {
            return json(['code' => 1, 'msg' => '超级管理员不允许删除']);
        }

        Db::name('admin')->where('id', $id)->delete();

        $this->adminLog('admin_delete', '删除管理员：' . $admin['username']);

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 重置密码（由管理员为他人重置新密码）
     */
    public function resetPassword()
    {
        $id = (int) $this->request->post('id', 0);
        $newPassword = (string) $this->request->post('password', '');

        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (strlen($newPassword) < 6) {
            return json(['code' => 1, 'msg' => '新密码长度至少6位']);
        }

        $admin = Db::name('admin')->where('id', $id)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在']);
        }

        // 重置密码会使其异地登录失效
        Db::name('admin')->where('id', $id)->update([
            'password'    => Password::encrypt($newPassword),
            'login_token' => Password::makeToken(),
            'update_time' => time(),
        ]);

        $this->adminLog('admin_reset_pwd', '重置管理员密码：' . $admin['username']);

        return json(['code' => 0, 'msg' => '密码重置成功，该账号需重新登录']);
    }

    /**
     * 修改自己的密码（需校验原密码）
     */
    public function changePassword()
    {
        $currentId = (int) Session::get('admin_id', 0);
        $oldPwd    = (string) $this->request->post('old_password', '');
        $newPwd    = (string) $this->request->post('new_password', '');

        if (strlen($newPwd) < 6) {
            return json(['code' => 1, 'msg' => '新密码长度至少6位']);
        }

        $admin = Db::name('admin')->where('id', $currentId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '管理员不存在']);
        }
        if (!Password::verify($oldPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '原密码错误']);
        }

        Db::name('admin')->where('id', $currentId)->update([
            'password'    => Password::encrypt($newPwd),
            'login_token' => Password::makeToken(),
            'update_time' => time(),
        ]);

        return json(['code' => 0, 'msg' => '密码修改成功，请重新登录']);
    }

    /**
     * 权限等级名称
     */
    protected function levelName(int $level): string
    {
        switch ($level) {
            case 9:
                return '超级管理员';
            case 2:
                return '管理员';
            default:
                return '普通管理员';
        }
    }

    /**
     * 写管理员操作日志
     */
    protected function adminLog(string $action, string $remark = '')
    {
        $adminId   = (int) Session::get('admin_id', 0);
        $adminName = (string) Session::get('admin_username', 'admin');
        AdminLog::write($adminId, $adminName, $action, $this->request->ip(), $remark);
    }
}
