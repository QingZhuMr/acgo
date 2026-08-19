<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;

class Menu extends BaseController
{
    /**
     * 菜单管理页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 获取菜单列表（按层级深度优先排序，普通表格即可展示多级）
     */
    public function getList()
    {
        $menus = Db::name('menu')->order('sort asc, id asc')->select()->toArray();

        // 建立父子映射（按排序）
        $byParent = [];
        $map      = [];
        foreach ($menus as $m) {
            $byParent[$m['parent_id']][] = $m;
            $map[$m['id']] = $m['title'];
        }

        // 深度优先遍历，生成扁平序列并计算每行深度
        $flat  = [];
        $walk  = function (int $pid, int $depth) use (&$walk, &$flat, $byParent) {
            if (empty($byParent[$pid])) {
                return;
            }
            foreach ($byParent[$pid] as $m) {
                $m['depth'] = $depth;
                $flat[]     = $m;
                $walk((int) $m['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        // 补充父级标题与层级缩进展示文本
        foreach ($flat as &$m) {
            $depth = (int) $m['depth'];
            $m['parent_title']  = isset($map[$m['parent_id']]) ? $map[$m['parent_id']] : '顶级菜单';
            $m['title_display'] = str_repeat('　　', $depth) . ($depth > 0 ? '└─ ' : '') . $m['title'];
        }
        unset($m);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $flat]);
    }

    /**
     * 获取父级菜单选项（所有菜单均可作为父级，带层级缩进，支持多级）
     */
    public function getParents()
    {
        $menus = Db::name('menu')->order('sort asc, id asc')->select()->toArray();

        // 深度优先遍历生成带缩进的父级选项
        $byParent = [];
        foreach ($menus as $m) {
            $byParent[$m['parent_id']][] = $m;
        }

        $list = [];
        $walk = function (int $pid, int $depth) use (&$walk, &$list, $byParent) {
            if (empty($byParent[$pid])) {
                return;
            }
            foreach ($byParent[$pid] as $m) {
                $list[] = ['id' => $m['id'], 'title' => str_repeat('　　', $depth) . $m['title']];
                $walk((int) $m['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        // 顶级菜单选项放最前
        array_unshift($list, ['id' => 0, 'title' => '顶级菜单']);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $list]);
    }

    /**
     * 获取单个菜单信息（用于编辑回显）
     */
    public function getOne()
    {
        $id = (int) $this->request->param('id', 0);
        $menu = Db::name('menu')->where('id', $id)->find();
        if (!$menu) {
            return json(['code' => 1, 'msg' => '菜单不存在']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $menu]);
    }

    /**
     * 新增菜单
     */
    public function add()
    {
        $data = $this->buildData();
        if (empty($data['title'])) {
            return json(['code' => 1, 'msg' => '菜单名称不能为空']);
        }

        // 校验父级菜单是否存在
        if ($data['parent_id'] > 0 && !Db::name('menu')->where('id', $data['parent_id'])->find()) {
            return json(['code' => 1, 'msg' => '父级菜单不存在']);
        }

        $data['create_time'] = time();
        $data['update_time'] = time();

        $id = Db::name('menu')->insertGetId($data);
        if ($id) {
            return json(['code' => 0, 'msg' => '新增成功']);
        }
        return json(['code' => 1, 'msg' => '新增失败']);
    }

    /**
     * 编辑菜单
     */
    public function edit()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $data = $this->buildData();
        if (empty($data['title'])) {
            return json(['code' => 1, 'msg' => '菜单名称不能为空']);
        }

        // 父级不能是自己或其子级，避免形成死循环
        if ($data['parent_id'] === $id) {
            return json(['code' => 1, 'msg' => '父级菜单不能选择自身']);
        }
        if ($data['parent_id'] > 0) {
            if (in_array($data['parent_id'], $this->collectChildIds($id), true)) {
                return json(['code' => 1, 'msg' => '父级菜单不能选择自己的子级']);
            }
            if (!Db::name('menu')->where('id', $data['parent_id'])->find()) {
                return json(['code' => 1, 'msg' => '父级菜单不存在']);
            }
        }

        $data['update_time'] = time();

        Db::name('menu')->where('id', $id)->update($data);
        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 切换菜单启用/禁用状态
     */
    public function toggle()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id || !Db::name('menu')->where('id', $id)->find()) {
            return json(['code' => 1, 'msg' => '菜单不存在']);
        }
        $status = (int) $this->request->post('status', 0) === 1 ? 1 : 0;

        Db::name('menu')->where('id', $id)->update([
            'status'      => $status,
            'update_time' => time(),
        ]);
        return json(['code' => 0, 'msg' => $status === 1 ? '已启用' : '已禁用']);
    }

    /**
     * 删除菜单（含其子级）
     */
    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        // 菜单不存在
        if (!Db::name('menu')->where('id', $id)->find()) {
            return json(['code' => 1, 'msg' => '菜单不存在']);
        }

        // 收集所有要删除的 id（含子级）
        $ids = $this->collectChildIds($id);
        $ids[] = $id;

        Db::name('menu')->whereIn('id', $ids)->delete();
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 收集子菜单 id
     */
    protected function collectChildIds(int $parentId): array
    {
        $ids = [];
        $children = Db::name('menu')->where('parent_id', $parentId)->column('id');
        foreach ($children as $cid) {
            $ids[] = $cid;
            $ids = array_merge($ids, $this->collectChildIds($cid));
        }
        return $ids;
    }

    /**
     * 组装表单数据（新增/编辑共用）
     */
    protected function buildData(): array
    {
        return [
            'parent_id' => (int) $this->request->post('parent_id', 0),
            'title'     => trim((string) $this->request->post('title', '')),
            'icon'      => trim((string) $this->request->post('icon', '')),
            'url'       => trim((string) $this->request->post('url', '')),
            'level'     => (int) $this->request->post('level', 1),
            'sort'      => (int) $this->request->post('sort', 0),
            'status'    => (int) $this->request->post('status', 1),
        ];
    }

    /**
     * 构建菜单树
     */
    protected function buildTree(array $menus, int $parentId = 0): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if ($menu['parent_id'] == $parentId) {
                $children = $this->buildTree($menus, (int) $menu['id']);
                if ($children) {
                    $menu['children'] = $children;
                }
                $tree[] = $menu;
            }
        }
        return $tree;
    }
}
