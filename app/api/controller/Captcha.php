<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 图形验证码接口（公开，仅签名）
 * 客户端生成 captcha_id（UUID）请求验证码图片，注册时带上 captcha + captcha_id 校验。
 */
class Captcha extends BaseController
{
    /** 验证码长度 */
    protected const LEN = 4;

    /** 有效期（秒） */
    protected const TTL = 300;

    /** 生成一个验证码图片，返回 base64（data:image/png;base64,...） */
    public function image()
    {
        $captchaId = trim((string) $this->request->get('captcha_id', ''));
        if ('' === $captchaId || strlen($captchaId) > 64) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        // 生成随机验证码（排除易混淆字符 0/O/1/I）
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code  = '';
        $max   = strlen($chars) - 1;
        for ($i = 0; $i < self::LEN; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        // 存储验证码（覆盖该 captcha_id 旧记录）
        $now = time();
        Db::name('verify_code')->where('captcha_id', $captchaId)->delete();
        Db::name('verify_code')->insert([
            'captcha_id'  => $captchaId,
            'code'        => $code,
            'type'        => 'register',
            'used'        => 0,
            'expire_time' => $now + self::TTL,
            'create_time' => $now,
        ]);

        // GD 生成图片
        $img = $this->createImage($code);
        if (!$img) {
            return json(['code' => 1, 'msg' => '验证码生成失败']);
        }
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        // 注意：不调用 imagedestroy()。PHP 8.0+ 已废弃该函数（无效果），
        // 且 PHP 8.5 下调用会触发 E_DEPRECATED 被 ThinkPHP 当作异常。GD 资源由运行时自动回收。

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'captcha_id' => $captchaId,
                'image'      => 'data:image/png;base64,' . base64_encode($png),
            ],
        ]);
    }

    /**
     * GD 生成带干扰线的验证码图片
     * 返回类型不显式声明，兼容 PHP7.4(resource)/PHP8(GdImage)
     */
    protected function createImage(string $code)
    {
        $w = 120;
        $h = 40;
        $img = imagecreatetruecolor($w, $h);

        // 背景
        $bg = imagecolorallocate($img, 245, 246, 250);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // 干扰线
        for ($i = 0; $i < 5; $i++) {
            $lineColor = imagecolorallocate($img, random_int(140, 200), random_int(140, 200), random_int(140, 200));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $lineColor);
        }

        // 文字（4 字符等距均匀分布，垂直居中，小幅旋转）
        $fontSize = 22;
        // 文字绘制区域：宽 100（左右各留 10），高 40 完整使用
        $areaW = $w - 20;
        $charW = (int) floor($areaW / self::LEN);
        // 基线 y：字高约 22，基线设在图片底部偏上（$h - 8 = 32），字符垂直居中
        $baselineY = $h - 8;
        for ($i = 0; $i < self::LEN; $i++) {
            $char = $code[$i];
            $color = imagecolorallocate($img, random_int(20, 90), random_int(20, 90), random_int(20, 90));
            // x 等距分布：每字符格中心 = 10 + $i*$charW + $charW/2；随机微调 ±2 防呆板
            $x = 10 + $i * $charW + (int) ($charW / 2) + random_int(-2, 2);
            $y = $baselineY;
            $angle = random_int(-10, 10); // 旋转角度小，避免字符溢出
            imagettftext($img, $fontSize, $angle, $x, $y, $color, $this->fontPath(), $char);
        }

        // 噪点
        for ($i = 0; $i < 40; $i++) {
            $dot = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($img, random_int(0, $w), random_int(0, $h), $dot);
        }

        return $img;
    }

    /**
     * 字体路径（优先系统字体，找不到则返回 null → 用内置字体兜底）
     */
    protected function fontPath(): string
    {
        // Windows 常见字体
        $candidates = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        // 找不到字体时返回空路径，imagettftext 会用默认字体（可能较丑但可用）
        return '';
    }
}
