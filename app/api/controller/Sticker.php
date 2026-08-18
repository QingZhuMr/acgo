<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\StickerCache;
use think\facade\Db;

/**
 * 表情包接口
 *
 * 职责：用户上传的表情包由客户端先处理（压缩到 240x240、≤256KB，GIF 保留动画），
 * 服务端仅做校验（格式 / 大小 / 尺寸），防止客户端绕过处理直接上传大文件。
 *
 *  - list：业务接口（签名 + 设备密钥），返回全局表情包列表（供评论面板选择）
 *  - upload：业务接口（签名 + 设备密钥），multipart 上传字段 sticker
 *
 * 表情包以 `[sticker:id]` 占位标记随评论内容存储，客户端渲染评论时按 id 替换为图片。
 */
class Sticker extends BaseController
{
    /** 压缩后上限 256KB（与客户端 StickerCompressor.maxBytes 一致） */
    protected const SIZE_MAX = 256 * 1024;
    /** 标准表情包边长（微信/QQ 行业标准 240x240，客户端 contain 进该尺寸） */
    protected const SIDE_MAX = 240;
    /** 允许的 MIME */
    protected const MIME_ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * 表情包列表（业务接口）：全局正常表情包，按时间倒序
     *
     * 列表本体走 StickerCache（Redis 缓存，TTL 10 分钟）；is_mine 按当前用户
     * 实时计算（不入缓存）。上传/删除后缓存自动失效。
     */
    public function list()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $rows = StickerCache::get();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'      => (int) $r['id'],
                'url'     => (string) $r['url'],
                'width'   => (int) $r['width'],
                'height'  => (int) $r['height'],
                'mime'    => (string) $r['mime'],
                'is_mine' => (int) $r['user_id'] === $uid,
            ];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list]]);
    }

    /**
     * 删除表情包（业务接口）：只能删除自己上传的
     * POST：sticker_id
     */
    public function delete()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $stickerId = (int) $this->request->post('sticker_id', 0);
        if ($stickerId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $sticker = Db::name('sticker')->where('id', $stickerId)->find();
        if (!$sticker) {
            return json(['code' => 1, 'msg' => '表情包不存在']);
        }
        // 只能删除自己上传的表情包
        if ((int) $sticker['user_id'] !== $uid) {
            return json(['code' => 1, 'msg' => '只能删除自己上传的表情包']);
        }
        // 软删（保留历史评论中引用图片可继续访问；若物理删除，历史评论里的表情会失效）
        Db::name('sticker')->where('id', $stickerId)->update(['status' => 0]);

        // 列表缓存失效
        StickerCache::forget();

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 添加到表情（业务接口）：收藏聊天中收到的表情包到自己的表情库
     * POST：url（表情相对路径，如 /static/sticker/xx.png）
     *
     * 校验 url 必须是平台已有表情包（sticker 表存在该 url），复制宽高/MIME
     * 创建一条自己名下的记录（软引用：图片文件不复制，删除收藏不影响他人）。
     * 已添加过则幂等返回成功。
     */
    public function add()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $url = (string) $this->request->post('url', '');
        if ($url === '' || strpos($url, '/static/sticker/') !== 0) {
            return json(['code' => 1, 'msg' => '表情包无效']);
        }

        // 源表情必须存在于平台（保证收藏的是有效表情）
        $src = Db::name('sticker')
            ->where('url', $url)
            ->order('id', 'asc')
            ->find();
        if (!$src) {
            return json(['code' => 1, 'msg' => '表情包不存在或已失效']);
        }

        // 已添加过：幂等返回成功
        $mine = Db::name('sticker')
            ->where('user_id', $uid)
            ->where('url', $url)
            ->where('status', 1)
            ->find();
        if ($mine) {
            return json(['code' => 0, 'msg' => '已添加到表情包', 'data' => ['id' => (int) $mine['id']]]);
        }

        Db::name('sticker')->insert([
            'user_id'     => $uid,
            'url'         => $url,
            'width'       => (int) $src['width'],
            'height'      => (int) $src['height'],
            'size'        => (int) $src['size'],
            'mime'        => (string) $src['mime'],
            'status'      => 1,
            'create_time' => time(),
        ]);
        // 列表缓存失效
        StickerCache::forget();

        return json(['code' => 0, 'msg' => '已添加到表情包']);
    }

    /**
     * 上传表情包（业务接口）
     *
     * 接收方式：raw body（`application/octet-stream`），文件内容即原始字节，
     * 文件名经 query 参数 `filename` 传递。这样走 `php://input`，
     * 绕开 PHP 的 multipart 上传机制（不依赖 upload_tmp_dir，
     * 避免 Windows 上传临时目录配置导致响应体被 Notice 污染）。
     *
     * 客户端已压缩（240x240 / ≤256KB / GIF 保动画），此处兜底校验防绕过：
     *   - 真实图片（getimagesizefromstring 校验，防止伪装文件）
     *   - 大小 ≤ 256KB
     *   - 边长 ≤ 240px
     */
    public function upload()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $bytes = file_get_contents('php://input');
        if ($bytes === false || strlen($bytes) === 0) {
            return json(['code' => 1, 'msg' => '请选择表情包图片']);
        }

        // 1) 大小校验（严格：客户端应已压缩到 256KB 内）
        if (strlen($bytes) > self::SIZE_MAX) {
            return json(['code' => 1, 'msg' => '表情包不能超过256KB，请压缩后上传']);
        }

        // 2) MIME 校验（根据文件签名识别真实格式）
        $mime = $this->sniffMime($bytes);
        if ($mime === null || !in_array($mime, self::MIME_ALLOWED, true)) {
            return json(['code' => 1, 'msg' => '仅支持 jpg/png/gif/webp 图片']);
        }

        // 3) 真实图片 + 尺寸校验（防伪装文件 / 防客户端绕过不压缩）
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return json(['code' => 1, 'msg' => '图片已损坏，请重新选择']);
        }
        $width  = (int) $info[0];
        $height = (int) $info[1];
        if ($width <= 0 || $height <= 0) {
            return json(['code' => 1, 'msg' => '图片尺寸异常']);
        }
        if ($width > self::SIDE_MAX || $height > self::SIDE_MAX) {
            return json(['code' => 1, 'msg' => '表情包尺寸不能超过' . self::SIDE_MAX . 'px，请压缩后上传']);
        }

        // 4) 落盘（直接写原始字节）
        $base = app()->getRootPath() . 'public/static/sticker';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $ext  = $this->extByMime($mime);
        $name = 's' . $uid . '_' . date('YmdHis') . '_' . substr(md5((string) microtime(true) . rand()), 0, 8) . '.' . $ext;
        $full = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $name;
        $ok   = @file_put_contents($full, $bytes);
        if ($ok === false) {
            return json(['code' => 1, 'msg' => '上传失败，请重试']);
        }
        @chmod($full, 0666 & ~umask());
        $url = '/static/sticker/' . $name;

        // 5) 入库
        $id = Db::name('sticker')->insertGetId([
            'user_id'     => $uid,
            'url'         => $url,
            'width'       => $width,
            'height'      => $height,
            'size'        => (int) strlen($bytes),
            'mime'        => $ext === 'gif' ? 'gif' : 'png',
            'status'      => 1,
            'create_time' => time(),
        ]);

        // 列表缓存失效
        StickerCache::forget();

        return json(['code' => 0, 'msg' => '上传成功', 'data' => [
            'id'     => (int) $id,
            'url'    => $url,
            'width'  => $width,
            'height' => $height,
            'mime'   => $ext === 'gif' ? 'gif' : 'png',
        ]]);
    }

    /** 根据文件头签名识别 MIME（jpg/png/gif/webp），无法识别返回 null */
    private function sniffMime(string $bytes): ?string
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        // PNG
        if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        // GIF（GIF87a / GIF89a）
        if (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a') {
            return 'image/gif';
        }
        // JPEG（FFD8）
        if (ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xD8) {
            return 'image/jpeg';
        }
        // WebP（RIFF....WEBP）
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    /** MIME → 存储后缀 */
    private function extByMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'png',
        };
    }
}
