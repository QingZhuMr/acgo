<?php
declare(strict_types=1);

namespace app\common;

/**
 * 图片压缩处理（GD 扩展）
 *
 * 职责：服务端兜底压缩上传图片，避免大图占用带宽。
 * 不同场景目标大小不同（由调用方传入 maxBytes），
 * 可选限制最大边长（maxDim，帖子/评论/头像分别传 1080/800/512）。
 *
 * 压缩策略（只对超限图片压缩，未超限原样保留）：
 *   1. 分辨率超出 maxDim 时，先一次性缩到目标尺寸（最大性能收益：
 *      避免在超大原图上反复做全尺寸编码）
 *   2. 有损格式（JPEG/WebP/BMP）在工作分辨率上二分搜索最优质量
 *   3. 仍超限（或 PNG/GIF 无损）则逐步等比缩小分辨率
 *
 * GIF 动画保留原样（不压缩，避免破坏动画帧）；静态 GIF 按图处理。
 */
class ImageProcessor
{
    /** 有损压缩质量区间 */
    private const MIN_QUALITY = 40;
    private const MAX_QUALITY = 95;

    /** 质量二分搜索迭代次数（区间 55 收敛到 ~2 精度） */
    private const BINARY_ITER = 5;

    /** 初次缩 maxDim 时的编码质量 */
    private const INIT_QUALITY = 82;

    /** 逐步缩小分辨率时的编码质量 */
    private const RESIZE_QUALITY = 70;

    /** 最小允许边长（避免压缩过度失真） */
    private const MIN_SIDE = 32;

    /** 最大缩放迭代次数（防死循环） */
    private const MAX_ITER = 8;

    /**
     * 把源图片压缩到目标大小内（超出才压）。
     *
     * @param string $srcPath  源图片路径
     * @param int    $maxBytes 目标最大字节数
     * @param int    $maxDim   目标最大边长（0 = 不限制分辨率）
     * @return string 压缩后的图片字节（若原图未超限则返回原字节）
     */
    public static function compressToMaxBytes(string $srcPath, int $maxBytes, int $maxDim = 0): string
    {
        $srcBytes = @file_get_contents($srcPath);
        if ($srcBytes === false || strlen($srcBytes) <= $maxBytes) {
            // 读不到或未超限，原样返回
            return $srcBytes === false ? '' : $srcBytes;
        }

        // GIF 动画（多帧）保留原样
        if (self::isAnimatedGif($srcBytes)) {
            return $srcBytes;
        }

        $info = @getimagesizefromstring($srcBytes);
        if ($info === false) {
            return $srcBytes;
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $type = (int) $info[2];
        if ($w <= 0 || $h <= 0) {
            return $srcBytes;
        }

        $src = self::createImage($srcBytes, $type);
        if ($src === null) {
            return $srcBytes;
        }

        // 当前工作资源 + 尺寸（可能被下面「缩 maxDim」更新）
        $work = $src;
        $cw = $w;
        $ch = $h;

        // 1) 分辨率超出 maxDim：先一步缩到目标尺寸，避免在超大原图上反复全尺寸编码
        if ($maxDim > 0 && max($w, $h) > $maxDim) {
            $scale = $maxDim / max($w, $h);
            $cw = max(self::MIN_SIDE, (int) round($w * $scale));
            $ch = max(self::MIN_SIDE, (int) round($h * $scale));
            $resized = self::resizeAndEncode($src, $cw, $ch, $type, self::INIT_QUALITY);
            if ($resized !== '') {
                if (strlen($resized) <= $maxBytes) {
                    return $resized;
                }
                $tmp = @imagecreatefromstring($resized);
                if ($tmp !== false) {
                    $work = $tmp;
                } else {
                    $cw = $w;
                    $ch = $h;
                }
            } else {
                $cw = $w;
                $ch = $h;
            }
        }

        $out = '';

        // 2) 有损格式（JPEG/WebP，BMP 经 encode 转 JPEG）：二分搜最优质量
        if ($type === IMAGETYPE_JPEG || $type === IMAGETYPE_WEBP || $type === IMAGETYPE_BMP) {
            $lo = self::MIN_QUALITY;
            $hi = self::MAX_QUALITY;
            for ($i = 0; $i < self::BINARY_ITER; $i++) {
                $mid = ($lo + $hi) >> 1;
                $enc = self::encode($work, $cw, $ch, $type, $mid);
                if ($enc === '') {
                    break;
                }
                if (strlen($enc) <= $maxBytes) {
                    $out = $enc;
                    $lo = $mid + 1; // 尝试更高质量
                } else {
                    $hi = $mid - 1; // 太大，降质量
                }
            }
        }

        // 3) 仍超限（或 PNG/GIF 无损）：逐步缩小分辨率
        $iter = 0;
        while (($out === '' || strlen($out) > $maxBytes) && $iter < self::MAX_ITER) {
            $cw = (int) ($cw * 0.82);
            $ch = (int) ($ch * 0.82);
            if ($cw < self::MIN_SIDE || $ch < self::MIN_SIDE) {
                break;
            }
            $resized = self::resizeAndEncode($work, $cw, $ch, $type, self::RESIZE_QUALITY);
            if ($resized === '') {
                break;
            }
            $out = $resized;
            $iter++;
        }

        // 兜底：仍无可用输出则返回原字节
        // 注意：不调用 imagedestroy()。PHP 8.0+ 已废弃该函数（无效果），
        // 且 PHP 8.5 下 ThinkPHP 会把 E_DEPRECATED 转为 ErrorException 导致 500。
        return $out !== '' ? $out : $srcBytes;
    }

    /** 根据类型创建图像资源 */
    private static function createImage(string $bytes, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromstring($bytes),
            IMAGETYPE_PNG  => @imagecreatefromstring($bytes),
            IMAGETYPE_GIF  => @imagecreatefromstring($bytes),
            IMAGETYPE_WEBP => @imagecreatefromstring($bytes),
            IMAGETYPE_BMP  => @imagecreatefromstring($bytes),
            default         => null,
        };
    }

    /** 编码输出（保留原始类型；BMP 等未知类型转 JPEG） */
    private static function encode($img, int $w, int $h, int $type, int $quality): string
    {
        ob_start();
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, null, $quality),
            IMAGETYPE_PNG  => imagepng($img, null, (int) (9 - $quality / 11)), // 0-9 压缩级别
            IMAGETYPE_GIF  => imagegif($img),
            IMAGETYPE_WEBP => imagewebp($img, null, $quality),
            default        => imagejpeg($img, null, $quality),
        };
        $data = ob_get_clean();
        return $ok ? (string) $data : '';
    }

    /** 缩放并编码输出 */
    private static function resizeAndEncode($src, int $nw, int $nh, int $type, int $quality = 78): string
    {
        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) {
            return '';
        }
        // PNG 透明背景保留
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));
        $out = self::encode($dst, $nw, $nh, $type, $quality);
        // 不调用 imagedestroy()：PHP 8.5 下 E_DEPRECATED 会转异常导致 500，资源由 GC 自动回收
        return $out;
    }

    /** 判断是否为动画 GIF（含多帧） */
    private static function isAnimatedGif(string $bytes): bool
    {
        if (strlen($bytes) < 13 || substr($bytes, 0, 6) !== 'GIF89a' && substr($bytes, 0, 6) !== 'GIF87a') {
            return false;
        }
        // 简单判断：存在图形控制扩展（0x21 0xF9）通常表示多帧
        return strpos($bytes, "\x21\xF9") !== false;
    }
}
