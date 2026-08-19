<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\ImageProcessor;
use app\common\Pusher;
use app\common\UserInfoCache;
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
            // 配对关系与状态（寻人配对功能）
            $item['pairing'] = $this->getPairingInfo((int) $item['id']);
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
     * 获取用户配对关系与状态（寻人配对功能）
     * status: 0=未寻找 1=寻找中 2=已配对(或曾配对)
     */
    protected function getPairingInfo(int $userId): array
    {
        $find = Db::name('user_find')
            ->where('user_id', $userId)
            ->order('id', 'DESC')
            ->find();
        if (!$find) {
            return ['status' => 0, 'status_text' => '未寻找', 'partner' => ''];
        }
        $status = (int) $find['status'];
        if ($status === 0) {
            return ['status' => 1, 'status_text' => '寻找中', 'partner' => ''];
        }
        $partner = '';
        if (!empty($find['matched_uid'])) {
            $p = Db::name('user')->where('id', (int) $find['matched_uid'])->field('nickname')->find();
            $partner = $p ? (string) $p['nickname'] : '';
        }
        return [
            'status'      => 2,
            'status_text' => $status === 1 ? '已配对' : '曾配对(已停止)',
            'partner'     => $partner,
        ];
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
     * 编辑用户资料（不含密码；支持修改头像：avatar 传相对路径如 /static/avatar/u1_xxx.jpg）
     */
    public function edit()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) return json(['code' => 1, 'msg' => '参数错误']);

        $user = Db::name('user')->where('id', $id)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);

        $nicknameRaw = $this->request->post('nickname', '');
        $avatarRaw   = $this->request->post('avatar', '');

        // 防御：layui 表单字段偶发序列化成数组（如 avatar[]），直接报友好错误，避免 PHP 转数组 500
        if (!is_string($nicknameRaw) || !is_string($avatarRaw)) {
            return json(['code' => 1, 'msg' => '提交参数格式错误，请重试']);
        }

        $nickname = trim($nicknameRaw);
        $avatar   = trim($avatarRaw);

        if ('' === $nickname) return json(['code' => 1, 'msg' => '昵称不能为空']);

        $data = [
            'nickname'    => $nickname,
            'update_time' => time(),
        ];

        // 头像：仅允许静态目录相对路径（防止任意 URL / XSS）
        if ('' !== $avatar) {
            if (strpos($avatar, '/static/avatar/') !== 0) {
                return json(['code' => 1, 'msg' => '头像路径不合法']);
            }
            $data['avatar'] = $avatar;
        }

        Db::name('user')->where('id', $id)->update($data);

        // 昵称/头像变化立即失效缓存
        UserInfoCache::forget($id);

        $this->log('user_edit', '后台编辑用户');

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 上传用户头像（后台管理）
     *
     * POST multipart：id + avatar（图片文件）
     * 校验：image 类型、≤5MB；压缩（最大边 512、超 256KB 压缩），
     * 存 public/static/avatar/u{id}_{ts}_{rand}.{ext}；返回相对路径（不落库，
     * 由 edit() 保存到用户表，避免上传即生效、取消却已改库）。
     */
    public function uploadAvatar()
    {
        // id 支持 URL 参数或 POST 字段（前端 layui upload 通过 URL 传 id）
        $id = (int) $this->request->param('id', 0);
        if (!$id || !Db::name('user')->where('id', $id)->find()) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        // 兼容 avatar / file 两种字段名（layui upload 默认字段名为 file）
        $file = $this->request->file('avatar');
        if (!$file) {
            $file = $this->request->file('file');
        }
        if (!$file) {
            return json(['code' => 1, 'msg' => '未接收到图片']);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        $mime = strtolower((string) $file->getMime());
        if (!in_array($mime, $allowed, true)) {
            return json(['code' => 1, 'msg' => '仅支持 jpg/png/gif/webp/bmp 图片']);
        }
        $size = $file->getSize();
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => '图片不能超过5MB']);
        }

        $srcPath = $file->getPathname();
        if ('' === $srcPath || !is_file($srcPath)) {
            return json(['code' => 1, 'msg' => '头像上传失败']);
        }

        // 压缩（超 256KB 才压，GIF 动画保留；头像最大边 512）
        $bytes = ImageProcessor::compressToMaxBytes($srcPath, 256 * 1024, 512);
        if ('' === $bytes) {
            return json(['code' => 1, 'msg' => '头像处理失败']);
        }

        $ext = self::detectExtByBytes($bytes) ?: 'jpg';
        $name = 'u' . $id . '_' . date('YmdHis') . '_' . substr(md5((string) microtime(true)), 0, 8) . '.' . $ext;
        $dir = app()->getRootPath() . 'public/static/avatar';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $target = $dir . DIRECTORY_SEPARATOR . $name;
        $ok = @file_put_contents($target, $bytes);
        if (false === $ok) {
            return json(['code' => 1, 'msg' => '头像上传失败']);
        }
        @chmod($target, 0666 & ~umask());

        return json([
            'code' => 0,
            'msg'  => '上传成功',
            'data' => ['avatar' => '/static/avatar/' . $name],
        ]);
    }

    /** 根据字节头判定图片扩展名 */
    private static function detectExtByBytes(string $bytes): string
    {
        if (strlen($bytes) >= 8 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'png';
        }
        if (strlen($bytes) >= 6 && (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a')) {
            return 'gif';
        }
        if (strlen($bytes) >= 4 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xD8) {
            return 'jpg';
        }
        return '';
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
