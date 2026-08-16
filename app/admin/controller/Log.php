<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Log extends BaseController
{
    /**
     * 日志管理首页（默认显示管理员日志）
     */
    public function index()
    {
        return redirect('/log/adminLog');
    }

    /**
     * 管理员日志页
     */
    public function adminLog()
    {
        return View::fetch();
    }

    /**
     * 用户日志页
     */
    public function userLog()
    {
        return View::fetch();
    }

    /**
     * 管理员日志列表（分页 + 搜索）
     */
    public function adminLogList()
    {
        $page   = max(1, (int) $this->request->param('page', 1));
        $limit  = min(100, max(1, (int) $this->request->param('limit', 20)));
        $kw     = trim((string) $this->request->param('keyword', ''));
        $action = trim((string) $this->request->param('action', ''));
        // 排序：field/order（点击表头排序）
        $sortField = trim((string) $this->request->param('field', 'id'));
        $sortOrder = strtolower((string) $this->request->param('order', 'desc'));
        $allowSort = ['id', 'username', 'action', 'login_ip', 'create_time'];
        if (!in_array($sortField, $allowSort, true)) $sortField = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'desc';

        $buildWhere = function ($q) use ($kw, $action) {
            if ('' !== $kw) {
                $q->where('username', 'like', "%{$kw}%");
            }
            if ('' !== $action) {
                $q->where('action', $action);
            }
        };

        $query = Db::name('admin_log')->where($buildWhere);
        $total = $query->count();

        $list = Db::name('admin_log')
            ->where($buildWhere)
            ->order($sortField, $sortOrder)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['action_text']      = AdminLog::text($item['action']);
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $list]);
    }

    /**
     * 用户日志列表（分页 + 搜索）
     */
    public function userLogList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));
        $kw    = trim((string) $this->request->param('keyword', ''));
        $action = trim((string) $this->request->param('action', ''));
        // 排序：field/order（点击表头排序）
        $sortField = trim((string) $this->request->param('field', 'id'));
        $sortOrder = strtolower((string) $this->request->param('order', 'desc'));
        $allowSort = ['id', 'phone', 'action', 'operator', 'ip', 'create_time'];
        if (!in_array($sortField, $allowSort, true)) $sortField = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'desc';

        $buildWhere = function ($q) use ($kw, $action) {
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('phone', "%{$kw}%")
                        ->whereOr('operator', 'like', "%{$kw}%");
                });
            }
            if ('' !== $action) {
                $q->where('action', $action);
            }
        };

        $query = Db::name('user_log')->where($buildWhere);
        $total = $query->count();

        $list = Db::name('user_log')
            ->where($buildWhere)
            ->order($sortField, $sortOrder)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        $actionMap = [
            'recharge'        => '充值',
            'withdraw'        => '提现',
            'change_password' => '修改密码',
        ];
        foreach ($list as &$item) {
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['action_text']      = isset($actionMap[$item['action']]) ? $actionMap[$item['action']] : $item['action'];
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $list]);
    }

    /**
     * 图形验证码（GD 生成 PNG 图片，值存 session）
     * GET /log/captcha
     */
    public function captcha()
    {
        $width  = 120;
        $height = 40;
        $chars  = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
        $code   = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // 存储验证码（小写，便于校验）
        Session::set('log_captcha', strtolower($code));
        Session::save();

        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor($width, $height);
            // 背景
            $bg = imagecolorallocate($img, 245, 246, 250);
            imagefill($img, 0, 0, $bg);
            // 干扰点
            for ($i = 0; $i < 80; $i++) {
                $c = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
                imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
            }
            // 干扰线
            for ($i = 0; $i < 3; $i++) {
                $c = imagecolorallocate($img, random_int(180, 230), random_int(180, 230), random_int(180, 230));
                imageline($img, random_int(0, $width / 3), random_int(0, $height), random_int($width * 2 / 3, $width), random_int(0, $height), $c);
            }
            // 文字
            $font = 5; // 内置字体
            for ($i = 0; $i < 4; $i++) {
                $c = imagecolorallocate($img, random_int(20, 90), random_int(20, 90), random_int(20, 90));
                $x = 8 + $i * 28;
                $y = random_int(8, 16);
                imagechar($img, $font, $x, $y, $code[$i], $c);
            }
            header('Content-Type: image/png');
            imagepng($img);
            // PHP 8+ 由 GC 自动回收，无需 imagedestroy
        } else {
            // 无 GD 时降级：纯文本提示
            header('Content-Type: text/plain');
            echo 'captcha:' . $code;
        }
        exit;
    }

    /**
     * 校验验证码
     */
    protected function verifyCaptcha(string $input): bool
    {
        $saved = (string) Session::get('log_captcha', '');
        return '' !== $saved && strtolower(trim($input)) === $saved;
    }

    /**
     * 清空管理员日志（需图形验证码）
     * POST /log/clearAdminLog  { captcha }
     */
    public function clearAdminLog()
    {
        return $this->doClear('admin_log', 'admin_log_clear', '清空管理员日志');
    }

    /**
     * 清空用户日志（需图形验证码）
     * POST /log/clearUserLog  { captcha }
     */
    public function clearUserLog()
    {
        return $this->doClear('user_log', 'user_log_clear', '清空用户日志');
    }

    /**
     * 通用清空日志逻辑
     */
    protected function doClear(string $table, string $action, string $desc)
    {
        if (!$this->request->isPost()) {
            return json(['code' => 1, 'msg' => '请求方式错误']);
        }

        $adminId = (int) Session::get('admin_id', 0);
        if (!$adminId) {
            return json(['code' => 1, 'msg' => '登录已失效']);
        }

        $captcha = (string) $this->request->post('captcha', '');
        if (!$this->verifyCaptcha($captcha)) {
            return json(['code' => 1, 'msg' => '验证码错误']);
        }

        // 删除日志
        Db::name($table)->delete(true);

        // 清空验证码，防止重复使用
        Session::delete('log_captcha');
        Session::save();

        // 记录清空操作（写入管理员日志）
        $adminName = (string) Session::get('admin_username', '');
        if ('' === $adminName) {
            $row = Db::name('admin')->where('id', $adminId)->value('username');
            $adminName = (string) $row;
        }
        AdminLog::write($adminId, $adminName, $action, $this->request->ip(), $desc);

        return json(['code' => 0, 'msg' => $desc . '成功']);
    }
}
