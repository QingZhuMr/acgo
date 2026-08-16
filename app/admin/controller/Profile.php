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
 * 管理员个人资料
 * - 主题偏好（白天/黑夜/跟随系统）由前端 localStorage 管理，即时生效
 * - 重置密码由本控制器处理
 */
class Profile extends BaseController
{
    public function index()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return redirect('/login/index');
        }
        View::assign([
            'admin_id' => $admin['id'],
            'username' => $admin['username'],
            'nickname' => $admin['nickname'],
            'level'    => (int) $admin['level'],
        ]);
        return View::fetch();
    }

    /**
     * 重置当前管理员密码
     * POST /profile/changePassword  { old_password, new_password, confirm_password }
     */
    public function changePassword()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 1, 'msg' => '请求方式错误']);
        }

        $adminId = (int) Session::get('admin_id', 0);
        $adminName = (string) Session::get('admin_username', '');
        if (!$adminId) {
            return json(['code' => 1, 'msg' => '登录已失效']);
        }

        $admin = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) {
            return json(['code' => 1, 'msg' => '账号不存在']);
        }

        // 优先从 session 取 username；session 缺失时从 admin 表回查，保证日志完整
        if ('' === $adminName) {
            $adminName = (string) $admin['username'];
        }

        $oldPassword = (string) $this->request->post('old_password', '');
        $newPassword = (string) $this->request->post('new_password', '');
        $confirm     = (string) $this->request->post('confirm_password', '');

        // 校验旧密码
        if (!Password::verify($oldPassword, $admin['password'])) {
            AdminLog::write($adminId, $adminName, 'admin_reset_pwd', $this->request->ip(), '原密码校验失败');
            return json(['code' => 1, 'msg' => '原密码不正确']);
        }

        // 校验新密码
        if (mb_strlen($newPassword) < 6) {
            return json(['code' => 1, 'msg' => '新密码至少 6 位']);
        }
        if ($newPassword !== $confirm) {
            return json(['code' => 1, 'msg' => '两次输入的新密码不一致']);
        }
        if ($newPassword === $oldPassword) {
            return json(['code' => 1, 'msg' => '新密码不能与原密码相同']);
        }

        // 更新密码（重新登录，使旧 token 失效）
        $newToken = Password::makeToken();
        Db::name('admin')->where('id', $adminId)->update([
            'password'    => Password::encrypt($newPassword),
            'login_token' => $newToken,
            'update_time' => time(),
        ]);

        // 更新当前 session token，保持登录
        Session::set('admin_login_token', $newToken);
        Session::save();

        AdminLog::write($adminId, $adminName, 'admin_reset_pwd', $this->request->ip(), '修改个人密码');

        return json(['code' => 0, 'msg' => '密码修改成功']);
    }
}
