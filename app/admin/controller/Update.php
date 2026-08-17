<?php
/**
 * 客户端版本更新推送 控制器
 *
 * 路由示例（自动绑定）：
 *   GET  /admin/update/check        客户端检查更新（公开）
 *   GET  /admin/update/index        管理页（需管理员登录）
 *   GET  /admin/update/list         拉取版本列表（API）
 *   POST /admin/update/add          新增版本
 *   POST /admin/update/edit         编辑版本
 *   POST /admin/update/delete       删除版本
 *   POST /admin/update/toggle        上下线
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\Pusher;
use app\common\UpdateCache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Update extends BaseController
{
    /**
     * 客户端检查更新
     * GET /admin/update/check?platform=win&client_version=0.1.0
     */
    public function check()
    {
        $platform = strtolower((string) $this->request->get('platform', 'win'));
        $clientVer = (string) $this->request->get('client_version', '');

        if (!in_array($platform, ['win', 'mac', 'ios', 'android'], true)) {
            return $this->jsonOut(1, '无效的平台');
        }

        // 取该平台最新且上线的版本
        $row = Db::name('update_version')
            ->where('platform', $platform)
            ->where('status', 1)
            ->order('id', 'desc')
            ->find();

        if (!$row) {
            return $this->jsonOut(0, '无可用更新', []);
        }

        // 客户端版本号 >= 数据库中最新版本号 => 无更新
        if ($clientVer !== '' && version_compare($clientVer, $row['version'], '>=')) {
            return $this->jsonOut(0, '已是最新', []);
        }

        $data = [
            'version'      => $row['version'],
            'title'        => $row['title'],
            'message'      => $row['message'],
            'platform'     => $row['platform'],
            'download_url' => $row['download_url'],
            'mandatory'    => (bool) $row['mandatory'],
        ];
        return $this->jsonOut(0, '有新版本', $data);
    }

    /**
     * 管理页（页面视图）
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 列表
     */
    public function list()
    {
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 20);
        $platform = (string) $this->request->get('platform', '');
        $keyword = (string) $this->request->get('keyword', '');

        $where = [];
        if ($platform !== '') {
            $where[] = ['platform', '=', $platform];
        }
        if ($keyword !== '') {
            $where[] = ['version|title', 'like', "%{$keyword}%"];
        }

        $total = Db::name('update_version')->where($where)->count();
        $list = Db::name('update_version')
            ->where($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return json([
            'code' => 0,
            'msg'  => '',
            'count' => $total,
            'data'  => $list,
        ]);
    }

    /**
     * 新增版本
     */
    public function add()
    {
        $data = $this->pullInput();
        $err = $this->validateFields($data);
        if ($err !== null) return $err;

        $now = time();
        $id = Db::name('update_version')->insertGetId([
            'platform'     => $data['platform'],
            'version'      => $data['version'],
            'title'        => $data['title'],
            'message'      => $data['message'],
            'download_url' => $data['download_url'],
            'mandatory'    => (int) ($data['mandatory'] ?? 0),
            'status'       => (int) ($data['status'] ?? 1),
            'create_time'  => $now,
            'update_time'  => $now,
        ]);
        $this->log('add', '新增客户端版本推送 id=' . $id);
        // 版本数据变化，清平台缓存
        UpdateCache::forget($data['platform']);

        // 新增即为上线状态时，实时推送该平台在线用户
        if (1 === (int) $data['status']) {
            Pusher::broadcastUpdate($data['platform'], $this->versionPayload($data));
        }

        return $this->jsonOut(0, '已添加', ['id' => $id]);
    }

    /**
     * 编辑版本
     */
    public function edit()
    {
        $data = $this->pullInput();
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) return $this->jsonOut(1, '参数错误');

        $err = $this->validateFields($data);
        if ($err !== null) return $err;

        Db::name('update_version')->where('id', $id)->update([
            'platform'     => $data['platform'],
            'version'      => $data['version'],
            'title'        => $data['title'],
            'message'      => $data['message'],
            'download_url' => $data['download_url'],
            'mandatory'    => (int) ($data['mandatory'] ?? 0),
            'status'       => (int) ($data['status'] ?? 1),
            'update_time'  => time(),
        ]);
        $this->log('edit', '编辑客户端版本推送 id=' . $id);
        // 版本数据变化，清平台缓存
        UpdateCache::forget($data['platform']);

        // 编辑后若为上线状态，实时推送该平台在线用户（客户端自行判断版本高低）
        if (1 === (int) $data['status']) {
            Pusher::broadcastUpdate($data['platform'], $this->versionPayload($data));
        }

        return $this->jsonOut(0, '已更新', []);
    }

    /**
     * 删除版本
     */
    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) return $this->jsonOut(1, '参数错误');
        $row = Db::name('update_version')->where('id', $id)->find();
        if (!$row) return $this->jsonOut(1, '版本不存在');
        Db::name('update_version')->where('id', $id)->delete();
        $this->log('delete', '删除客户端版本推送 id=' . $id);
        // 版本数据变化，清对应平台缓存
        UpdateCache::forget((string) $row['platform']);
        return $this->jsonOut(0, '已删除', []);
    }

    /**
     * 上下线
     */
    public function toggle()
    {
        $id = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);
        if ($id <= 0) return $this->jsonOut(1, '参数错误');
        $row = Db::name('update_version')->where('id', $id)->find();
        if (!$row) return $this->jsonOut(1, '版本不存在');

        Db::name('update_version')->where('id', $id)->update([
            'status'      => $status,
            'update_time' => time(),
        ]);
        $this->log('toggle', '切换版本状态 id=' . $id . ' -> ' . $status);
        // 版本状态变化，清对应平台缓存
        UpdateCache::forget((string) $row['platform']);

        // 上线时实时推送该平台所有在线用户
        if (1 === (int) $status) {
            Pusher::broadcastUpdate((string) $row['platform'], $this->versionPayload($row));
        }

        return $this->jsonOut(0, '已切换', []);
    }

    /**
     * 拉取 POST 输入
     */
    private function pullInput(): array
    {
        $input = $this->request->post();
        return is_array($input) ? $input : [];
    }

    /**
     * 公共字段校验
     */
    private function validateFields(array $data)
    {
        if (empty($data['platform']) || !in_array($data['platform'], ['win', 'mac', 'ios', 'android'], true)) {
            return $this->jsonOut(1, '请选择有效的平台');
        }
        if (empty($data['version'])) {
            return $this->jsonOut(1, '请填写版本号');
        }
        if (empty($data['title'])) {
            return $this->jsonOut(1, '请填写标题');
        }
        if (empty($data['download_url'])) {
            return $this->jsonOut(1, '请填写下载/跳转地址');
        }
        return null;
    }

    /**
     * 写管理员操作日志（复用当前登录管理员）
     * @param string $action 行为标识（AdminLog::ACTION_MAP 中的键）
     * @param string $remark 备注
     */
    protected function log(string $action, string $remark = '')
    {
        $adminId = (int) Session::get('admin_id', 0);
        $adminName = (string) Session::get('admin_username', 'admin');
        AdminLog::write($adminId, $adminName, $action, $this->request->ip(), $remark);
    }

    /**
     * 构造推送给客户端的版本信息体（含 platform，供客户端判断）
     */
    private function versionPayload(array $row): array
    {
        return [
            'platform'     => (string) $row['platform'],
            'version'      => (string) $row['version'],
            'title'        => (string) $row['title'],
            'message'      => (string) $row['message'],
            'download_url' => (string) $row['download_url'],
            'mandatory'    => (bool) $row['mandatory'],
        ];
    }

    /**
     * 统一 JSON 输出
     */
    private function jsonOut(int $code, string $msg, $data = null)
    {
        $out = ['code' => $code, 'msg' => $msg];
        if ($data !== null) $out['data'] = $data;
        return json($out);
    }
}
