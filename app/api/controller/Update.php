<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\UpdateCache;
use think\facade\Db;

/**
 * 客户端版本更新查询接口（api 应用）
 *
 * 与 admin 应用隔离：admin 负责推送（维护数据），api 负责对外查询。
 * 客户端仅访问本接口，不暴露后台域名。
 *
 * 路由（绑定 api 域名）：
 *   GET /update/check?platform=win&client_version=0.1.0
 *
 * 返回：
 *   code 0 + data 为空对象 => 无可用更新
 *   code 0 + data 有内容     => 有新版本
 */
class Update extends BaseController
{
    /**
     * 客户端检查更新
     */
    public function check()
    {
        $platform = strtolower((string) $this->request->get('platform', 'win'));
        $clientVer = (string) $this->request->get('client_version', '');

        if (!in_array($platform, ['win', 'mac', 'ios', 'android'], true)) {
            return $this->jsonOut(1, '无效的平台');
        }

        // 取该平台最新且上线的版本（走 UpdateCache，Redis 缓存 TTL 10 分钟）
        $row = UpdateCache::latest($platform);

        if (!$row) {
            return $this->jsonOut(0, '无可用更新', new \stdClass());
        }

        // 客户端版本 >= 最新版本 => 无更新
        if ($clientVer !== '' && version_compare($clientVer, $row['version'], '>=')) {
            return $this->jsonOut(0, '已是最新', new \stdClass());
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
     * 统一 JSON 输出
     */
    private function jsonOut(int $code, string $msg, $data = null)
    {
        $out = ['code' => $code, 'msg' => $msg];
        if ($data !== null) $out['data'] = $data;
        return json($out);
    }
}
