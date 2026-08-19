<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Index extends BaseController
{
    /**
     * 后台主页
     * session 仅保存 admin_id，权限等级等从数据库实时查询
     */
    public function index()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();

        if (!$admin) {
            return redirect('/login/index');
        }

        $adminLevel = (int) $admin['level'];

        // 读取启用且权限等级匹配的菜单，按 sort 排序
        $menus = Db::name('menu')
            ->where('status', 1)
            ->where('level', '<=', $adminLevel)
            ->order('sort asc, id asc')
            ->select()
            ->toArray();

        // 组装树
        $menuTree = $this->buildTree($menus);

        // 应用名称（来自系统设置，默认 ACGO 后台）
        $appName = (string) Db::name('config')->where('name', 'app_name')->value('value');
        if ('' === $appName) {
            $appName = 'ACGO 后台';
        }

        View::assign([
            'admin_id'    => $admin['id'],
            'username'    => $admin['username'],
            'nickname'    => $admin['nickname'],
            'level'       => $adminLevel,
            'menu_tree'   => $menuTree,
            'menu_tree_json' => json_encode($menuTree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'app_name'    => $appName,
        ]);
        return View::fetch();
    }

    /**
     * iframe 默认欢迎页（当前登录信息）
     */
    public function welcome()
    {
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();

        if (!$admin) {
            return redirect('/login/index');
        }

        View::assign([
            'admin_id' => $admin['id'],
            'username' => $admin['username'],
            'nickname' => $admin['nickname'],
            'level'    => (int) $admin['level'],
        ]);
        return View::fetch();
    }

    /**
     * 构建菜单树
     */
    protected function buildTree(array $menus, int $parentId = 0): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if ((int) $menu['parent_id'] === $parentId) {
                $children = $this->buildTree($menus, (int) $menu['id']);
                if ($children) {
                    $menu['children'] = $children;
                }
                $tree[] = $menu;
            }
        }
        return $tree;
    }

    /**
     * 充值总额统计（按币种汇总 status=3 已完成）
     * GET /index/rechargeStat
     */
    public function rechargeStat()
    {
        // 货币中文名（双轨制：人民币 + 熊爪）
        $curName = ['cny' => '人民币', 'paw' => '熊爪'];
        $curSym  = ['cny' => '¥', 'paw' => ''];

        // 按币种汇总已完成（status=3）的总金额
        $rows = Db::name('recharge')
            ->where('status', 3)
            ->field('currency, SUM(amount) AS total, COUNT(*) AS cnt')
            ->group('currency')
            ->select()
            ->toArray();

        $byCurrency = [];
        $totalCount = 0;
        foreach ($rows as $r) {
            $cur = strtolower((string) $r['currency']);
            $byCurrency[] = [
                'currency'      => $cur,
                'currency_name' => $curName[$cur] ?? strtoupper($cur),
                'symbol'        => $curSym[$cur] ?? '',
                'total'         => 'paw' === $cur ? number_format((float) $r['total'], 0, '.', '') : number_format((float) $r['total'], 2, '.', ''),
                'count'         => (int) $r['cnt'],
            ];
            $totalCount += (int) $r['cnt'];
        }

        // 总体统计（不含币种区分）
        $grand = Db::name('recharge')->where('status', 3)->count();

        return json([
            'code'          => 0,
            'msg'           => 'ok',
            'grand_count'   => $grand,
            'total_count'   => $totalCount,
            'by_currency'   => $byCurrency,
        ]);
    }

    /**
     * 用户注册数量走势（最近 N 天每日新增）
     * GET /index/userRegTrend?days=12
     */
    public function userRegTrend()
    {
        $days = max(1, min(60, (int) $this->request->param('days', 12)));

        // 计算日期序列（从 days-1 天前到今天）
        $today    = strtotime(date('Y-m-d'));
        $labels   = [];
        $startTs  = $today - ($days - 1) * 86400;
        for ($i = 0; $i < $days; $i++) {
            $labels[] = date('m-d', $startTs + $i * 86400);
        }

        // 按 register_time 分组统计（用户表 register_time 是 int 时间戳）
        $rows = Db::name('user')
            ->field("FROM_UNIXTIME(register_time, '%Y-%m-%d') AS d, COUNT(*) AS cnt")
            ->where('register_time', '>=', $startTs)
            ->where('register_time', '<', $today + 86400)
            ->group('d')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = (int) $r['cnt'];
        }

        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $key = date('Y-m-d', $startTs + $i * 86400);
            $data[] = isset($map[$key]) ? $map[$key] : 0;
        }

        return json([
            'code'   => 0,
            'msg'    => 'ok',
            'labels' => $labels,
            'data'   => $data,
        ]);
    }
}
