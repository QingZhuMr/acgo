<?php
declare (strict_types = 1);

namespace app\api\controller;

use app\BaseController;
use app\common\Password;
use app\common\UserInfoCache;
use think\facade\Db;

/**
 * 前台用户接口（注册/登录）
 * 域名：读取 .env 的 DOMAIN_API
 */
class User extends BaseController
{
    /**
     * 默认头像（用户创建时自动赋值）
     */
    protected const DEFAULT_AVATAR = '/static/admin/img/default-avatar.png';

    /**
     * 用户注册
     */
    public function register()
    {
        $nickname = trim((string) $this->request->post('nickname', ''));
        $password = (string) $this->request->post('password', '');
        $phone    = trim((string) $this->request->post('phone', ''));
        $captcha  = trim((string) $this->request->post('captcha', ''));
        $captchaId = trim((string) $this->request->post('captcha_id', ''));

        // 校验
        // 图形验证码校验（防机器人注册）
        if ('' === $captcha || '' === $captchaId) {
            return json(['code' => 1, 'msg' => '请输入验证码']);
        }
        $vc = Db::name('verify_code')
            ->where('captcha_id', $captchaId)
            ->where('type', 'register')
            ->where('used', 0)
            ->find();
        if (!$vc) {
            return json(['code' => 1, 'msg' => '验证码已失效，请重新获取']);
        }
        if (time() > (int) $vc['expire_time']) {
            return json(['code' => 1, 'msg' => '验证码已过期，请重新获取']);
        }
        if (strtoupper($captcha) !== strtoupper((string) $vc['code'])) {
            return json(['code' => 1, 'msg' => '验证码错误']);
        }
        // 标记已使用（一次性）
        Db::name('verify_code')->where('id', (int) $vc['id'])->update(['used' => 1]);

        if ('' === $phone) {
            return json(['code' => 1, 'msg' => '手机号不能为空']);
        }
        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) {
            return json(['code' => 1, 'msg' => '手机号格式不正确']);
        }
        if ('' === $nickname) {
            return json(['code' => 1, 'msg' => '昵称不能为空']);
        }
        if (strlen($password) < 6) {
            return json(['code' => 1, 'msg' => '密码长度至少6位']);
        }

        // 手机号唯一（登录标识）
        if (Db::name('user')->where('phone', $phone)->find()) {
            return json(['code' => 1, 'msg' => '该手机号已注册']);
        }

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

