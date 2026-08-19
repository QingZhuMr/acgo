<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;
use app\common\Password;

/**
 * 表情包管理（覆盖已有表情包功能）
 *  - sticker 表：用户上传的表情（user_id=0 表示平台表情）
 */
class Sticker extends BaseController
{
    public function index()
    {
        return view('sticker/index');
    }

    public function getList()
    {
        $uid    = (int) $this->request->param('uid', 0);
        $status = $this->request->param('status', '');
        $kw     = trim((string) $this->request->param('keyword', ''));

        $query = Db::name('sticker')->alias('s')
            ->join('user u', 'u.id = s.user_id', 'LEFT');

        if ($uid > 0) {
            $query->where('s.user_id', $uid);
        }
        if ($status !== '') {
            $query->where('s.status', (int) $status);
        }
        if ($kw !== '') {
            $query->where('s.url', 'like', '%' . $kw . '%');
        }

        $count = $query->count();
        $list  = $query->field('s.*, u.nickname as user_nickname')
            ->order('s.id', 'desc')
            ->paginate([
                'list_rows' => (int) $this->request->param('limit', 15),
                'page'      => (int) $this->request->param('page', 1),
            ]);

        return json(['code' => 0, 'msg' => '', 'count' => $count, 'data' => $list->items()]);
    }

    /** 上传新增（存为平台表情 user_id=0） */
    public function add()
    {
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        $file = $this->request->file('file');
        if (!$file) {
            return json(['code' => 1, 'msg' => '请选择图片文件']);
        }
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return json(['code' => 1, 'msg' => '仅支持 png/jpg/gif/webp']);
        }
        $size = $file->getSize();
        if ($size > 1024 * 1024) {
            return json(['code' => 1, 'msg' => '图片不能超过 1MB']);
        }
        $info = @getimagesize($file->getPathname());
        if (!$info) {
            return json(['code' => 1, 'msg' => '不是有效图片']);
        }
        $width  = $info[0];
        $height = $info[1];
        $mime   = $info['mime'];

        $root = app()->getRootPath() . 'public';
        $dir  = $root . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'sticker';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $newName = date('YmdHis') . '_' . rand(1000, 9999) . '.' . $ext;
        $saved   = $file->move($dir, $newName);
        if (!$saved) {
            return json(['code' => 1, 'msg' => '保存失败']);
        }
        $url = '/static/sticker/' . $newName;
        Db::name('sticker')->insert([
            'user_id'     => 0,
            'url'         => $url,
            'width'       => $width,
            'height'      => $height,
            'size'        => $size,
            'mime'        => $mime,
            'status'      => 1,
            'create_time' => time(),
        ]);
        $this->log('sticker_add', 'url=' . $url);
        return json(['code' => 0, 'msg' => '上传成功']);
    }

    /** 切换状态（1上架 / 0下架） */
    public function setStatus()
    {
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        Db::name('sticker')->where('id', $id)->update(['status' => $status]);
        $this->log('sticker_set_status', 'id=' . $id . ',status=' . $status);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /** 删除（物理删除，保留磁盘文件） */
    public function del()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if (!$this->checkPwd()) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }
        $row = Db::name('sticker')->where('id', $id)->find();
        if ($row) {
            Db::name('sticker')->where('id', $id)->delete();
            $this->log('sticker_del', 'url=' . ($row['url'] ?? ''));
        }
        return json(['code' => 0, 'msg' => '已删除']);
    }

    protected function checkPwd(): bool
    {
        $plain = (string) $this->request->post('admin_password', '');
        $admin = Db::name('admin')->where('id', Session::get('admin_id'))->find();
        if (!$admin) {
            return false;
        }
        return Password::verify($plain, $admin['password']);
    }

    protected function log(string $action, string $target): void
    {
        $admin = Db::name('admin')->where('id', Session::get('admin_id'))->find();
        Db::name('admin_log')->insert([
            'admin_id'    => Session::get('admin_id'),
            'admin_name'  => $admin['username'] ?? '',
            'action'      => $action,
            'target'      => $target,
            'ip'          => $this->request->ip(),
            'create_time' => time(),
        ]);
    }
}
