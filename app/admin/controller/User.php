<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\Pusher;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;
use app\common\Password;

class User extends BaseController
{
    /**
     * 默认头像（用户创建时自动赋值）
     */
    protected const DEFAULT_AVATAR = '/static/admin/img/default-avatar.png';

    /**
     * 用户管理页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 用户列表（分页 + 搜索）
     */
    public function getList()
    {
        $page   = max(1, (int) $this->request->param('page', 1));
        $limit  = min(100, max(1, (int) $this->request->param('limit', 10)));
        $kw     = trim((string) $this->request->param('keyword', ''));
        $status = $this->request->param('status', '');
        // 排序：field/order（点击表头排序）
        $sortField  = trim((string) $this->request->param('field', 'id'));
        $sortOrder  = strtolower((string) $this->request->param('order', 'asc'));
        $allowSort  = ['id', 'phone', 'nickname', 'status', 'register_time', 'last_login_time'];
        if (!in_array($sortField, $allowSort, true)) $sortField = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'asc';

        // 用闭包构建查询条件，keyword 的 OR 用括号分组与 status 的 AND 隔离
        $buildWhere = function ($q) use ($kw, $status) {
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('phone', "%{$kw}%")
                        ->whereOr('nickname', 'like', "%{$kw}%")
                        ->whereOr('id', 'like', "%{$kw}%");
                });
            }
            if ('' !== $status) {
                $q->where('status', (int) $status);
            }
        };

        $query = Db::name('user')->where($buildWhere);
        $total = $query->count();

        $list = Db::name('user')
            ->where($buildWhere)
            ->order($sortField, $sortOrder)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        // 在线用户集合（该用户任一设备长连接在线即视为在线）
        $onlineUserIds = [];
        try {
            $onlineUserIds = Db::name('user_device')
                ->where('online_status', 1)
                ->column('user_id');
        } catch (\Throwable $e) {
            $onlineUserIds = [];
        }
        $onlineUserIds = array_flip(array_map('intval', $onlineUserIds));

        foreach ($list as &$item) {
            $item['register_time']   = $item['register_time'] ? date('Y-m-d H:i:s', (int) $item['register_time']) : '-';
            $item['last_login_time'] = $item['last_login_time'] ? date('Y-m-d H:i:s', (int) $item['last_login_time']) : '-';
            $item['ban_time']        = $item['ban_time'] ? date('Y-m-d H:i:s', (int) $item['ban_time']) : '-';
            $item['status_text']     = $this->statusText((int) $item['status']);
            // 在线状态：1在线 0离线（实时，由 Swoole 长连接维护）
            $item['online'] = isset($onlineUserIds[(int) $item['id']]) ? 1 : 0;
            unset($item['password'], $item['login_token']);

            // 关联钱包数据（双轨制货币：人民币 + 熊爪）
            $item['wallet'] = $this->getWalletInfo((int) $item['id']);
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
     * 后台创建用户
     */
    public function add()
    {
        $nickname = trim((string) $this->request->post('nickname', ''));
        $password = (string) $this->request->post('password', '');
        $phone    = trim((string) $this->request->post('phone', ''));

        if ('' === $nickname) return json(['code' => 1, 'msg' => '昵称不能为空']);
        if ('' === $phone) return json(['code' => 1, 'msg' => '手机号不能为空']);
        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) return json(['code' => 1, 'msg' => '手机号格式不正确']);
        if (strlen($password) < 6) return json(['code' => 1, 'msg' => '密码长度至少6位']);
        if (Db::name('user')->where('phone', $phone)->find()) return json(['code' => 1, 'msg' => '该手机号已注册']);

        $now = time();
        $userId = Db::name('user')->insertGetId([
            'nickname'      => $nickname,
            'password'      => Password::encrypt($password),
            'phone'         => $phone,
            'avatar'        => self::DEFAULT_AVATAR,
            'status'        => 1,
            'register_time' => $now,
            'update_time'   => $now,
        ]);

        // 同步创建钱包记录
        Db::name('wallet')->insert([
            'uid'         => $userId,
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->log('user_create', '后台创建用户');

        return json(['code' => 0, 'msg' => '创建成功']);
    }

    /**
     * 批量创建用户（机器人/种子账号专用）
     * POST：prefix（昵称前缀）、count（数量 1-50）、password（统一密码，可空则默认 123456）
     *
     * 手机号自动生成：`1` + 随机 10 位（后台创建不受真实手机号限制）。
     * 昵称 = prefix + 序号（如 小龙虾01、小龙虾02）。
     */
    public function batchAdd()
    {
        $prefix   = trim((string) $this->request->post('prefix', ''));
        $count    = (int) $this->request->post('count', 0);
        $password = (string) $this->request->post('password', '');

        if ('' === $prefix) return json(['code' => 1, 'msg' => '请填写昵称前缀']);
        if ($count < 1 || $count > 50) return json(['code' => 1, 'msg' => '批量数量 1-50']);
        if (strlen($password) > 0 && strlen($password) < 6) return json(['code' => 1, 'msg' => '密码至少6位']);
        if ('' === $password) $password = '123456';

        // 生成手机号：从 10000000001 起始递增，保证格式正确（11 位）且不与现有手机号冲突。
        // 冲突检测基于"当前已存在的手机号"，从起点递增找到连续空闲号段。
        $now     = time();
        $created = 0;

        // 找出已占用的手机号集合（从 10000000001 起的区间，最多取 10 万个足够覆盖）
        $used = [];
        $rows = Db::name('user')
            ->where('phone', '>=', '10000000001')
            ->where('phone', '<=', '10000000100')
            ->field('phone')
            ->select()
            ->toArray();
        foreach ($rows as $r) {
            $used[$r['phone']] = true;
        }

        // 起始手机号：从 10000000001 开始找第一个空闲号（不在已用集合中）
        $base = 10000000001;
        while (isset($used[(string) $base])) {
            $base++;
        }

        for ($i = 1; $i <= $count; $i++) {
            $nickname = $prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            // 跳过已被占用的号（并发/其他渠道注册）
            while (isset($used[(string) $base])) {
                $base++;
            }
            $phone = (string) $base;
            $used[$phone] = true;
            $base++;

            $userId = Db::name('user')->insertGetId([
                'nickname'      => $nickname,
                'password'      => Password::encrypt($password),
                'phone'         => $phone,
                'avatar'        => self::DEFAULT_AVATAR,
                'status'        => 1,
                'register_time' => $now,
                'update_time'   => $now,
            ]);
            // 同步创建钱包记录
            Db::name('wallet')->insert([
                'uid'         => $userId,
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $created++;
        }

        $this->log('user_batch_add', '批量创建用户 prefix=' . $prefix . ' 数量=' . $created);

        return json(['code' => $created > 0 ? 0 : 1, 'msg' => $created > 0 ? "成功创建 {$created} 个用户" : '创建失败']);
    }

    /**
     * 编辑用户资料（不含密码，不含头像，头像固定为默认值）
     */
    public function edit()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) return json(['code' => 1, 'msg' => '参数错误']);

        $user = Db::name('user')->where('id', $id)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);

        $nickname = trim((string) $this->request->post('nickname', ''));

        if ('' === $nickname) return json(['code' => 1, 'msg' => '昵称不能为空']);

        // 手机号是登录标识，编辑时不可修改，保留原值
        Db::name('user')->where('id', $id)->update([
            'nickname'    => $nickname,
            'update_time' => time(),
        ]);

        $this->log('user_edit', '后台编辑用户');

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 封禁用户
     */
    public function ban()
    {
        $id = (int) $this->request->post('id', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if (!$id) return json(['code' => 1, 'msg' => '参数错误']);
        $user = Db::name('user')->where('id', $id)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);
        if (2 === (int) $user['status']) return json(['code' => 1, 'msg' => '该用户已被封禁']);

        // 封禁同时失效其登录 token
        Db::name('user')->where('id', $id)->update([
            'status'      => 2,
            'ban_time'    => time(),
            'ban_reason'  => $reason,
            'login_token' => '',
            'update_time' => time(),
        ]);

        $this->log('user_ban', $reason);

        // 实时推送：强制该用户所有在线设备下线
        $this->forceLogoutAllDevices($id, '账号已被封禁');

        return json(['code' => 0, 'msg' => '封禁成功']);
    }

    /**
     * 解封用户
     */
    public function unban()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) return json(['code' => 1, 'msg' => '参数错误']);
        $user = Db::name('user')->where('id', $id)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);
        if (2 !== (int) $user['status']) return json(['code' => 1, 'msg' => '该用户未被封禁']);

        Db::name('user')->where('id', $id)->update([
            'status'      => 1,
            'ban_time'    => 0,
            'ban_reason'  => '',
            'update_time' => time(),
        ]);

        // 解封同时恢复钱包状态
        Db::name('wallet')->where('uid', $id)->update([
            'status'      => 1,
            'update_time' => time(),
        ]);

        $this->log('user_unban', '解封用户');

        return json(['code' => 0, 'msg' => '解封成功']);
    }

    /**
     * 注销用户（需当前管理员输入密码确认）
     */
    public function delete()
    {
        $id      = (int) $this->request->post('id', 0);
        $adminPwd = (string) $this->request->post('admin_password', '');

        if (!$id) return json(['code' => 1, 'msg' => '参数错误']);
        if ('' === $adminPwd) return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);

        // 校验当前管理员密码
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);

        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        $user = Db::name('user')->where('id', $id)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);
        if (0 === (int) $user['status']) return json(['code' => 1, 'msg' => '该用户已注销']);

        // 注销：标记状态为 0，清空 token
        Db::name('user')->where('id', $id)->update([
            'status'      => 0,
            'login_token' => '',
            'update_time' => time(),
        ]);

        // 同步标注钱包为注销状态
        Db::name('wallet')->where('uid', $id)->update([
            'status'      => 0,
            'update_time' => time(),
        ]);

        $this->log('user_delete', '管理员注销用户：' . $admin['username']);

        // 实时推送：强制该用户所有在线设备下线
        $this->forceLogoutAllDevices($id, '账号已注销');

        return json(['code' => 0, 'msg' => '注销成功']);
    }

    /**
     * 获取钱包信息（双轨制货币：人民币 cny + 熊爪 paw）
     */
    protected function getWalletInfo(int $userId): array
    {
        $wallet = Db::name('wallet')->where('uid', $userId)->find();
        if (!$wallet) {
            return [
                'status' => 0,
                'balances' => [
                    'cny' => '0.00',
                    'paw' => '0',
                ],
            ];
        }

        return [
            'status'   => (int) $wallet['status'],
            'balances' => [
                'cny' => (string) $wallet['balance_cny'],
                'paw' => (string) $wallet['balance_paw'],
            ],
        ];
    }

    /**
     * 强制指定用户的所有在线设备下线（实时推送 force_logout）
     * @param int    $userId 用户ID
     * @param string $reason 下线原因
     */
    protected function forceLogoutAllDevices(int $userId, string $reason)
    {
        try {
            $devices = Db::name('user_device')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($devices as $dev) {
            Pusher::forceLogout(
                (int) $dev['user_id'],
                (string) $dev['platform'],
                (string) $dev['device_id'],
                $reason
            );
        }
    }

    /**
     * 状态文案
     */
    protected function statusText(int $status): string
    {
        switch ($status) {
            case 0:
                return '已注销';
            case 2:
                return '已封禁';
            default:
                return '正常';
        }
    }

    /**
     * 写管理员操作日志（admin_log）
     * @param string $adminAction 管理员日志 action（对应 AdminLog::ACTION_MAP）
     * @param string $remark      备注
     */
    protected function log(string $adminAction, string $remark = '')
    {
        $operator = (string) Session::get('admin_username', 'admin');
        $adminId  = (int) Session::get('admin_id', 0);
        AdminLog::write($adminId, $operator, $adminAction, $this->request->ip(), $remark);
    }
}
