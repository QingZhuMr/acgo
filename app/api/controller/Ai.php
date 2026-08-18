<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\Password;
use think\facade\Db;

/**
 * AI/机器人专用接口（内部工具）
 *
 * 与常规业务接口不同：**免签名、免设备密钥**，仅用独立 `AI_TOKEN`
 * （.env `AI_TOKEN`，请求头 `X-AI-Token`）保护。供本地自动化脚本
 * （批量建号、发帖、评论等）直接调用，降低脚本复杂度。
 *
 * 注意：中间件 `$publicActions` 已放行本控制器的接口（跳过 HMAC 签名校验），
 * 鉴权完全依赖 `AI_TOKEN`，务必保证 token 安全（生产可随时更换）。
 *
 * 接口：
 *  - POST /api/ai/batchCreate   批量创建用户（返回 uid/昵称/手机号/密码）
 *  - POST /api/ai/uploadAvatar  给指定 uid 上传头像（multipart）
 */
class Ai extends BaseController
{
    /** 请求头中的 AI token 键 */
    private const TOKEN_HEADER = 'x-ai-token';

    /** 后台创建统一头像默认值 */
    private const DEFAULT_AVATAR = '/static/avatar/default.png';

    /** 合法平台（供后续扩展发帖用） */
    private const PLATFORMS = ['win', 'mac', 'linux', 'android', 'ios', 'ipados', 'harmony'];

    /** 合法头像扩展名 */
    private const AVATAR_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * 校验 AI_TOKEN（构造器已校验，此处冗余防御）
     */
    private function checkToken(): bool
    {
        $expected = trim((string) env('AI_TOKEN', ''));
        $given = trim((string) ($this->request->header(self::TOKEN_HEADER) ?? ''));
        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * 批量创建用户（机器人/种子账号）
     *
     * POST：prefix（昵称前缀）、count（1-50）、password（可空默认 123456）
     * 手机号从 10000000001 起始递增（格式正确 11 位，不占用真实手机号段，不冲突）。
     * 返回：data.accounts[] = {id, nickname, phone, password}
     */
    public function batchCreate()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $prefix   = trim((string) $this->request->post('prefix', ''));
        $count    = (int) $this->request->post('count', 0);
        $password = (string) $this->request->post('password', '');

        if ($prefix === '') return json(['code' => 1, 'msg' => '请填写昵称前缀']);
        if ($count < 1 || $count > 50) return json(['code' => 1, 'msg' => '批量数量 1-50']);
        if ($password !== '' && strlen($password) < 6) return json(['code' => 1, 'msg' => '密码至少6位']);
        if ($password === '') $password = '123456';

        // 已占用手机号集合（10000000001 起的号段）
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

        $base = 10000000001;
        while (isset($used[(string) $base])) {
            $base++;
        }

        $now = time();
        $accounts = [];
        for ($i = 1; $i <= $count; $i++) {
            $nickname = $prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
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
            // 同步创建钱包记录（与注册一致）
            Db::name('wallet')->insert([
                'uid'         => $userId,
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $accounts[] = [
                'id'       => $userId,
                'nickname' => $nickname,
                'phone'    => $phone,
                'password' => $password,
            ];
        }

        return json([
            'code' => 0,
            'msg'  => "成功创建 {$count} 个用户",
            'data' => ['accounts' => $accounts],
        ]);
    }

    /**
     * 给指定 uid 上传/设置头像
     *
     * POST multipart：uid + avatar（文件，png/jpg/jpeg/gif/webp，≤5MB）
     * 存 public/static/avatar/ai_{uid}.{ext}，覆盖同名；更新 user 表 avatar 字段。
     * 返回：data.avatar = 相对路径
     */
    public function uploadAvatar()
    {
        if (!$this->checkToken()) {
            return json(['code' => 401, 'msg' => 'AI token 无效']);
        }

        $uid = (int) $this->request->post('uid', 0);
        if ($uid <= 0 || !Db::name('user')->where('id', $uid)->find()) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        $file = $this->request->file('avatar');
        if (!$file) {
            return json(['code' => 1, 'msg' => '请上传头像文件']);
        }
        $size = $file->getSize();
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => '头像不能超过5MB']);
        }
        $ext = strtolower((string) pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($ext, self::AVATAR_EXTS, true)) {
            return json(['code' => 1, 'msg' => '不支持的图片格式：' . $ext]);
        }

        $fileName = 'ai_' . $uid . '.' . $ext;
        $dir = public_path() . 'static' . DIRECTORY_SEPARATOR . 'avatar';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            $file->move($dir, $fileName);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        $avatar = '/static/avatar/' . $fileName;
        Db::name('user')->where('id', $uid)->update([
            'avatar'      => $avatar,
            'update_time' => time(),
        ]);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => ['avatar' => $avatar],
        ]);
    }
}
