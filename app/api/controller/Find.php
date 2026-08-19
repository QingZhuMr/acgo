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
        $findId = Db::transaction(function () use ($uid, $planetId, $profile, $target, $now) {
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

            // 停止我旧的寻找记录（同一账户同时只能在一个星球寻找）
            Db::name('user_find')
                ->where('user_id', $uid)
                ->where('status', 0)
                ->update(['status' => 2, 'stop_time' => $now]);

            // 插入新寻找记录
            return Db::name('user_find')->insertGetId([
                'user_id'     => $uid,
                'planet_id'   => $planetId,
                'target'      => json_encode($target, JSON_UNESCAPED_UNICODE),
                'status'      => 0,
                'matched_uid' => 0,
                'create_time' => $now,
            ]);
        });

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

        return json(['code' => 0, 'msg' => '已开始寻找', 'data' => ['status' => 0, 'find_id' => $findId]]);
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
        $n = Db::name('user_find')
            ->where('user_id', $uid)
            ->where('status', 0)
            ->update(['status' => 2, 'stop_time' => $now]);

        return json(['code' => 0, 'msg' => $n > 0 ? '已停止寻找' : '当前没有寻找中的任务', 'data' => []]);
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
        Db::name('notification')->insert([
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
        return [
            'uid'         => $partnerUid,
            'nickname'    => (string) ($partner['nickname'] ?? ''),
            'avatar'      => (string) ($partner['avatar'] ?? ''),
            'planet_id'   => (int) $bind['planet_id'],
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

        // 候选：同星球、寻找中、非自己；按时间升序（先来的先配）
        $candidates = Db::name('user_find')
            ->where('planet_id', $planetId)
            ->where('status', 0)
            ->where('user_id', '<>', $uid)
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

        $this->notifyMatch($uidB, $uidA, (string) ($userA['nickname'] ?? ''), (string) ($userA['avatar'] ?? ''), $planetName, $now);
        $this->notifyMatch($uidA, $uidB, (string) ($userB['nickname'] ?? ''), (string) ($userB['avatar'] ?? ''), $planetName, $now);

        return [
            'uid'  => $uidB,
            'user' => ['nickname' => (string) ($userB['nickname'] ?? ''), 'avatar' => (string) ($userB['avatar'] ?? '')],
            'bind' => [
                'uid'         => $uidB,
                'nickname'    => (string) ($userB['nickname'] ?? ''),
                'avatar'      => (string) ($userB['avatar'] ?? ''),
                'planet_id'   => $planetId,
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

    protected function notifyMatch(int $toUid, int $fromUid, string $fromName, string $fromAvatar, string $planetName, int $now): void
    {
        Db::name('notification')->insert([
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
                    'from_uid'      => $fromUid,
                    'from_nickname' => $fromName,
                    'from_avatar'   => $fromAvatar,
                    'type'          => 6,
                    'create_time'   => $now,
                ],
            ]
        );
    }
}
