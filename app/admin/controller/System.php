<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\SensitiveLexicon;
use think\facade\Db;
use think\facade\View;

class System extends BaseController
{
    /**
     * 系统设置页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 获取系统设置
     */
    public function getConfig()
    {
        $data = Db::name('config')->column('value', 'name');

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'app_name'            => isset($data['app_name']) ? $data['app_name'] : '',
                'ip_loc_provider'     => (string) ($data['ip_loc_provider'] ?? 'amap'),
                'amap_key'            => (string) ($data['amap_key'] ?? ''),
                'qqmap_key'           => (string) ($data['qqmap_key'] ?? ''),
                'qqmap_sk'            => (string) ($data['qqmap_sk'] ?? ''),
                'baidu_ak'            => (string) ($data['baidu_ak'] ?? ''),
                'maintenance_enabled' => '1' === (string) ($data['maintenance_enabled'] ?? '0') ? '1' : '0',
                'maintenance_notice'  => (string) ($data['maintenance_notice'] ?? ''),
                // 运营设置：绑定对象未上线自动解除 + 补偿
                'unbind_auto_days'         => (string) ($data['unbind_auto_days'] ?? '3'),
                'unbind_auto_compensation' => (string) ($data['unbind_auto_compensation'] ?? '20'),
                'lexicon'             => SensitiveLexicon::info(),
                'custom_words'        => $this->customWords(),
            ],
        ]);
    }

    /**
     * 平台自定义敏感词列表（sensitive_word 表）
     *
     * @return array<int, array{id:int, word:string}>
     */
    protected function customWords(): array
    {
        try {
            $list = Db::name('sensitive_word')->field('id, word')->order('id desc')->select()->toArray();
            return is_array($list) ? array_values($list) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 添加自定义敏感词
     */
    public function addWord()
    {
        $word = trim((string) $this->request->post('word', ''));
        $word = preg_replace('/\s+/u', '', $word) ?? '';

        if ('' === $word) {
            return json(['code' => 1, 'msg' => '敏感词不能为空']);
        }
        if (mb_strlen($word) > 100) {
            return json(['code' => 1, 'msg' => '敏感词过长']);
        }

        try {
            $exists = Db::name('sensitive_word')->where('word', $word)->find();
            if ($exists) {
                return json(['code' => 1, 'msg' => '该敏感词已存在']);
            }
            Db::name('sensitive_word')->insert([
                'word'        => $word,
                'create_time' => time(),
            ]);
        } catch (\Throwable) {
            return json(['code' => 1, 'msg' => '添加失败，请稍后重试']);
        }

        return json(['code' => 0, 'msg' => '添加成功', 'data' => $this->customWords()]);
    }

    /**
     * 删除自定义敏感词
     */
    public function delWord()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        try {
            Db::name('sensitive_word')->where('id', $id)->delete();
        } catch (\Throwable) {
            return json(['code' => 1, 'msg' => '删除失败，请稍后重试']);
        }

        return json(['code' => 0, 'msg' => '删除成功', 'data' => $this->customWords()]);
    }

    /**
     * 保存系统设置
     */
    public function save()
    {
        $appName = trim((string) $this->request->post('app_name', ''));
        $ipLocProvider = (string) $this->request->post('ip_loc_provider', 'amap');
        $amapKey = trim((string) $this->request->post('amap_key', ''));
        $qqmapKey = trim((string) $this->request->post('qqmap_key', ''));
        $qqmapSk = trim((string) $this->request->post('qqmap_sk', ''));
        $baiduAk = trim((string) $this->request->post('baidu_ak', ''));
        $maintEnabled = (string) $this->request->post('maintenance_enabled', '0');
        $maintNotice = trim((string) $this->request->post('maintenance_notice', ''));

        if ('' === $appName) {
            return json(['code' => 1, 'msg' => '应用名称不能为空']);
        }
        if (!in_array($ipLocProvider, ['amap', 'qq', 'baidu'], true)) {
            return json(['code' => 1, 'msg' => 'IP 定位服务商参数错误']);
        }
        if (mb_strlen($amapKey) > 128) {
            return json(['code' => 1, 'msg' => '高德 Key 过长']);
        }
        if (mb_strlen($qqmapKey) > 128) {
            return json(['code' => 1, 'msg' => '腾讯 Key 过长']);
        }
        if (mb_strlen($qqmapSk) > 128) {
            return json(['code' => 1, 'msg' => '腾讯 SK 过长']);
        }
        if (mb_strlen($baiduAk) > 128) {
            return json(['code' => 1, 'msg' => '百度 AK 过长']);
        }
        if (!in_array($maintEnabled, ['0', '1'], true)) {
            return json(['code' => 1, 'msg' => '维护开关参数错误']);
        }
        if (mb_strlen($maintNotice) > 500) {
            return json(['code' => 1, 'msg' => '维护公告最长500字']);
        }

        $now = time();
        $this->setConfig('app_name', $appName, '系统应用名称', $now);
        $this->setConfig('ip_loc_provider', $ipLocProvider, 'IP 定位服务商（amap/qq/baidu）', $now);
        $this->setConfig('amap_key', $amapKey, '高德 Web 服务 Key（IP 定位）', $now);
        $this->setConfig('qqmap_key', $qqmapKey, '腾讯位置服务 Key（IP 定位）', $now);
        $this->setConfig('qqmap_sk', $qqmapSk, '腾讯位置服务 SK（签名校验，可选）', $now);
        $this->setConfig('baidu_ak', $baiduAk, '百度地图 AK（IP 定位）', $now);
        $this->setConfig('maintenance_enabled', $maintEnabled, '平台维护开关（1开启/0关闭）', $now);
        $this->setConfig('maintenance_notice', $maintNotice, '平台维护公告', $now);

        // 维护状态变更：通过 push_queue 实时广播给所有在线客户端
        try {
            Db::name('push_queue')->insert([
                'user_id'     => 0,   // 0 = 广播所有在线用户
                'platform'    => '',
                'device_id'   => '',
                'payload'     => json_encode([
                    'type'    => 'maintenance',
                    'enabled' => '1' === $maintEnabled,
                    'notice'  => $maintNotice,
                ], JSON_UNESCAPED_UNICODE),
                'pushed'      => 0,
                'create_time' => $now,
            ]);
        } catch (\Throwable $e) {
            // 广播失败不影响保存结果
        }

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 保存运营设置（与系统设置分开保存）
     */
    public function saveOperation()
    {
        $days = (int) $this->request->post('unbind_auto_days', 3);
        $comp = (int) $this->request->post('unbind_auto_compensation', 20);

        if ($days < 1 || $days > 365) {
            return json(['code' => 1, 'msg' => '未上线自动解除天数需在 1-365 之间']);
        }
        if ($comp < 0 || $comp > 1000000) {
            return json(['code' => 1, 'msg' => '补偿熊爪需在 0-1000000 之间']);
        }

        $now = time();
        $this->setConfig('unbind_auto_days', (string) $days, '绑定对象未上线自动解除天数', $now);
        $this->setConfig('unbind_auto_compensation', (string) $comp, '自动解除绑定补偿熊爪数量', $now);

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 更新敏感词库（从 GitHub 拉取、二次过滤后保存本地 JSON）
     */
    public function updateLexicon()
    {
        $result = SensitiveLexicon::update();

        if (!$result['ok']) {
            return json(['code' => 1, 'msg' => $result['error']]);
        }

        return json([
            'code' => 0,
            'msg'  => '敏感词库更新成功，共 ' . $result['count'] . ' 词',
            'data' => SensitiveLexicon::info(),
        ]);
    }

    /**
     * 写入配置项（存在则更新，不存在则新增）
     */
    protected function setConfig(string $name, string $value, string $remark, int $now)
    {
        if (Db::name('config')->where('name', $name)->find()) {
            Db::name('config')->where('name', $name)->update([
                'value'       => $value,
                'remark'      => $remark,
                'update_time' => $now,
            ]);
        } else {
            Db::name('config')->insert([
                'name'        => $name,
                'value'       => $value,
                'remark'      => $remark,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }
}
