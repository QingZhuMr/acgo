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
            return $this->redirectLogin($request);
        }

        // 根据 admin_id 从数据库实时查询
        $adminId = (int) Session::get('admin_id');
        $admin   = Db::name('admin')->where('id', $adminId)->find();

        // 账号不存在或已禁用
        if (!$admin || 0 == $admin['status']) {
            Session::clear();
            return $this->redirectLogin($request);
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
            return $this->redirectLogin($request, 'kick');
        }

        // 会话有效期控制：登录 6 小时有效，每次操作续期 +1 小时
        // （精确控制用 admin_expire_at；File 驱动 mtime + expire 21600 兜底防异常）
        $now = time();
        $expireAt = (int) Session::get('admin_expire_at', 0);
        if ($expireAt > 0 && $now >= $expireAt) {
            // 超过有效期未续期：清会话踢回登录页
            Session::clear();
            return $this->redirectLogin($request);
        }

        // 续期：距上次续期 ≥60 秒才算一次操作，过期点 +1 小时
        // ThinkPHP 8 Session 不依赖 PHP 原生 handler，必须显式 save() 才落盘。
        $last = (int) Session::get('admin_last_active', 0);
        if ($now - $last >= 60) {
            Session::set('admin_last_active', $now);
            Session::set('admin_expire_at', $now + 3600);
            Session::save();
        }

        return $next($request);
    }

    /**
     * 重定向到登录页
     */
    protected function redirectLogin(Request $request, string $reason = ''): Response
    {
        // AJAX / JSON 请求：返回未登录 JSON，由前端全局拦截器（admin-core.js）触发
        // 顶层窗口整页跳转登录页（解决「掉线后登录框出现在 iframe 内容区」的问题）
        if ($request->isAjax() || $request->isJson()
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            $msg = $reason === 'kick' ? '账号已在其他设备登录，请重新登录' : '登录已失效，请重新登录';
            return json(['code' => -9, 'msg' => $msg, 'need_login' => true]);
        }
        $url = '/login/index';
        if ('kick' === $reason) {
            $url = '/login/index?kick=1';
        }
        return redirect($url);
    }
}
