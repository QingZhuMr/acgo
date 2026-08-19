<?php
declare(strict_types = 1);

namespace app\api\controller;

use app\BaseController;
use app\common\UserInfoCache;
use app\common\Pusher;
use think\facade\Db;

/**
 * 「开始寻找」星球配对接口
 *
 * 业务场景：在相同星球上寻找同样在发起寻找的人；可手动停止；
 * 未停止则账户保持寻找状态；配对成功后成为绑定人（好友+强制互关），状态结束。
 * - 不同星球需填写不同参数（后台 planet.find_fields 配置，默认 性别/年龄）
 * - 我的资料 user_find_profile 首填后自动复用，其他星球自动带出可修改
 * - 绑定期间不能解除好友关系、不能在任何星球发起寻找
 * - 星球可配置强制认证（颜值/学历/资产）后才能配对；认证功能后续开发，此处仅预留校验
 */
class Find extends BaseController
{
    protected const PROFILE_FIELDS = ['gender', 'age', 'income_range', 'personality', 'city', 'face_score', 'edu_level'];

    protected const FIELD_LABELS = [
        'gender'       => '性别',
        'age'          => '年龄',
        'income_range' => '月收入范围',
        'personality'  => '性格',
        'city'         => '城市',
        'face_score'   => '颜值打分',
        'edu_level'    => '学历',
    ];

    /**
     * 寻人配置 + 我的资料 + 我的状态（业务接口）
     * GET：planet_id
     */
    public function config()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $planetId = (int) $this->request->get('planet_id', 0);
        if ($planetId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $planet = Db::name('planet')->where('id', $planetId)->find();
        if (!$planet) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }

        // 费用与免费次数（供前端展示与发起时扣费判定复用）
        $costInfo = $this->resolveFindCost($planet, $uid);

