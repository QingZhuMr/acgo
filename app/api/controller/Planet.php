<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\ContentCheck;
use think\facade\Db;

/**
 * 星球接口（星球养成：认领 / 我的星球）
 *
 * 资源体系（初始星球）：
 *   - water 水：与水材质绑定，越多海洋占比越大（视觉映射）
 *   - metal 金属
 *   - rock  岩石
 *   - oxygen 氧气：初始星球恒为 0（后续通过养成获得）
 * 资源由 seed 确定性计算（LCG 哈希），总资源恒为 100（平衡），
 * 但配比随机（有的水多金属少、有的岩石多水少），认领时可见可权衡。
 * 算法与客户端一致（lib/services/planet_materials.dart 的 PlanetResources.forSeed）。
 *
 * 初始星球处于「岩浆活动期」（stage=magma），表面以岩浆材质为主。
 */
class Planet extends BaseController
{
    /** 星球名字最大长度 */
    protected const NAME_MAX = 20;

    /**
     * 我的星球（业务接口：签名 + 设备密钥）
     *
     * GET /api/planet/my
     * 返回 data：星球记录（含 resources 子结构），未认领时 data=null
     */
    public function my()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $planet = Db::name('planet')
            ->where('uid', $uid)
            ->where('status', 1)
            ->find();
        if (!$planet) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => null]);
        }

        $planet['resources'] = [
            'water'  => (int) $planet['water'],
            'metal'  => (int) $planet['metal'],
            'oxygen' => (int) $planet['oxygen'],
            'rock'   => (int) $planet['rock'],
        ];
        return json(['code' => 0, 'msg' => 'ok', 'data' => $planet]);
    }

    /**
     * 认领候选星球（业务接口：签名 + 设备密钥）
     *
     * GET /api/planet/candidates
     * 返回 3 个候选星球（seed + 初始资源），seed 与资源均由服务端下发，
     * 客户端只做展示与选择，无法篡改资源。
     * 已拥有星球时返回 data=null。
     */
    public function candidates()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        // 一人一颗：已有星球则无需候选
        if (Db::name('planet')->where('uid', $uid)->where('status', 1)->find()) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => null]);
        }

        // 生成 3 个随机候选（seed + 资源），避免碰撞
        $seeds = [];
        while (count($seeds) < 3) {
            $seed = mt_rand(1, 0x7fffffff);
            if (!isset($seeds[$seed])) {
                $seeds[$seed] = true;
            }
        }
        $list = [];
        foreach (array_keys($seeds) as $seed) {
            $res = self::resourcesForSeed($seed);
            $list[] = [
                'seed' => $seed,
                'resources' => $res,
            ];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $list]);
    }

    /**
     * 认领星球（业务接口：签名 + 设备密钥）
     *
     * POST /api/planet/claim
     * 参数：seed（int，必须是服务端候选接口下发的 seed）、name（星球名字，敏感词校验）
     * 资源由服务端按 seed 确定性计算，客户端无法修改。
     * 一人一颗：已拥有星球时拒绝重复认领
     */
    public function claim()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $seed = (int) $this->request->post('seed', 0);
        $name = trim((string) $this->request->post('name', ''));

        if ($seed <= 0 || $seed > 0x7fffffff) {
            return json(['code' => 1, 'msg' => '星球种子非法']);
        }
        if ('' === $name) {
            return json(['code' => 1, 'msg' => '请为星球命名']);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return json(['code' => 1, 'msg' => '星球名字最长' . self::NAME_MAX . '字']);
        }
        // 名字敏感词校验
        $check = ContentCheck::check($name);
        if ($check['hit']) {
            return json(['code' => 1, 'msg' => '名字含敏感词，请重新命名']);
        }

        // 一人一颗：已拥有则拒绝
        if (Db::name('planet')->where('uid', $uid)->where('status', 1)->find()) {
            return json(['code' => 1, 'msg' => '你已拥有星球，无法重复认领']);
        }

        // 按 seed 确定性计算初始资源（与客户端预览一致）
        $res = self::resourcesForSeed($seed);
        $now = time();

        $id = Db::name('planet')->insertGetId([
            'uid'         => $uid,
            'name'        => $name,
            'seed'        => $seed,
            'water'       => $res['water'],
            'metal'       => $res['metal'],
            'oxygen'      => 0,
            'rock'        => $res['rock'],
            'stage'       => 'magma',
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $planet = Db::name('planet')->find($id);
        $planet['resources'] = [
            'water'  => (int) $planet['water'],
            'metal'  => (int) $planet['metal'],
            'oxygen' => (int) $planet['oxygen'],
            'rock'   => (int) $planet['rock'],
        ];
        return json(['code' => 0, 'msg' => '认领成功', 'data' => $planet]);
    }

    /**
     * 按 seed 确定性计算初始资源（前后端一致，勿改算法）
     *
     * LCG：h = (h * 1103515245 + 12345) & 0x7fffffff
     *   water = 40..70
     *   metal = 20..min(45, 100-water)（金属上限受剩余空间约束，保证 rock>=0）
     *   rock  = 100 - water - metal（>=0，总资源恒为 100 平衡）
     *   oxygen = 0（初始无氧气）
     *
     * @return array{water:int, metal:int, rock:int, oxygen:int}
     */
    public static function resourcesForSeed(int $seed): array
    {
        $h = $seed & 0x7fffffff;
        $h = (int) (($h * 1103515245 + 12345) & 0x7fffffff);
        $water = 40 + ($h % 31); // 40-70
        $h = (int) (($h * 1103515245 + 12345) & 0x7fffffff);
        $maxMetal = min(45, 100 - $water);
        $metal = 20 + ($h % ($maxMetal - 20 + 1)); // 20..maxMetal
        $rock = 100 - $water - $metal; // >=0

        return [
            'water'  => $water,
            'metal'  => $metal,
            'rock'   => $rock,
            'oxygen' => 0,
        ];
    }
}
