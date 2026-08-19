<?php
/**
 * 星球管理 控制器
 *
 * 路由示例（自动绑定）：
 *   GET  /admin/planet/index        管理页（需管理员登录）
 *   GET  /admin/planet/getList      列表（API）
 *   POST /admin/planet/add          新增（种子自动生成）
 *   POST /admin/planet/edit         编辑（名称/介绍/排序/状态）
 *   POST /admin/planet/delete       删除
 *   POST /admin/planet/toggle       上下线
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Planet extends BaseController
{
    /**
     * 管理页（页面视图）
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 列表（分页 + 搜索）
     */
    public function getList()
    {
        $page   = max(1, (int) $this->request->get('page', 1));
        $limit  = min(100, max(1, (int) $this->request->get('limit', 20)));
        $kw     = trim((string) $this->request->get('keyword', ''));
        $status = $this->request->get('status', '');

        $where = [];
        if ('' !== $kw) {
            $where[] = ['name|intro', 'like', "%{$kw}%"];
        }
        if ('' !== $status) {
            $where[] = ['status', '=', (int) $status];
        }

        $total = Db::name('planet')->where($where)->count();
        $list  = Db::name('planet')
            ->where($where)
            ->order('sort asc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['status_text']      = (int) $item['status'] === 1 ? '已启用' : '已禁用';
            $item['status_badge']     = (int) $item['status'] === 1 ? 'badge-green' : 'badge-gray';
            $item['create_time_text'] = $item['create_time']
                ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
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
     * 新增星球（种子自动生成：random_int，保证同种子同星球）
     */
    public function add()
    {
        $name  = trim((string) $this->request->post('name', ''));
        $intro = trim((string) $this->request->post('intro', ''));
        $sort  = (int) $this->request->post('sort', 0);

        if ('' === $name) {
            return json(['code' => 1, 'msg' => '星球名称不能为空']);
        }
        if (mb_strlen($name) > 50) {
            return json(['code' => 1, 'msg' => '星球名称最长 50 字']);
        }
        if (mb_strlen($intro) > 500) {
            return json(['code' => 1, 'msg' => '星球介绍最长 500 字']);
        }

        $now = time();
        $id  = Db::name('planet')->insertGetId([
            'seed'        => random_int(1, 99999999),
            'name'        => $name,
            'intro'       => $intro,
            'sort'        => $sort,
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->log('planet_create', '创建星球 id=' . $id . ' 名称=' . $name);
        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    /**
     * 编辑星球（名称/介绍/排序/状态；种子创建时已定，不允许修改）
     */
    public function edit()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $row = Db::name('planet')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        $name   = trim((string) $this->request->post('name', ''));
        $intro  = trim((string) $this->request->post('intro', ''));
        $sort   = (int) $this->request->post('sort', 0);
        $status = (int) $this->request->post('status', 1);

        if ('' === $name) {
            return json(['code' => 1, 'msg' => '星球名称不能为空']);
        }
        if (mb_strlen($name) > 50) {
            return json(['code' => 1, 'msg' => '星球名称最长 50 字']);
        }
        if (mb_strlen($intro) > 500) {
            return json(['code' => 1, 'msg' => '星球介绍最长 500 字']);
        }

        Db::name('planet')->where('id', $id)->update([
            'name'        => $name,
            'intro'       => $intro,
            'sort'        => $sort,
            'status'      => $status === 1 ? 1 : 0,
            'update_time' => time(),
        ]);

        $this->log('planet_edit', '编辑星球 id=' . $id . ' 名称=' . $name);
        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 删除星球
     */
    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $row = Db::name('planet')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        Db::name('planet')->where('id', $id)->delete();
        $this->log('planet_delete', '删除星球 id=' . $id . ' 名称=' . $row['name']);
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 上下线
     */
    public function toggle()
    {
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $row = Db::name('planet')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        Db::name('planet')->where('id', $id)->update([
            'status'      => $status === 1 ? 1 : 0,
            'update_time' => time(),
        ]);
        $this->log('planet_toggle', '切换星球状态 id=' . $id . ' -> ' . ($status === 1 ? '启用' : '禁用'));
        return json(['code' => 0, 'msg' => '已切换']);
    }

    /**
     * 写管理员操作日志（复用当前登录管理员）
     */
    protected function log(string $action, string $remark = '')
    {
        $adminId   = (int) Session::get('admin_id', 0);
        $adminName = (string) Session::get('admin_username', 'admin');
        AdminLog::write($adminId, $adminName, $action, $this->request->ip(), $remark);
    }
}
