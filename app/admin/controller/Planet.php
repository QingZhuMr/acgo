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
    /** 星球类型白名单（key=存储值，value=显示名） */
    const TYPES = [
        'earth'          => '类地行星',
        'jupiter'        => '气态行星',
        'lava'           => '岩浆行星',
        'rock'           => '岩石行星',
        'gradient_green' => '绿色渐变',
        'gradient_blue'  => '蓝色渐变',
        'gradient_pink'  => '粉色渐变',
        'gradient_red'   => '红色渐变',
        'blackhole'      => '黑洞',
    ];

    /**
     * 管理页（页面视图）
     */
    public function index()
    {
        View::assign('types', self::TYPES);
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
            $item['type_text']        = self::TYPES[$item['type']] ?? $item['type'];
            $item['status_text']      = (int) $item['status'] === 1 ? '已启用' : '已禁用';
            $item['status_badge']     = (int) $item['status'] === 1 ? 'badge-green' : 'badge-gray';
            $item['create_time_text'] = $item['create_time']
                ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            // 寻人配置（列表展示用）
            $item['enable_find_text'] = (int) $item['enable_find'] === 1 ? '开启' : '关闭';
            $item['enable_find_badge'] = (int) $item['enable_find'] === 1 ? 'badge-green' : 'badge-gray';
            $item['force_auth_text']  = $this->forceAuthText((int) ($item['force_face_auth'] ?? 0), (int) ($item['force_edu_auth'] ?? 0), (int) ($item['force_asset_auth'] ?? 0));
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
        $type  = trim((string) $this->request->post('type', 'earth'));

        if (!isset(self::TYPES[$type])) {
            return json(['code' => 1, 'msg' => '星球类型不合法']);
        }
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
            'type'        => $type,
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
        $type   = trim((string) $this->request->post('type', $row['type'] ?? 'earth'));

        if (!isset(self::TYPES[$type])) {
            return json(['code' => 1, 'msg' => '星球类型不合法']);
        }
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
            'type'        => $type,
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
     * 保存星球寻人配置（业务接口）
     * POST：id、enable_find(0/1)、find_fields(JSON 数组)、
     *      force_face_auth/force_edu_auth/force_asset_auth(0/1)
     *
     * find_fields 可选值（我的资料字段）：gender/age/income_range/personality/city/face_score/edu_level
     * 性别与年龄为默认必填项（后台可勾选，保存时强制并入）。
     */
    public function findConfig()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $row = Db::name('planet')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        $enableFind = (int) $this->request->post('enable_find', 0) === 1 ? 1 : 0;

        $fieldsRaw = (string) $this->request->post('find_fields', '[]');
        $fields = json_decode($fieldsRaw, true);
        if (!is_array($fields)) {
            return json(['code' => 1, 'msg' => '参数格式错误']);
        }
        $allow = ['gender', 'age', 'income_range', 'personality', 'city', 'face_score', 'edu_level'];
        $fields = array_values(array_unique(array_intersect($fields, $allow)));
        // 性别/年龄 为默认必填项
        if (!in_array('gender', $fields, true)) {
            array_unshift($fields, 'gender');
        }
        if (!in_array('age', $fields, true)) {
            $fields[] = 'age';
        }

        Db::name('planet')->where('id', $id)->update([
            'enable_find'      => $enableFind,
            'find_fields'      => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'force_face_auth'  => (int) $this->request->post('force_face_auth', 0) === 1 ? 1 : 0,
            'force_edu_auth'   => (int) $this->request->post('force_edu_auth', 0) === 1 ? 1 : 0,
            'force_asset_auth' => (int) $this->request->post('force_asset_auth', 0) === 1 ? 1 : 0,
            'update_time'      => time(),
        ]);

        $this->log('planet_find_config', '保存星球寻人配置 id=' . $id . ' 名称=' . $row['name']
            . ' enable_find=' . $enableFind . ' fields=' . json_encode($fields, JSON_UNESCAPED_UNICODE));
        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 强制认证文案（列表展示用）
     */
    protected function forceAuthText(int $face, int $edu, int $asset): string
    {
        $items = [];
        if ($face === 1) {
            $items[] = '颜值';
        }
        if ($edu === 1) {
            $items[] = '学历';
        }
        if ($asset === 1) {
            $items[] = '资产';
        }
        return $items ? '强制' . implode('/', $items) . '认证' : '不强制';
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
