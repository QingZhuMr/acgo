<?php
declare(strict_types=1);

namespace app\api\middleware;

use Closure;
use think\Request;
use think\Response;
use think\facade\Db;

/**
 * api 应用请求签名 + 密钥校验中间件（双层）
 *
 * 安全模型：
 *   - 签名校验（所有接口）：请求须带 X-Timestamp + X-Signature
 *     signature = HMAC-SHA256(API_SECRET, "timestamp|path")
 *     时间戳防重放（±300s），即使 secret 泄漏签名也有时效
 *   - 公开接口（登录/注册/域名）：签名通过即可
 *   - 业务接口（更新检查等）：签名通过 + 校验设备级密钥 X-Device-Key
 *
 * 目的：防止非授权方无限调用、防重放、防伪造；每设备独立密钥，单设备在线。
 */
class ApiAuth
{
    /**
     * 仅需签名（无需设备密钥）的公开接口（pathinfo）
     */
    protected array $publicActions = [
        'user/login',
        'user/register',
        'user/domain',
        'captcha/image',
        'post/list',      // 广场列表：游客可浏览
        'post/detail',    // 帖子详情：游客可浏览
        'planet/list',    // 星球列表：游客可浏览（公开只读数据）
        // AI/机器人专用接口（免签名免设备密钥，靠 X-AI-Token 独立保护）
        'ai/batchCreate',
        'ai/uploadAvatar',
    ];

    /**
     * 允许的时间戳偏差（秒，普通接口）
     */
    protected int $timestampDrift = 300;

    /**
     * 校时入口（user/domain）允许的偏差（秒）
     *
     * domain 是客户端启动后第一个调用的接口（也是校时入口），
     * 此时客户端可能尚未校准本地时钟（尤其国外访问时差大），
     * 故放宽到 10 分钟，只用于获取服务端时间，不产生业务副作用。
     */
    protected int $domainDrift = 600;

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = strtolower(trim((string) $request->pathinfo(), '/'));
        $appSecret = (string) env('API_SECRET', '');

        // ========== 0. AI 专用接口：完全跳过签名与设备密钥 ==========
        // ai/* 靠 X-AI-Token 独立保护（控制器内校验），免 HMAC 签名方便脚本调用
        if (str_starts_with($path, 'ai/')) {
            return $next($request);
        }

        // ========== 1. 通用签名校验 ==========
        $check = $this->verifySignature($request, $path, $appSecret);
        if ($check !== null) {
            return $check;
        }

        // ========== 2. 业务接口：再校验设备级密钥 ==========
        if (!in_array($path, $this->publicActions, true)) {
            $deviceKey = (string) $request->header('x-device-key', '');
            if ($deviceKey === '') {
                $deviceKey = (string) $request->param('device_key', '');
            }
            if ($deviceKey === '') {
                return json(['code' => 401, 'msg' => '缺少设备密钥']);
            }

            $device = Db::name('user_device')
                ->where('device_key', $deviceKey)
                ->where('status', 1)
                ->find();

            if (!$device) {
                return json(['code' => 401, 'msg' => '设备密钥无效或已下线']);
            }

            // 注入当前设备/用户到请求
            $request->deviceInfo = $device;
        }

        return $next($request);
    }

    /**
     * 校验请求签名；返回 null 表示通过，否则返回错误响应
     */
    private function verifySignature(Request $request, string $path, string $secret): ?Response
    {
        if ($secret === '') {
            return json(['code' => 500, 'msg' => '服务端未配置签名密钥']);
        }

        $timestamp = (string) $request->header('x-timestamp', '');
        $signature = (string) $request->header('x-signature', '');
        if ($timestamp === '') {
            $timestamp = (string) $request->param('timestamp', '');
        }
        if ($signature === '') {
            $signature = (string) $request->param('signature', '');
        }

        if ($timestamp === '' || $signature === '') {
            return json(['code' => 401, 'msg' => '缺少签名参数']);
        }

        // 时间戳防重放
        if (!ctype_digit($timestamp)) {
            return json(['code' => 401, 'msg' => '签名时间戳非法']);
        }
        $ts = (int) $timestamp;
        // 校时入口（user/domain）放宽时间窗；其余接口用严格窗口（±300s）
        $drift = ($path === 'user/domain') ? $this->domainDrift : $this->timestampDrift;
        if (abs(time() - $ts) > $drift) {
            return json(['code' => 401, 'msg' => '签名已过期']);
        }

        // 计算期望签名：HMAC-SHA256(secret, "timestamp|path")
        $expected = hash_hmac('sha256', "{$timestamp}|{$path}", $secret);

        if (!hash_equals($expected, $signature)) {
            return json(['code' => 401, 'msg' => '签名校验失败']);
        }

        return null;
    }
}
