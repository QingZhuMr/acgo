<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;
use app\common\Password;

class Login extends BaseController
{
    /**
     * 登录页
     */
    public function index()
    {
        // 已登录则直接跳转后台主页
        if (Session::has('admin_id')) {
            return redirect('/index/index');
        }
        $appName = (string) Db::name('config')->where('name', 'app_name')->value('value');
        if ('' === $appName) {
            $appName = 'ACGO 后台';
        }
        View::assign('app_name', $appName);
        return View::fetch();
    }

    /**
     * 处理登录
     */
    public function doLogin()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if ('' === $username || '' === $password) {
            return json(['code' => 1, 'msg' => '请输入账号和密码']);
        }

        // 查询管理员
        $admin = Db::name('admin')->where('username', $username)->find();

        if (!$admin) {
            $this->writeLog(0, $username, 'login', '账号不存在');
            return json(['code' => 1, 'msg' => '账号或密码错误']);
        }

        // 校验密码
        if (!Password::verify($password, $admin['password'])) {
            $this->writeLog($admin['id'], $username, 'login', '密码错误');
            return json(['code' => 1, 'msg' => '账号或密码错误']);
        }

        // 账号状态
        if (0 == $admin['status']) {
            $this->writeLog($admin['id'], $username, 'login', '账号已禁用');
            return json(['code' => 1, 'msg' => '账号已被禁用']);
        }

        // 生成新的登录 token（用于异地登录检测）
        $newToken = Password::makeToken();
        $loginIp  = $this->request->ip();

        // 更新 admin 表的 token 和登录信息
        Db::name('admin')->where('id', $admin['id'])->update([
            'login_token'     => $newToken,
            'last_login_time' => time(),
            'last_login_ip'   => $loginIp,
            'update_time'     => time(),
        ]);

        // 写入 session（只存关键鉴权信息，权限等级等从数据库实时查）
        Session::set('admin_id', $admin['id']);
        Session::set('admin_username', $username);
        Session::set('admin_login_token', $newToken);
        Session::save();

        // 记录登录日志
        $this->writeLog($admin['id'], $username, 'login', '登录成功');

        return json(['code' => 0, 'msg' => '登录成功', 'url' => '/index/index']);
    }

    /**
     * 登出
     */
    public function logout()
    {
        $adminId = (int) Session::get('admin_id', 0);

        if ($adminId) {
            // username 可能因 session 配置丢失，从 admin 表回查保证日志完整
            $adminName = (string) Session::get('admin_username', '');
            if ('' === $adminName) {
                $row = Db::name('admin')->where('id', $adminId)->value('username');
                $adminName = (string) $row;
            }
            $this->writeLog($adminId, $adminName, 'logout', '退出登录');
        }

        Session::clear();
        return redirect('/login/index');
    }

    /**
     * 写入登录日志（统一走 AdminLog）
     */
    protected function writeLog(int $adminId, string $username, string $action, string $remark = '')
    {
        AdminLog::write($adminId, $username, $action, $this->request->ip(), $remark);
    }
}