        return json(['code' => 0, 'msg' => '注册成功']);
    }

    /**
     * 用户登录（返回 token + 设备密钥）
     *
     * 额外参数：
     *   platform  - 应用标识：android/ios/win/mac/h5
     *   device_id - 客户端生成的设备唯一标识（UUID）
     *
     * 安全机制：
     *   - 每台设备一个独立的 device_key（登录时签发，客户端加密保存）
     *   - 同一账号 + 同一平台只允许一个设备在线（新的登录踢掉旧设备）
     */
    public function login()
    {
        // account 支持：手机号 或 UID（数字）
        $account = trim((string) $this->request->post('account', ''));
        $password = (string) $this->request->post('password', '');
        $platform = strtolower((string) $this->request->post('platform', 'win'));
        $deviceId = trim((string) $this->request->post('device_id', ''));

        if ('' === $account || '' === $password) {
            return json(['code' => 1, 'msg' => '请输入手机号/UID和密码']);
        }
        if (!in_array($platform, ['android', 'ios', 'win', 'mac', 'h5'], true)) {
            return json(['code' => 1, 'msg' => '无效的应用标识']);
        }
        if ('' === $deviceId || strlen($deviceId) > 64) {
            return json(['code' => 1, 'msg' => '设备标识缺失']);
        }

        // 平台维护中：禁止登录
        $maint = self::maintenanceInfo();
        if ($maint['enabled']) {
            return json([
                'code' => 503,
                'msg'  => $maint['notice'] ?: '平台维护中，请稍后再试',
                'data' => ['maintenance' => $maint],
            ]);
        }

        // 用手机号或 UID 匹配用户
        $user = Db::name('user')
            ->where(function ($q) use ($account) {
                $q->where('phone', $account);
                if (is_numeric($account)) {
                    $q->whereOr('id', (int) $account);
                }
            })
            ->find();

        if (!$user) {
            return json(['code' => 1, 'msg' => '账号或密码错误']);
        }

        if (!Password::verify($password, $user['password'])) {
            return json(['code' => 1, 'msg' => '账号或密码错误']);
        }

        // 状态判断
        // 注销：拒绝登录
        if (0 === (int) $user['status']) {
            return json(['code' => 1, 'msg' => '账号已注销']);
        }
        // 封禁：允许登录（后续限制操作），返回 banned 标记
        $isBanned = 2 === (int) $user['status'];
        $bannedReason = $isBanned ? (string) $user['ban_reason'] : '';

        $now = time();
        $ip = $this->request->ip();

        // ========== 设备会话 ==========
        // 1) 同一账号 + 同一平台的其他设备全部下线（单设备在线）
        Db::name('user_device')
            ->where('user_id', $user['id'])
            ->where('platform', $platform)
            ->update(['status' => 0, 'update_time' => $now]);

        // 2) 生成 token + 设备密钥（各 32 位）
        $token = Password::makeToken();
        $deviceKey = Password::makeToken();

        // 3) upsert 当前设备
        $device = Db::name('user_device')
            ->where('user_id', $user['id'])
            ->where('platform', $platform)
            ->where('device_id', $deviceId)
            ->find();

        if ($device) {
            Db::name('user_device')->where('id', $device['id'])->update([
                'device_key'      => $deviceKey,
                'login_token'     => $token,
                'status'          => 1,
                'last_login_time' => $now,
                'last_login_ip'   => $ip,
                'update_time'     => $now,
            ]);
        } else {
            Db::name('user_device')->insert([
                'user_id'         => $user['id'],
                'platform'        => $platform,
                'device_id'       => $deviceId,
                'device_key'      => $deviceKey,
                'login_token'     => $token,
                'status'          => 1,
                'last_login_time' => $now,
                'last_login_ip'   => $ip,
                'create_time'     => $now,
                'update_time'     => $now,
            ]);
        }

        // 更新 user 表 token；密码验证通过，记录本次 password_changed
        Db::name('user')->where('id', $user['id'])->update([
            'login_token'      => $token,
            'last_login_time'  => $now,
            'last_login_ip'    => $ip,
            'password_changed' => $now, // 登录成功视为密码已验证
            'update_time'      => $now,
        ]);

        // 查询钱包余额（熊爪/人民币），随登录返回供客户端展示
        $wallet = Db::name('wallet')->where('uid', (int) $user['id'])->find();

        return json([
            'code' => 0,
            'msg'  => '登录成功',
            'data' => [
                'token'       => $token,
                'device_key'  => $deviceKey,
                'platform'    => $platform,
                'device_id'   => $deviceId,
                'banned'      => $isBanned,
                'ban_reason'  => $bannedReason,
                'user'        => [
                    'id'       => (int) $user['id'],
                    'phone'    => (string) $user['phone'],
                    'nickname' => $user['nickname'],
                    'avatar'   => $user['avatar'],
                    'paw'      => $wallet ? (int) $wallet['balance_paw'] : 0,
                    'cny'      => $wallet ? (float) $wallet['balance_cny'] : 0.0,
                ],
            ],
        ]);
    }

    /**
     * 账号状态检查（自动登录 / 会话恢复时调用）
     *
     * 业务接口：需要 device_key
     * 返回：账号是否注销/封禁、密码是否被后台修改、是否有异地登录等
     */
    public function status()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];
        $now = time();

        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return json(['code' => 401, 'msg' => '账号不存在']);
        }

        // 注销：强制下线
        if (0 === (int) $user['status']) {
            return json([
                'code' => 401,
                'msg'  => '账号已注销',
                'data' => ['account_cancelled' => true],
            ]);
        }

        // 封禁：提示但允许使用（未来限制操作）
        $isBanned = 2 === (int) $user['status'];
        $bannedReason = $isBanned ? (string) $user['ban_reason'] : '';

        // 密码被后台修改：当前 device 记录的 password_changed 与 user 不一致
        // 这里比较 user 表 password_changed 与本次登录记录时间（简化：返回最新 password_changed）
        $passwordChanged = (int) ($user['password_changed'] ?? 0);

        // 异地登录检测：当前 device 是否仍为在线状态（单设备在线时已被踢下线则 status=0）
        $isLoggedOut = (int) $device['status'] !== 1;

        // 查询钱包余额（熊爪/人民币），随状态返回供客户端展示
        $wallet = Db::name('wallet')->where('uid', $uid)->find();

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'account_cancelled' => false,
                'banned'            => $isBanned,
                'ban_reason'        => $bannedReason,
                'password_changed'  => $passwordChanged,
                'kicked'            => $isLoggedOut, // 异地登录/被踢下线
                'user'              => [
                    'id'       => (int) $user['id'],
                    'phone'    => (string) $user['phone'],
                    'nickname' => $user['nickname'],
                    'avatar'   => $user['avatar'],
                    'paw'      => $wallet ? (int) $wallet['balance_paw'] : 0,
                    'cny'      => $wallet ? (float) $wallet['balance_cny'] : 0.0,
                ],
            ],
        ]);
    }

    /**
     * 修改昵称（业务接口：签名 + 设备密钥）
     * POST nickname
     */
    public function profile()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];
        $nickname = trim((string) $this->request->post('nickname', ''));

        if ('' === $nickname) {
            return json(['code' => 1, 'msg' => '昵称不能为空']);
        }
        if (mb_strlen($nickname) > 50) {
            return json(['code' => 1, 'msg' => '昵称最长50个字符']);
        }

        Db::name('user')->where('id', $uid)->update([
            'nickname'    => $nickname,
            'update_time' => time(),
        ]);

        // 昵称变化立即失效缓存，避免评论/通知列表展示旧昵称
        UserInfoCache::forget($uid);

        return json(['code' => 0, 'msg' => '保存成功', 'data' => ['nickname' => $nickname]]);
    }

    /**
     * 上传头像（业务接口：签名 + 设备密钥）
     * multipart/form-data：字段 avatar
     *
     * 图片保存到 public/static/avatar/，返回可访问的相对路径。
     */
    public function uploadAvatar()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $file = $this->request->file('avatar');
        if (!$file) {
            return json(['code' => 1, 'msg' => '未接收到图片']);
        }

        // 校验图片：必须是 image 类型，大小限制 5MB（原始）
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        $mime = (string) $file->getMime();
        if (!in_array(strtolower($mime), $allowed, true)) {
            return json(['code' => 1, 'msg' => '仅支持 jpg/png/gif/webp/bmp 图片']);
        }
        $size = $file->getSize();
        if ($size > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => '图片不能超过5MB']);
        }

        // 目标目录：public/static/avatar/
        $base = app()->getRootPath() . 'public/static/avatar';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }

        // 直接读取上传临时文件压缩（上传临时文件已落在本地磁盘，无需再 move；
        // 之前 move($tmp) 把文件路径当目录用，必然抛异常导致"头像上传失败"）
        $srcPath = $file->getPathname();
        if ($srcPath === '' || !is_file($srcPath)) {
            return json(['code' => 1, 'msg' => '头像上传失败']);
        }

        // 压缩（超 256KB 才压，GIF 动画保留；头像最大边 512）
        $bytes = \app\common\ImageProcessor::compressToMaxBytes($srcPath, 256 * 1024, 512);
        if ($bytes === '') {
            return json(['code' => 1, 'msg' => '头像处理失败']);
        }

        // 按压缩后内容判定扩展名（可能由 BMP/WebP 转码为 jpg/png）
        $ext = self::detectExtByBytes($bytes) ?: 'jpg';
        $name = 'u' . $uid . '_' . date('YmdHis') . '_' . substr(md5((string) microtime(true)), 0, 8) . '.' . $ext;
        $target = $base . DIRECTORY_SEPARATOR . $name;
        $ok = @file_put_contents($target, $bytes);
        if ($ok === false) {
            return json(['code' => 1, 'msg' => '头像上传失败']);
        }
        @chmod($target, 0666 & ~umask());

        $avatarPath = '/static/avatar/' . $name;
        Db::name('user')->where('id', $uid)->update([
            'avatar'      => $avatarPath,
            'update_time' => time(),
        ]);

        // 头像变化立即失效缓存，避免评论/通知列表展示旧头像
        UserInfoCache::forget($uid);

        return json(['code' => 0, 'msg' => '头像更新成功', 'data' => ['avatar' => $avatarPath]]);
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
     * 修改密码（业务接口：签名 + 设备密钥）
     * POST old_password, new_password
     *
     * 校验旧密码正确，成功后强制当前设备下线（需重新登录）。
     */
    public function changePassword()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];
        $oldPassword = (string) $this->request->post('old_password', '');
        $newPassword = (string) $this->request->post('new_password', '');

        if ('' === $oldPassword) {
            return json(['code' => 1, 'msg' => '请输入原密码']);
        }
        if (strlen($newPassword) < 6) {
            return json(['code' => 1, 'msg' => '新密码长度至少6位']);
        }

        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return json(['code' => 401, 'msg' => '账号不存在']);
        }
        if (!Password::verify($oldPassword, $user['password'])) {
            return json(['code' => 1, 'msg' => '原密码错误']);
        }

        $now = time();
        Db::name('user')->where('id', $uid)->update([
            'password'        => Password::encrypt($newPassword),
            'login_token'     => '',
            'password_changed' => $now,
            'update_time'     => $now,
        ]);

        // 该用户所有设备下线（密码已变更，需重新登录）
        Db::name('user_device')
            ->where('user_id', $uid)
            ->where('status', 1)
            ->update(['status' => 0, 'update_time' => $now]);

        Db::name('user_log')->insert([
            'user_id'     => $uid,
            'phone'       => (string) $user['phone'],
            'action'      => 'change_password',
            'ip'          => $this->request->ip(),
            'operator'    => 'user',
            'remark'      => '修改密码',
            'create_time' => $now,
        ]);

        return json(['code' => 0, 'msg' => '密码修改成功，请重新登录']);
    }

    /**
     * 获取当前 api 域名 + 运行环境 + WebSocket 连接参数（公开接口，仅签名）
     *
     * 客户端每次启动（或连接前）调用本接口，拿到服务端决定的 WebSocket 协议：
     *   - 开发环境（APP_ENV=true）→ ws_scheme = 'ws'（直连 Swoole，如 ws://127.0.1.3:2346）
     *   - 生产环境（APP_ENV=false）→ ws_scheme = 'wss'（经 Nginx 反向代理，如 wss://yourdomain.com/ws）
     *
     * 生产环境对外仅暴露 wss，仅能发起 ws:// 的客户端将无法连接（服务端强制 wss）。
     * 客户端据此选择 ws:// 或 wss:// 连接，无需在客户端硬编码环境判断。
     */
    public function domain()
    {
        // 运行环境：true=开发 false=生产
        $isDev = in_array(strtolower((string) env('APP_ENV', 'true')), ['1', 'true', 'yes', 'on'], true);
        $wsPort = (int) env('WS_PORT', 2346);

        $apiDomain = (string) env('DOMAIN_API', '127.0.1.3');
        // 兼容带协议/不带协议的域名，统一取 host
        $apiHost = parse_url($apiDomain, PHP_URL_HOST);
        if (!$apiHost) {
            $apiHost = $apiDomain;
        }

        if ($isDev) {
            // 开发：直连 Swoole。注意：客户端必须连"可达的地址"，
            // 不能用 WS_HOST（0.0.0.0 是服务端监听通配地址，客户端连不上），
            // 因此开发环境用 apiHost（客户端可达的 api 域名/IP）+ ws_port。
            $wsUrl = "ws://{$apiHost}:{$wsPort}";
        } else {
            // 生产：经 Nginx 反向代理，走 https 同域 443，path 固定 /ws
            $wsUrl = "wss://{$apiHost}/ws";
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'api_domain'  => env('DOMAIN_API', '127.0.1.3'),
                'app_env'     => $isDev ? 'dev' : 'prod',
                'ws_scheme'   => $isDev ? 'ws' : 'wss',
                // 开发环境 ws_host 用 apiHost（客户端可达），不用 WS_HOST（0.0.0.0 监听通配地址）
                'ws_host'     => $isDev ? $apiHost : $apiHost,
                'ws_port'     => $isDev ? $wsPort : 443,
                'ws_url'      => $wsUrl,            // 客户端可直接用该地址建立连接
                'server_time' => time(),            // 服务端权威时间（客户端校时用）
                // 平台维护状态（系统设置开启维护时返回公告）
                'maintenance' => self::maintenanceInfo(),
            ],
        ]);
    }

    /**
     * 平台维护信息（系统设置 config 表）
     *
     * @return array{enabled: bool, notice: string}
     */
    protected static function maintenanceInfo(): array
    {
        $data = Db::name('config')->column('value', 'name');
        $enabled = '1' === (string) ($data['maintenance_enabled'] ?? '0');
        return [
            'enabled' => $enabled,
            'notice'  => (string) ($data['maintenance_notice'] ?? ''),
        ];
    }

}
