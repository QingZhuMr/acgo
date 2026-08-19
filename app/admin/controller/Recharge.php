<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\BaseController;
use app\common\AdminLog;
use app\common\Pusher;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;
use app\common\Password;

class Recharge extends BaseController
{
    /**
     * 币种名称映射
     */
    protected const CURRENCY_NAME = [
        'cny' => '人民币',
        'paw' => '熊爪',
    ];

    /**
     * 充值状态映射
     * 1=发起中 2=核对中 3=已完成 4=已取消
     */
    protected const STATUS_NAME = [
        1 => '发起中',
        2 => '核对中',
        3 => '已完成',
        4 => '已取消',
    ];

    /**
     * 充值状态徽章样式
     */
    protected const STATUS_BADGE = [
        1 => 'badge-cyan',
        2 => 'badge-warm',
        3 => 'badge-green',
        4 => 'badge-gray',
    ];

    /**
     * 充值记录页
     */
    public function index()
    {
        return View::fetch();
    }

    /**
     * 充值记录列表（分页 + 搜索）
     */
    public function getList()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));
        $kw    = trim((string) $this->request->param('keyword', ''));
        $currency = trim((string) $this->request->param('currency', ''));
        $status = $this->request->param('status', '');
        // 排序：field/order（点击表头排序）
        $sortField = trim((string) $this->request->param('field', 'id'));
        $sortOrder = strtolower((string) $this->request->param('order', 'desc'));
        $allowSort = ['id', 'phone', 'nickname', 'currency', 'amount', 'status', 'source', 'operator_type', 'create_time'];
        if (!in_array($sortField, $allowSort, true)) $sortField = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'desc';

        // 用闭包统一构建查询条件，keyword 的 OR 用括号分组与其它 AND 条件隔离
        $buildWhere = function ($q) use ($kw, $currency, $status) {
            if ('' !== $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->whereLike('phone', "%{$kw}%")
                        ->whereOr('nickname', 'like', "%{$kw}%")
                        ->whereOr('operator_name', 'like', "%{$kw}%");
                });
            }
            if ('' !== $currency) {
                $q->where('currency', $currency);
            }
            if ('' !== $status) {
                $q->where('status', (int) $status);
            }
        };

        $query = Db::name('recharge')->where($buildWhere);
        $total = $query->count();

        $list = Db::name('recharge')
            ->where($buildWhere)
            ->order($sortField, $sortOrder)
            ->limit(($page - 1) * $limit, $limit)
            ->select()
            ->toArray();

        $curName = self::CURRENCY_NAME;
        $curSymbol = ['cny' => '¥', 'paw' => '熊爪'];
        $statusName  = self::STATUS_NAME;
        $statusBadge = self::STATUS_BADGE;
        foreach ($list as &$item) {
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $item['currency_name']    = isset($curName[$item['currency']]) ? $curName[$item['currency']] : $item['currency'];
            $item['currency_symbol']  = isset($curSymbol[$item['currency']]) ? $curSymbol[$item['currency']] : '';
            $item['amount_text']      = 'paw' === $item['currency'] ? number_format((float) $item['amount'], 0, '.', '') : number_format((float) $item['amount'], 2, '.', '');
            $item['source_text']      = 'manual' === $item['source'] ? '人工' : '接口';
            $item['operator_type_text'] = 'admin' === $item['operator_type'] ? '管理员' : '系统';
            $item['status_text']      = isset($statusName[(int) $item['status']]) ? $statusName[(int) $item['status']] : '未知';
            $item['status_badge']     = isset($statusBadge[(int) $item['status']]) ? $statusBadge[(int) $item['status']] : 'badge-gray';
        }
        unset($item);

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $list]);
    }

    /**
     * 用户模糊搜索（自动搜索：id/昵称/用户名）
     */
    public function searchUser()
    {
        $kw = trim((string) $this->request->param('keyword', ''));
        if ('' === $kw) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => []]);
        }

        // 用户模糊搜索：手机号 / 昵称 LIKE，或 id 精确匹配
        $list = Db::name('user')
            ->where(function ($query) use ($kw) {
                $query->whereLike('phone', "%{$kw}%")
                    ->whereOr('nickname', 'like', "%{$kw}%")
                    ->whereOr('id', (int) $kw);
            })
            ->where('status', '<>', 0) // 排除已注销
            ->order('id asc')
            ->limit(20)
            ->field('id,phone,nickname,avatar')
            ->select()
            ->toArray();

        // 补充钱包余额
        foreach ($list as &$u) {
            $wallet = Db::name('wallet')->where('uid', $u['id'])->find();
            $u['avatar'] = $u['avatar'] ?: '/static/admin/img/default-avatar.png';
            $u['wallet'] = $wallet ? [
                'cny' => $wallet['balance_cny'],
                'paw' => $wallet['balance_paw'],
            ] : null;
        }
        unset($u);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $list]);
    }

    /**
     * 人工充值（需管理员密码二次验证）
     */
    public function manualRecharge()
    {
        $userId   = (int) $this->request->post('user_id', 0);
        // 人工充值仅支持熊爪（不支持人民币充值）
        $currency = 'paw';
        $amount   = (string) $this->request->post('amount', '');
        $remark   = trim((string) $this->request->post('remark', ''));
        $adminPwd = (string) $this->request->post('admin_password', '');

        // 校验参数
        if (!$userId) return json(['code' => 1, 'msg' => '请选择用户']);
        if ('' === $amount || !is_numeric($amount) || (float) $amount <= 0) {
            return json(['code' => 1, 'msg' => '请输入正确的充值数量']);
        }
        if ((float) $amount != floor((float) $amount)) {
            return json(['code' => 1, 'msg' => '熊爪数量必须为整数']);
        }
        if ('' === $adminPwd) return json(['code' => 1, 'msg' => '请输入管理员密码确认操作']);

        // 校验当前管理员
        $adminId = (int) Session::get('admin_id', 0);
        $admin   = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin) return json(['code' => 1, 'msg' => '管理员不存在，请重新登录']);
        if (0 === (int) $admin['status']) return json(['code' => 1, 'msg' => '管理员已被禁用']);
        if (!Password::verify($adminPwd, $admin['password'])) {
            return json(['code' => 1, 'msg' => '管理员密码错误']);
        }

        // 校验用户
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) return json(['code' => 1, 'msg' => '用户不存在']);
        if (0 === (int) $user['status']) return json(['code' => 1, 'msg' => '该用户已注销，无法充值']);
        if (2 === (int) $user['status']) return json(['code' => 1, 'msg' => '该用户已被封禁']);

        // 熊爪为整数积分
        $amount = round((float) $amount, 0);
        $now    = time();

        // 更新用户钱包对应币种余额
        $wallet = Db::name('wallet')->where('uid', $userId)->find();
        if (!$wallet) {
            // 无钱包则创建
            Db::name('wallet')->insert([
                'uid'         => $userId,
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('wallet')->where('uid', $userId)->inc('balance_' . $currency, $amount)->update();

        // 充值到账后推送最新余额（WS 实时刷新客户端熊爪/人民币余额）
        $walletNow = Db::name('wallet')->where('uid', $userId)->find();
        if ($walletNow) {
            Pusher::pushWallet($userId, [
                'paw' => (int) $walletNow['balance_paw'],
                'cny' => (float) $walletNow['balance_cny'],
            ]);
        }

        // 写入充值记录
        $adminName = $admin['nickname'] ?: $admin['username'];
        Db::name('recharge')->insert([
            'user_id'       => $userId,
            'phone'         => (string) $user['phone'],
            'nickname'      => $user['nickname'],
            'currency'      => $currency,
            'amount'        => $amount,
            'source'        => 'manual',          // 人工充值
            'operator_type' => 'admin',           // 操作人为管理员
            'operator_id'   => $adminId,
            'operator_name' => $adminName,
            'remark'        => $remark ?: '人工充值', // 人工充值默认备注
            'status'        => 3, // 人工充值直接完成
            'create_time'   => $now,
        ]);

        // 同步写入管理员操作日志（行为 recharge 充值）
        $curName = self::CURRENCY_NAME[$currency] ?? $currency;
        $logRemark = '给 ' . ($user['nickname'] ?: $user['phone']) . ' 充值 ' . $curName . ' +' . $amount . ($remark ? '（' . $remark . '）' : '');
        AdminLog::write($adminId, $admin['username'], 'recharge', $this->request->ip(), $logRemark);

        // 同步写入用户操作日志（充值）
        Db::name('user_log')->insert([
            'user_id'     => $userId,
            'phone'       => (string) $user['phone'],
            'action'      => 'recharge',
            'ip'          => $this->request->ip(),
            'operator'    => $adminName,
            'remark'      => $logRemark,
            'create_time' => $now,
        ]);

        return json(['code' => 0, 'msg' => '充值成功：' . $curName . ' +' . $amount]);
    }
}