        // 超时未配对的任务：自动停止并退回扣费
        $this->expireStaleFinds($uid, time());
        // 绑定对象长期未上线：自动解除绑定并补偿熊爪（懒检查）
        $this->autoUnbind($uid);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'planet_id'        => $planetId,
                'enable_find'      => (int) $planet['enable_find'] === 1,
                'find_fields'      => $this->parseFields((string) $planet['find_fields']),
                'field_labels'     => self::FIELD_LABELS,
                'force_face_auth'  => (int) $planet['force_face_auth'] === 1,
                'force_edu_auth'   => (int) $planet['force_edu_auth'] === 1,
                'force_asset_auth' => (int) $planet['force_asset_auth'] === 1,
                'profile'          => $this->myProfile($uid),
                'my_find'          => $this->myFind($uid),
                'my_bind'          => $this->myBind($uid),
                'auth'             => $this->myAuth($uid),
                // 付费 / 免费次数相关（星球寻人配对）
                'balance_paw'      => $this->myPaw($uid),
                'match_cost'       => $costInfo['cost'],
                'free_remaining'   => $costInfo['free_remaining'],
                'match_timeout'    => (int) $this->getConfigValue('find_match_timeout', 60),
                'cost_notice'      => (string) $this->getConfigValue('find_cost_notice', ''),
            ],
        ]);
    }

    /**
     * 发起寻找（业务接口）
     * POST：planet_id、my_gender/my_age 等我的资料字段、target_gender/target_age_min/target_age_max 等目标字段
     */
    public function start()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $planetId = (int) $this->request->post('planet_id', 0);
        if ($planetId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $planet = Db::name('planet')->where('id', $planetId)->find();
        if (!$planet) {
            return json(['code' => 1, 'msg' => '星球不存在']);
        }
        if ((int) $planet['status'] !== 1) {
            return json(['code' => 1, 'msg' => '该星球暂未开放']);
        }
        if ((int) $planet['enable_find'] !== 1) {
            return json(['code' => 1, 'msg' => '该星球未开启寻找配对']);
        }

        // 绑定校验：未解除绑定前不能在任何星球发起寻找
        if ($this->myBind($uid)) {
            return json(['code' => 1, 'msg' => '你有绑定关系，请先解除绑定']);
        }

        // 强制认证校验（预留：认证功能后续开发）
        $auth = $this->myAuth($uid);
        if ((int) $planet['force_face_auth'] === 1 && (int) $auth['face_status'] !== 1) {
            return json(['code' => 1, 'msg' => '该星球需完成颜值认证后才能配对']);
        }
        if ((int) $planet['force_edu_auth'] === 1 && (int) $auth['edu_status'] !== 1) {
            return json(['code' => 1, 'msg' => '该星球需完成学历认证后才能配对']);
        }
        if ((int) $planet['force_asset_auth'] === 1 && (int) $auth['asset_status'] !== 1) {
            return json(['code' => 1, 'msg' => '该星球需完成资产认证后才能配对']);
        }

        $findFields = $this->parseFields((string) $planet['find_fields']);

        // 我的资料
        $profile = $this->collectProfile($uid, $findFields);
        if (isset($profile['__error'])) {
            return json(['code' => 1, 'msg' => $profile['__error']]);
        }
        // 目标条件
        $target = $this->collectTarget($findFields);
        if (isset($target['__error'])) {
            return json(['code' => 1, 'msg' => $target['__error']]);
        }

        $now = time();
        $costInfo = $this->resolveFindCost($planet, $uid);
        $cost = $costInfo['cost'];

        try {
            $result = Db::transaction(function () use ($uid, $planetId, $planet, $profile, $target, $now, $cost) {
                // 停止我旧的寻找记录并退回扣费（同一账户同时只能在一个星球寻找）
                $this->stopMyFinds($uid, $now);

                // 永久免费次数判定（星球可配置每账号默认免费次数，后台也可按 账号+星球 额外赠送）
                $freeRow = Db::name('user_find_free')
                    ->where('user_id', $uid)
                    ->where('planet_id', $planetId)
                    ->find();
                $freeDefault = (int) ($planet['free_match_count'] ?? 0);
                $freeRemaining = $freeRow
                    ? max(0, (int) $freeRow['free_total'] - (int) $freeRow['free_used'])
                    : $freeDefault;
                $isFree = $freeRemaining > 0;

                $chargeType = '';
                $chargeAmount = 0;
                if ($isFree) {
                    // 消耗一次永久免费次数
                    if ($freeRow) {
                        Db::name('user_find_free')
                            ->where('id', (int) $freeRow['id'])
                            ->inc('free_used', 1)
                            ->update(['update_time' => $now]);
                    } else {
                        Db::name('user_find_free')->insert([
                            'user_id'     => $uid,
                            'planet_id'   => $planetId,
                            'free_total'  => $freeDefault,
                            'free_used'   => 1,
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                    }
                    $chargeType = 'free';
                    $chargeAmount = 1;
                } elseif ($cost > 0) {
                    // 付费：单次费用 > 0 → 扣熊爪
                    $aff = Db::name('wallet')
                        ->where('uid', $uid)
                        ->where('balance_paw', '>=', $cost)
                        ->dec('balance_paw', $cost)
                        ->update(['update_time' => $now]);
                    if ($aff < 1) {
                        throw new \Exception('熊爪不足');
                    }
                    $chargeType = 'paw';
                    $chargeAmount = $cost;
                }

                // 保存/更新我的资料（首填后自动复用）
                $old = Db::name('user_find_profile')->where('user_id', $uid)->find();
                $profile['update_time'] = $now;
                if ($old) {
                    Db::name('user_find_profile')->where('id', $old['id'])->update($profile);
                } else {
                    $profile['user_id'] = $uid;
                    $profile['create_time'] = $now;
                    Db::name('user_find_profile')->insert($profile);
                }

                // 插入新寻找记录（记录本次扣费，配对失败时退回）
                $findId = Db::name('user_find')->insertGetId([
                    'user_id'       => $uid,
                    'planet_id'     => $planetId,
                    'target'        => json_encode($target, JSON_UNESCAPED_UNICODE),
                    'status'        => 0,
                    'matched_uid'   => 0,
                    'charge_type'   => $chargeType,
                    'charge_amount' => $chargeAmount,
                    'create_time'   => $now,
                ]);
                return ['find_id' => $findId, 'is_free' => $isFree, 'cost' => $cost];
            });
        } catch (\Throwable $e) {
            $msg = $e->getMessage() === '熊爪不足' ? '熊爪不足，无法发起寻找' : '发起失败，请稍后重试';
            return json(['code' => 1, 'msg' => $msg]);
        }

        $findId = $result['find_id'];
        $isFree = $result['is_free'];
        $cost   = $result['cost'];

        // 尝试立即匹配
        $matched = $this->tryMatch($findId);
        if ($matched) {
            return json([
                'code' => 0,
                'msg'  => '配对成功',
                'data' => [
                    'status'       => 1,
                    'matched_uid'  => $matched['uid'],
                    'matched_user' => $matched['user'],
                    'bind'         => $matched['bind'],
                ],
            ]);
        }

        return json([
            'code' => 0,
            'msg'  => $isFree ? '已开始寻找（本次免费）' : ('已开始寻找（消耗 ' . $cost . ' 熊爪）'),
            'data' => ['status' => 0, 'find_id' => $findId, 'is_free' => $isFree, 'cost' => $cost],
        ]);
    }

    /**
     * 手动停止寻找（业务接口）
     * POST：无参数（停止当前寻找中的任务）
     */
    public function stop()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $now = time();
        $info = $this->stopMyFinds($uid, $now);

        if ($info['count'] > 0) {
            if ($info['refund_type'] === 'paw') {
                return json(['code' => 0, 'msg' => '已停止寻找，已退回 ' . $info['refund_amount'] . ' 熊爪', 'data' => $info]);
            }
            if ($info['refund_type'] === 'free') {
                return json(['code' => 0, 'msg' => '已停止寻找，本次免费次数已退回', 'data' => $info]);
            }
            return json(['code' => 0, 'msg' => '已停止寻找', 'data' => $info]);
        }
        return json(['code' => 0, 'msg' => '当前没有寻找中的任务', 'data' => $info]);
    }

    /**
     * 我的寻找/绑定状态（业务接口）
     * GET：无参数
     */
    public function status()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        // 超时未配对：自动停止并退回扣费
        $this->expireStaleFinds($uid, time());
        // 绑定对象长期未上线：自动解除绑定并补偿熊爪（懒检查）
        $this->autoUnbind($uid);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'my_find' => $this->myFind($uid),
                'my_bind' => $this->myBind($uid),
            ],
        ]);
    }

    /**
     * 解除绑定（业务接口）
     * POST：target_uid（绑定的对象）
     *
     * 解除后：双方可正常解除好友关系，也可在任何星球重新发起寻找。
     */
    public function unbind()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $targetUid = (int) $this->request->post('target_uid', 0);
        if ($targetUid <= 0 || $targetUid === $uid) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $bind = $this->myBind($uid);
        if (!$bind || (int) $bind['uid'] !== $targetUid) {
            return json(['code' => 1, 'msg' => '你们之间没有绑定关系']);
        }

        $now = time();
        $a = min($uid, $targetUid);
        $b = max($uid, $targetUid);
        Db::name('user_bind')
            ->where('user_a', $a)
            ->where('user_b', $b)
            ->where('status', 1)
            ->update(['status' => 0, 'unbind_time' => $now]);

        // 通知对方解除绑定（type=7 解除绑定）
        $me = UserInfoCache::get($uid);
        $nid = Db::name('notification')->insertGetId([
            'user_id'         => $targetUid,
            'from_uid'        => $uid,
            'post_id'         => 0,
            'post_content'    => '',
            'comment_id'      => 0,
            'comment_content' => '',
            'type'            => 7,
            'is_read'         => 0,
            'create_time'     => $now,
        ]);
        Pusher::send(
            ['user_id' => $targetUid],
            [
                'type'         => 'match_notice',
                'notification' => [
                    'id'            => (int) $nid,
                    'from_uid'      => $uid,
                    'from_nickname' => (string) ($me['nickname'] ?? ''),
                    'from_avatar'   => (string) ($me['avatar'] ?? ''),
                    'type'          => 7,
                    'create_time'   => $now,
                ],
            ]
        );

        return json(['code' => 0, 'msg' => '已解除绑定', 'data' => []]);
    }

    // ==================== 内部方法 ====================

    protected function parseFields(string $json): array
    {
        $fields = json_decode($json, true);
        if (!is_array($fields) || empty($fields)) {
            return ['gender', 'age'];
        }
        $fields = array_values(array_intersect($fields, self::PROFILE_FIELDS));
        // 默认必须包含 性别/年龄
        if (!in_array('gender', $fields, true)) {
            array_unshift($fields, 'gender');
        }
        if (!in_array('age', $fields, true)) {
            $fields[] = 'age';
        }
        return $fields;
    }

    protected function myAuth(int $uid): array
    {
        $auth = Db::name('user_auth')->where('user_id', $uid)->find();
        return [
            'face_status'  => (int) ($auth['face_status'] ?? 0),
            'edu_status'   => (int) ($auth['edu_status'] ?? 0),
            'asset_status' => (int) ($auth['asset_status'] ?? 0),
        ];
    }

    protected function myProfile(int $uid): ?array
    {
        $p = Db::name('user_find_profile')->where('user_id', $uid)->find();
        if (!$p) {
            return null;
        }
        return [
            'gender'       => (int) $p['gender'],
            'age'          => (int) $p['age'],
            'income_range' => (string) $p['income_range'],
            'personality'  => (string) $p['personality'],
            'city'         => (string) $p['city'],
            'face_score'   => (int) $p['face_score'],
            'edu_level'    => (string) $p['edu_level'],
            'update_time'  => (int) $p['update_time'],
        ];
    }

    protected function myFind(int $uid): ?array
    {
        $f = Db::name('user_find')->where('user_id', $uid)->order('id', 'desc')->find();
        if (!$f) {
            return null;
        }
        return [
            'id'          => (int) $f['id'],
            'planet_id'   => (int) $f['planet_id'],
            'target'      => json_decode((string) $f['target'], true) ?: [],
            'status'      => (int) $f['status'],
            'matched_uid' => (int) $f['matched_uid'],
            'create_time' => (int) $f['create_time'],
            'match_time'  => (int) $f['match_time'],
        ];
    }

    /**
     * 解析某星球发起一次匹配的费用与永久免费次数
     * - 单次费用 match_cost：星球配置 > 0 用星球值，否则回落全局 config 默认
     * - 免费次数：后台按「账号+星球」赠送的【永久】免费次数（user_find_free），非每日重置
     */
    protected function resolveFindCost(array $planet, int $uid): array
    {
        $planetCost = (int) ($planet['match_cost'] ?? 0);
        $cost = $planetCost > 0
            ? $planetCost
            : (int) $this->getConfigValue('find_default_match_cost', 0);

        $freeRow = Db::name('user_find_free')
            ->where('user_id', $uid)
            ->where('planet_id', (int) $planet['id'])
            ->find();
        $freeRemaining = $freeRow
            ? max(0, (int) $freeRow['free_total'] - (int) $freeRow['free_used'])
            : (int) ($planet['free_match_count'] ?? 0);

        return [
            'cost'           => $cost,
            'free_remaining' => $freeRemaining,
        ];
    }

    /** 读取运营配置项（string），不存在返回默认值 */
    protected function getConfigValue(string $name, $default = '')
    {
        $val = Db::name('config')->where('name', $name)->value('value');
        return $val === null ? $default : $val;
    }

    /**
     * 停止某用户所有「寻找中」的任务，未成功配对的退回本次扣费（手动取消/重复发起）
     *
     * @return array{count:int, refund_type:string, refund_amount:int}
     */
    protected function stopMyFinds(int $uid, int $now): array
    {
        $finds = Db::name('user_find')
            ->where('user_id', $uid)
            ->where('status', 0)
            ->select()
            ->toArray();

        $count = 0;
        $refundType = '';
        $refundAmount = 0;
        foreach ($finds as $f) {
            $count++;
            if ((int) $f['refunded'] !== 1) {
                $this->refundFind($uid, $f);
                $refundType = (string) $f['charge_type'];
                $refundAmount = (int) $f['charge_amount'];
            }
            Db::name('user_find')->where('id', (int) $f['id'])->update([
                'status'    => 2,
                'stop_time' => $now,
                'refunded'  => 1,
            ]);
        }
        return ['count' => $count, 'refund_type' => $refundType, 'refund_amount' => $refundAmount];
    }

    /**
     * 超时未配对的任务：自动停止并退回扣费（懒过期，任意接口触发时顺带清理）
     */
    protected function expireStaleFinds(int $uid, int $now): void
    {
        $timeout = (int) $this->getConfigValue('find_match_timeout', 60);
        $deadline = $now - max(10, $timeout);
        $stale = Db::name('user_find')
            ->where('user_id', $uid)
            ->where('status', 0)
            ->where('create_time', '<', $deadline)
            ->select()
            ->toArray();
        foreach ($stale as $f) {
            if ((int) $f['refunded'] !== 1) {
                $this->refundFind($uid, $f);
            }
            Db::name('user_find')->where('id', (int) $f['id'])->update([
                'status'    => 2,
                'stop_time' => $now,
                'refunded'  => 1,
            ]);
        }
    }

    /**
     * 绑定对象长期未上线：自动解除绑定并补偿熊爪（懒检查，任意接口触发时顺带处理当前用户）
     *
     * 规则：绑定对象超过 unbind_auto_days 天未上线 → 自动解除绑定，
     *      并给"被离开方"（仍在线访问接口的一方）补偿：
     *      实际星球消费金额（付费按 user_find.charge_amount；
     *      免费次数匹配按配对星球单次积分金额）+ 后台 unbind_auto_compensation 额外补偿。
     */
    protected function autoUnbind(int $uid): void
    {
        $days = (int) $this->getConfigValue('unbind_auto_days', 3);
        $compensation = (int) $this->getConfigValue('unbind_auto_compensation', 20);
        if ($days <= 0 || $compensation <= 0) {
            return; // 未配置自动解除
        }

        // 当前用户的有效绑定
        $bind = Db::name('user_bind')
            ->where('status', 1)
            ->where(function ($q) use ($uid) {
                $q->where('user_a', $uid)->whereOr('user_b', $uid);
            })
            ->find();
        if (!$bind) {
            return;
        }

        $partnerUid = ((int) $bind['user_a'] === $uid) ? (int) $bind['user_b'] : (int) $bind['user_a'];

        // 对方最后上线时间（user_device 最大 last_online_time，兜底 user.last_login_time）
        $lastOnline = (int) Db::name('user_device')->where('user_id', $partnerUid)->max('last_online_time');
        if ($lastOnline <= 0) {
            $lastOnline = (int) Db::name('user')->where('id', $partnerUid)->value('last_login_time');
        }
        if ($lastOnline <= 0) {
            return;
        }

        $deadline = time() - $days * 86400;
        if ($lastOnline >= $deadline) {
            return; // 对方未超期，不解绑
        }

        // 对方超期未上线 → 自动解绑 + 补偿当前用户（被离开方）
        $now = time();
        Db::name('user_bind')
            ->where('id', (int) $bind['id'])
            ->where('status', 1)
            ->update(['status' => 0, 'unbind_time' => $now]);

        // 补偿熊爪 = 实际星球消费金额 + 后台额外补偿
        // （付费匹配按 user_find.charge_amount；免费次数匹配按配对星球单次积分金额）
        $actualCost = $this->bindActualCost($uid, (int) $bind['planet_id']);
        $totalComp = $actualCost + $compensation;

        $wallet = Db::name('wallet')->where('uid', $uid)->find();
        if ($wallet) {
            Db::name('wallet')->where('uid', $uid)
                ->inc('balance_paw', $totalComp)
                ->update(['update_time' => $now]);
            $paw = (int) $wallet['balance_paw'] + $totalComp;
            $cny = (float) $wallet['balance_cny'];
        } else {
            Db::name('wallet')->insert([
                'uid'          => $uid,
                'balance_paw'  => $totalComp,
                'balance_cny'  => 0,
                'create_time'  => $now,
                'update_time'  => $now,
            ]);
            $paw = $totalComp;
            $cny = 0.0;
        }
        Pusher::pushWallet($uid, ['paw' => $paw, 'cny' => $cny]);

        // 通知 type=8（自动解除绑定）+ WS 推送
        $partner = UserInfoCache::get($partnerUid);
        $commentContent = '补偿 ' . $totalComp . ' 熊爪';
        $nid = Db::name('notification')->insertGetId([
            'user_id'         => $uid,
            'from_uid'        => $partnerUid,
            'post_id'         => 0,
            'post_content'    => (string) ($bind['planet_id'] ?? 0),
            'comment_id'      => 0,
            'comment_content' => $commentContent,
            'type'            => 8,
            'is_read'         => 0,
            'create_time'     => $now,
        ]);
        Pusher::send(
            ['user_id' => $uid],
            [
                'type'         => 'match_notice',
                'notification' => [
                    'id'              => (int) $nid,
                    'from_uid'        => $partnerUid,
                    'from_nickname'   => (string) ($partner['nickname'] ?? ''),
                    'from_avatar'     => (string) ($partner['avatar'] ?? ''),
                    'type'            => 8,
                    'post_content'    => (string) ($bind['planet_id'] ?? 0),
                    'comment_content' => $commentContent,
                    'create_time'     => $now,
                ],
            ]
        );
    }

    /**
     * 该用户在某星球配对成功那次的「实际消费金额」（熊爪）：
     * - 付费匹配（charge_type=paw）：取 user_find.charge_amount（实际扣的熊爪数）
     * - 免费次数匹配（charge_type=free）：按配对星球单次积分金额计（星球 match_cost，未配置则全局 find_default_match_cost）
     * - 无匹配记录或类型未知：返回 0
     */
    protected function bindActualCost(int $uid, int $planetId): int
    {
        $find = Db::name('user_find')
            ->where('user_id', $uid)
            ->where('planet_id', $planetId)
            ->where('status', 1)
            ->order('id', 'desc')
            ->find();
        if (!$find) {
            return 0;
        }
        $type = (string) ($find['charge_type'] ?? '');
        if ($type === 'paw') {
            return (int) ($find['charge_amount'] ?? 0);
        }
        if ($type === 'free') {
            $planet = Db::name('planet')->where('id', $planetId)->find();
            if ($planet) {
                return (int) $this->resolveFindCost($planet, $uid)['cost'];
            }
            return (int) $this->getConfigValue('find_default_match_cost', 0);
        }
        return 0;
    }

    /**
     * 退回一次未成功配对的扣费
     * - free：回补 user_find_free.free_used（减 1，最低 0）
     * - paw：回补钱包 balance_paw
     */
    protected function refundFind(int $uid, array $find): void
    {
        $type = (string) ($find['charge_type'] ?? '');
        $amount = (int) ($find['charge_amount'] ?? 0);
        if ($type === '' || $amount <= 0) {
            return;
        }
        if ($type === 'paw') {
            $wallet = Db::name('wallet')->where('uid', $uid)->find();
            if ($wallet) {
                Db::name('wallet')->where('uid', $uid)
                    ->inc('balance_paw', $amount)
                    ->update(['update_time' => time()]);
            } else {
                Db::name('wallet')->insert([
                    'uid'          => $uid,
                    'balance_paw'  => $amount,
                    'create_time'  => time(),
                    'update_time'  => time(),
                ]);
            }
        } elseif ($type === 'free') {
            Db::name('user_find_free')
                ->where('user_id', $uid)
                ->where('planet_id', (int) $find['planet_id'])
                ->where('free_used', '>', 0)
                ->dec('free_used', 1)
                ->update(['update_time' => time()]);
        }
    }

    /** 当前用户的熊爪余额（整数积分），无钱包记录返回 0 */
    protected function myPaw(int $uid): int
    {
        $w = Db::name('wallet')->where('uid', $uid)->field('balance_paw')->find();
        return $w ? (int) $w['balance_paw'] : 0;
    }

    protected function myBind(int $uid): ?array
    {
        $bind = Db::name('user_bind')
            ->where('status', 1)
            ->where(function ($q) use ($uid) {
                $q->where('user_a', $uid)->whereOr('user_b', $uid);
            })
            ->find();
        if (!$bind) {
            return null;
        }
        $partnerUid = ((int) $bind['user_a'] === $uid) ? (int) $bind['user_b'] : (int) $bind['user_a'];
        $partner = UserInfoCache::get($partnerUid);
        $planet = Db::name('planet')->where('id', (int) $bind['planet_id'])->field('name')->find();
        return [
            'uid'         => $partnerUid,
            'nickname'    => (string) ($partner['nickname'] ?? ''),
            'avatar'      => (string) ($partner['avatar'] ?? ''),
            'planet_id'   => (int) $bind['planet_id'],
            'planet_name' => (string) ($planet['name'] ?? ''),
            'create_time' => (int) $bind['create_time'],
        ];
    }

    /**
     * 收集我的资料（POST 参数 my_xxx，优先已存资料兜底）
     */
    protected function collectProfile(int $uid, array $findFields): array
    {
        $old = Db::name('user_find_profile')->where('user_id', $uid)->find() ?: [];
        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $postVal = $this->request->post('my_' . $field);
            if ($postVal === null || $postVal === '') {
                $postVal = $old[$field] ?? '';
            }
            $val = trim((string) $postVal);
            // 必填校验
            if (in_array($field, $findFields, true) && $val === '') {
                return ['__error' => '请填写' . self::FIELD_LABELS[$field]];
            }
            $profile[$field] = $this->normalize($field, $val);
        }
        return $profile;
    }

    /**
     * 收集目标条件（POST 参数 target_xxx）
     */
    protected function collectTarget(array $findFields): array
    {
        $target = [];
        // 目标性别（0=不限）
        $targetGender = (int) $this->request->post('target_gender', 0);
        if (!in_array($targetGender, [0, 1, 2], true)) {
            return ['__error' => '目标性别参数错误'];
        }
        $target['gender'] = $targetGender;

        // 目标年龄范围
        $ageMin = (int) $this->request->post('target_age_min', 0);
        $ageMax = (int) $this->request->post('target_age_max', 0);
        if (in_array('age', $findFields, true) && ($ageMin < 18 || $ageMax < 18 || $ageMax < $ageMin)) {
            return ['__error' => '请填写有效的目标年龄范围'];
        }
        $target['age_min'] = $ageMin ?: 18;
        $target['age_max'] = $ageMax ?: 99;

        // 可选目标条件（仅当该字段参与时才收集）
        $optional = ['income_range', 'personality', 'city', 'face_score', 'edu_level'];
        $target['income_range'] = in_array('income_range', $findFields, true) ? trim((string) $this->request->post('target_income_range', '')) : '';
        $target['personality']  = in_array('personality', $findFields, true) ? trim((string) $this->request->post('target_personality', '')) : '';
        $target['city']         = in_array('city', $findFields, true) ? trim((string) $this->request->post('target_city', '')) : '';
        $target['face_score']   = in_array('face_score', $findFields, true) ? (int) $this->request->post('target_face_score', 0) : 0;
        $target['edu_level']    = in_array('edu_level', $findFields, true) ? trim((string) $this->request->post('target_edu_level', '')) : '';

        // 同城选项：若配置了 city 字段，可选择是否只寻找同城
        $target['same_city'] = in_array('city', $findFields, true) ? (int) $this->request->post('target_same_city', 0) : 0;

        // 必填校验（目标性别/年龄范围已在上面校验）
        if ($target['city'] !== '' && !$target['same_city']) {
            $target['same_city'] = 1; // 填写了目标城市视为只找该城
        }
        return $target;
    }

    protected function normalize(string $field, string $value)
    {
        switch ($field) {
            case 'gender':
                return in_array((int) $value, [1, 2], true) ? (int) $value : 0;
            case 'age':
            case 'face_score':
                $v = (int) $value;
                if ($field === 'age') {
                    return ($v >= 18 && $v <= 99) ? $v : 0;
                }
                return ($v >= 1 && $v <= 10) ? $v : 0;
            default:
                return mb_substr($value, 0, 50);
        }
    }

    /**
     * 尝试立即匹配（同星球、互配条件、无拉黑）
     *
     * @return array|null ['uid'=>..,'user'=>[昵称头像],'bind'=>绑定信息] | null
     */
    protected function tryMatch(int $findId): ?array
    {
        $my = Db::name('user_find')->where('id', $findId)->find();
        if (!$my || (int) $my['status'] !== 0) {
            return null;
        }
        $uid = (int) $my['user_id'];
        $planetId = (int) $my['planet_id'];
        $myTarget = json_decode((string) $my['target'], true) ?: [];
        $myProfile = $this->myProfile($uid);
        $planet = Db::name('planet')->where('id', $planetId)->find();
        if (!$myProfile) {
            return null;
        }

        // 候选：同星球、寻找中、非自己、未超时；按时间升序（先来的先配）
        $timeout = (int) $this->getConfigValue('find_match_timeout', 60);
        $deadline = time() - max(10, $timeout);
        $candidates = Db::name('user_find')
            ->where('planet_id', $planetId)
            ->where('status', 0)
            ->where('user_id', '<>', $uid)
            ->where('create_time', '>=', $deadline)
            ->order('id', 'asc')
            ->limit(50)
            ->select()
            ->toArray();

        foreach ($candidates as $cand) {
            $candUid = (int) $cand['user_id'];

            // 拉黑互斥：任一方向拉黑则跳过
            $blocked = Db::name('user_block')
                ->where(function ($q) use ($uid, $candUid) {
                    $q->where('blocker_id', $uid)->where('blocked_id', $candUid)
                      ->whereOr('blocker_id', $candUid)->where('blocked_id', $uid);
                })
                ->count();
            if ($blocked > 0) {
                continue;
            }

            // 对方已有绑定则跳过（理论上 status=0 即未绑定，防御性检查）
            if ($this->myBind($candUid)) {
                continue;
            }

            // 对方资料
            $candProfile = Db::name('user_find_profile')->where('user_id', $candUid)->find();
            if (!$candProfile) {
                continue;
            }
            $candTarget = json_decode((string) $cand['target'], true) ?: [];

            // 双向条件匹配
            if (!$this->matchBoth($myProfile, $myTarget, $candProfile, $candTarget)) {
                continue;
            }

            // 星球强制认证：双方都必须已认证（预留校验）
            if ($planet) {
                $candAuth = Db::name('user_auth')->where('user_id', $candUid)->find();
                if ((int) $planet['force_face_auth'] === 1 && (int) ($candAuth['face_status'] ?? 0) !== 1) {
                    continue;
                }
                if ((int) $planet['force_edu_auth'] === 1 && (int) ($candAuth['edu_status'] ?? 0) !== 1) {
                    continue;
                }
                if ((int) $planet['force_asset_auth'] === 1 && (int) ($candAuth['asset_status'] ?? 0) !== 1) {
                    continue;
                }
            }

            // 配对成功
            return $this->doMatch($my, $cand, $planetId);
        }
        return null;
    }

    /**
     * 双向匹配：我的目标命中对方资料 且 对方目标命中我的资料
     */
    protected function matchBoth(array $p1, array $t1, array $p2, array $t2): bool
    {
        // 性别：目标性别（0=不限）需与对方性别一致
        if ((int) ($t1['gender'] ?? 0) !== 0 && (int) ($t1['gender'] ?? 0) !== (int) ($p2['gender'] ?? 0)) {
            return false;
        }
        if ((int) ($t2['gender'] ?? 0) !== 0 && (int) ($t2['gender'] ?? 0) !== (int) ($p1['gender'] ?? 0)) {
            return false;
        }

        // 年龄：双方年龄需落在对方的目标区间
        $age1 = (int) ($p1['age'] ?? 0);
        $age2 = (int) ($p2['age'] ?? 0);
        if ($age2 < (int) ($t1['age_min'] ?? 18) || $age2 > (int) ($t1['age_max'] ?? 99)) {
            return false;
        }
        if ($age1 < (int) ($t2['age_min'] ?? 18) || $age1 > (int) ($t2['age_max'] ?? 99)) {
            return false;
        }

        // 收入范围：配置时要求一致
        if (($t1['income_range'] ?? '') !== '' && ($t1['income_range'] ?? '') !== (string) ($p2['income_range'] ?? '')) {
            return false;
        }
        if (($t2['income_range'] ?? '') !== '' && ($t2['income_range'] ?? '') !== (string) ($p1['income_range'] ?? '')) {
            return false;
        }

        // 性格：配置时要求一致
        if (($t1['personality'] ?? '') !== '' && ($t1['personality'] ?? '') !== (string) ($p2['personality'] ?? '')) {
            return false;
        }
        if (($t2['personality'] ?? '') !== '' && ($t2['personality'] ?? '') !== (string) ($p1['personality'] ?? '')) {
            return false;
        }

        // 学历：配置时要求一致
        if (($t1['edu_level'] ?? '') !== '' && ($t1['edu_level'] ?? '') !== (string) ($p2['edu_level'] ?? '')) {
            return false;
        }
        if (($t2['edu_level'] ?? '') !== '' && ($t2['edu_level'] ?? '') !== (string) ($p1['edu_level'] ?? '')) {
            return false;
        }

        // 颜值打分：配置时目标分值需在对方范围内
        $score1 = (int) ($p1['face_score'] ?? 0);
        $score2 = (int) ($p2['face_score'] ?? 0);
        if ((int) ($t1['face_score'] ?? 0) > 0 && $score2 < (int) ($t1['face_score'] ?? 0)) {
            return false;
        }
        if ((int) ($t2['face_score'] ?? 0) > 0 && $score1 < (int) ($t2['face_score'] ?? 0)) {
            return false;
        }

        // 同城：任一要求同城则双方城市需一致（目标城市优先）
        $city1 = (string) ($p1['city'] ?? '');
        $city2 = (string) ($p2['city'] ?? '');
        $want1 = (int) ($t1['same_city'] ?? 0) === 1 || (string) ($t1['city'] ?? '') !== '';
        $want2 = (int) ($t2['same_city'] ?? 0) === 1 || (string) ($t2['city'] ?? '') !== '';
        if (($want1 || $want2) && ($city1 === '' || $city2 === '' || $city1 !== $city2)) {
            return false;
        }

        return true;
    }

    /**
     * 执行配对：双方寻找记录置已完成、落绑定、强制互关、通知 + WS 推送
     */
    protected function doMatch(array $my, array $cand, int $planetId): array
    {
        $uidA = (int) $my['user_id'];
        $uidB = (int) $cand['user_id'];
        $now = time();

        // 锁定双方记录（防止并发重复配对：status 更新成功数）
        $ua = Db::name('user_find')->where('id', (int) $my['id'])->where('status', 0)
            ->update(['status' => 1, 'matched_uid' => $uidB, 'match_time' => $now]);
        $ub = Db::name('user_find')->where('id', (int) $cand['id'])->where('status', 0)
            ->update(['status' => 1, 'matched_uid' => $uidA, 'match_time' => $now]);
        if ($ua !== 1 || $ub !== 1) {
            return []; // 已被并发配对，本轮放弃（返回空数组走重试）
        }

        // 绑定关系（user_a 恒为较小 ID）
        $a = min($uidA, $uidB);
        $b = max($uidA, $uidB);
        Db::name('user_bind')->insert([
            'user_a'      => $a,
            'user_b'      => $b,
            'planet_id'   => $planetId,
            'status'      => 1,
            'create_time' => $now,
        ]);

        // 强制互相关注（user_follow 双向，已存在则跳过）
        $this->ensureFollow($uidA, $uidB);
        $this->ensureFollow($uidB, $uidA);

        // 通知 + WS 推送（type=6 配对成功）
        $userA = UserInfoCache::get($uidA);
        $userB = UserInfoCache::get($uidB);
        $planet = Db::name('planet')->where('id', $planetId)->field('name')->find();
        $planetName = (string) ($planet['name'] ?? '');

        $profileA = $this->myProfile($uidA);
        $profileB = $this->myProfile($uidB);
        $this->notifyMatch($uidB, $uidA, (string) ($userA['nickname'] ?? ''), (string) ($userA['avatar'] ?? ''), $profileA, $planetId, $planetName, $now);
        $this->notifyMatch($uidA, $uidB, (string) ($userB['nickname'] ?? ''), (string) ($userB['avatar'] ?? ''), $profileB, $planetId, $planetName, $now);

        return [
            'uid'  => $uidB,
            'user' => [
                'uid'     => $uidB,
                'nickname' => (string) ($userB['nickname'] ?? ''),
                'avatar'  => (string) ($userB['avatar'] ?? ''),
                'profile' => $profileB,
            ],
            'bind' => [
                'uid'         => $uidB,
                'nickname'    => (string) ($userB['nickname'] ?? ''),
                'avatar'      => (string) ($userB['avatar'] ?? ''),
                'planet_id'   => $planetId,
                'planet_name' => $planetName,
                'create_time' => $now,
            ],
        ];
    }

    protected function ensureFollow(int $fromUid, int $toUid): void
    {
        $exists = Db::name('user_follow')
            ->where('follower_id', $fromUid)
            ->where('following_id', $toUid)
            ->find();
        if (!$exists) {
            Db::name('user_follow')->insert([
                'follower_id'  => $fromUid,
                'following_id' => $toUid,
                'create_time'  => time(),
            ]);
        }
    }

    protected function notifyMatch(int $toUid, int $fromUid, string $fromName, string $fromAvatar, ?array $fromProfile, int $planetId, string $planetName, int $now): void
    {
        $nid = Db::name('notification')->insertGetId([
            'user_id'         => $toUid,
            'from_uid'        => $fromUid,
            'post_id'         => 0,
            'post_content'    => $planetName,
            'comment_id'      => 0,
            'comment_content' => '',
            'type'            => 6,
            'is_read'         => 0,
            'create_time'     => $now,
        ]);
        Pusher::send(
            ['user_id' => $toUid],
            [
                'type'         => 'match_notice',
                'notification' => [
                    'id'            => (int) $nid,
                    'from_uid'      => $fromUid,
                    'from_nickname' => $fromName,
                    'from_avatar'   => $fromAvatar,
                    'from_profile'  => $fromProfile ?? new \stdClass(),
                    'planet_id'     => $planetId,
                    'planet_name'   => $planetName,
                    'type'          => 6,
                    'create_time'   => $now,
                ],
            ]
        );
    }
}
