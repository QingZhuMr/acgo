<?php
declare (strict_types = 1);

namespace app\admin\middleware;

use Closure;
use think\facade\Db;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 后台登录鉴权中间件
 * 1. 未登录：重定向到登录页
 * 2. 异地登录（token 不匹配）：清 session、记日志、重定向登录页并提示
 * 权限等级等动态数据从数据库实时读取，session 仅保存 admin_id 用于鉴权
 */
class CheckLogin
{
    /**
     * 免登录的路径（应用内相对路径，统一小写）
     */
    protected array $freePaths = [
        'login/index',
        'login/dologin',
        'login/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->pathinfo(), '/');
        $path = strtolower($path ?: 'index/index');

        // 免登录路径直接放行
        foreach ($this->freePaths as $free) {
            if ($path === $free || str_ends_with($path, $free) || str_ends_with($path, '/' . $free)) {
                return $next($request);
            }
        }

        // 未登录
        if (!Session::has('admin_id')) {
            return $this->redirectLogin();
        }

        // 根据 admin_id 从数据库实时查询
        $adminId = (int) Session::get('admin_id');
        $admin   = Db::name('admin')->where('id', $adminId)->find();

        // 账号不存在或已禁用
        if (!$admin || 0 == $admin['status']) {
            Session::clear();
            return $this->redirectLogin();
        }

        // 异地登录检测：比对 session 里的 token 与数据库最新 token
        $sessionToken = (string) Session::get('admin_login_token', '');
        $dbToken      = (string) $admin['login_token'];

        if ('' !== $sessionToken && $sessionToken !== $dbToken) {
            // 记录被挤下线日志
            Db::name('admin_log')->insert([
                'admin_id'    => $adminId,
                'username'    => $admin['username'],
                'action'      => 'kick',
                'login_ip'    => $request->ip(),
                'user_agent'  => mb_substr($request->server('HTTP_USER_AGENT', ''), 0, 490),
                'create_time' => time(),
            ]);
            Session::clear();
            return $this->redirectLogin('kick');
        }

        return $next($request);
    }

    /**
     * 重定向到登录页
     */
    protected function redirectLogin(string $reason = ''): Response
    {
        $url = '/login/index';
        if ('kick' === $reason) {
            $url = '/login/index?kick=1';
        }
        return redirect($url);
    }
}
