<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\UserInfoCache;
use think\facade\Db;

/**
 * 帖子回复通知接口
 *
 * 业务场景：他人评论了我的帖子 / 回复了我的评论时，生成一条通知。
 * 通知在 Post::comment() 写入评论后由服务端同步落库并推送实时消息，
 * 本控制器负责客户端拉取通知列表 / 未读数 / 标记已读。
 *
 * 全部为业务接口（签名 + 设备密钥），按当前登录用户隔离数据。
 */
class Notification extends BaseController
{
    /** 单页最多条数 */
    protected const PAGE_SIZE_MAX = 50;

    /** 通知内容展示时正文最长字数 */
    protected const CONTENT_SHOW_MAX = 60;

    /**
     * 通知列表（业务接口）
     * GET：page / page_size
     *
     * 返回：list[]（含未读数 unread_count）+ page
     *
     * 折叠聚合规则（客户端需求：多条未读同一目标合并展示）：
     * - **未读**通知按 `type + post_id + comment_id` 聚合：
     *   - type=1/2/4/5（评论/回复/点赞/收藏）→ 键 `{type}_{post_id}_{comment_id}`
     *     同一帖子/评论的多条未读合并为一条
     *   - type=3（关注）→ 全部合并为一条「xxx、xxx等N人关注了你」
     * - **已读**通知不聚合（保持单条展示，避免历史记录合并混乱）
     * - 聚合条目字段：id_list[]、from_uids[]、from_nicknames[]（最多前2 + 等N人）、
     *   count、post_id、comment_id、post_content、comment_content、type、
     *   is_read（含未读即未读）、latest_time
     */
    public function list()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('page_size', 20);
        $pageSize = min(max(1, $pageSize), self::PAGE_SIZE_MAX);

        // 未读数（总览，无论分页）
        $unread = (int) Db::name('notification')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->count();

        $total = (int) Db::name('notification')
            ->where('user_id', $uid)
            ->count();

        // 通知表已不存触发者昵称/头像冗余快照，按 from_uid 实时联表 user（走 Redis 缓存）
        $rows = Db::name('notification')
            ->where('user_id', $uid)
            ->field('id,from_uid,post_id,post_content,comment_id,comment_content,type,is_read,create_time')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 未读聚合：按 目标键 分组；已读单条直出
        $unreadGroups = []; // key => ['rows'=>[], 'ids'=>[], 'uids'=>[], 'post_id'=>..., ...]
        $readList = [];
        foreach ($rows as $r) {
            if ((int) $r['is_read'] === 1) {
                $readList[] = $r;
                continue;
            }
            $key = $this->groupKey($r);
            if (!isset($unreadGroups[$key])) {
                $unreadGroups[$key] = [
                    'rows' => [],
                    'ids'  => [],
                    'uids' => [],
                    'type' => (int) $r['type'],
                    'post_id' => (int) $r['post_id'],
                    'post_content' => (string) $r['post_content'],
                    'comment_id' => (int) $r['comment_id'],
                    'comment_content' => (string) $r['comment_content'],
                    'latest_time' => (int) $r['create_time'],
                ];
            }
            $unreadGroups[$key]['rows'][] = $r;
            $unreadGroups[$key]['ids'][] = (int) $r['id'];
            $unreadGroups[$key]['uids'][] = (int) $r['from_uid'];
            if ((int) $r['create_time'] > $unreadGroups[$key]['latest_time']) {
                $unreadGroups[$key]['latest_time'] = (int) $r['create_time'];
            }
        }

        // 收集所有需要的 from_uid（聚合 + 已读单条）
        $needUids = array_column($readList, 'from_uid');
        foreach ($unreadGroups as $g) {
            $needUids = array_merge($needUids, $g['uids']);
        }
        $userMap = UserInfoCache::batch($needUids);

        $list = [];

        // 聚合条目：最新时间倒序插入（与单条混合排序：未读聚合按 latest_time）
        $unreadItems = [];
        foreach ($unreadGroups as $g) {
            // 触发者昵称（按触发时间顺序：先来的在前，最多取2个 + 等N人）
            $names = [];
            foreach ($g['uids'] as $fuid) {
                $info = $userMap[$fuid] ?? ['nickname' => ''];
                $name = (string) ($info['nickname'] ?? '');
                if ($name === '') $name = '用户' . $fuid;
                if (!in_array($name, $names, true)) $names[] = $name;
            }
            $count = count($g['rows']);
            $namesShow = array_slice($names, 0, 2);
            $suffix = $count > 2 ? '等' . $count . '人' : '';
            $unreadItems[] = [
                'id'              => $g['ids'][0],
                'id_list'         => $g['ids'],
                'from_uid'        => $g['uids'][0],
                'from_uids'       => $g['uids'],
                'from_nickname'   => implode('、', $namesShow) . $suffix,
                'from_avatar'     => (string) (($userMap[$g['uids'][0]]['avatar'] ?? '') ?: UserInfoCache::DEFAULT_AVATAR),
                'post_id'         => $g['post_id'],
                'post_content'    => $this->shorten($g['post_content']),
                'comment_id'      => $g['comment_id'],
                'comment_content' => $this->shorten($g['comment_content']),
                'type'            => $g['type'],
                'is_read'         => 0,
                'count'           => $count,
                'create_time'     => $g['latest_time'],
                'create_time_text' => $this->timeText($g['latest_time']),
            ];
        }
        // 已读单条
        foreach ($readList as $r) {
            $fromUid  = (int) $r['from_uid'];
            $fromInfo = $userMap[$fromUid] ?? ['nickname' => '', 'avatar' => ''];
            $list[] = [
                'id'              => (int) $r['id'],
                'id_list'         => [(int) $r['id']],
                'from_uid'        => $fromUid,
                'from_uids'       => [$fromUid],
                'from_nickname'   => (string) ($fromInfo['nickname'] ?? ''),
                'from_avatar'     => (string) (($fromInfo['avatar'] ?? '') ?: UserInfoCache::DEFAULT_AVATAR),
                'post_id'         => (int) $r['post_id'],
                'post_content'    => $this->shorten((string) $r['post_content']),
                'comment_id'      => (int) $r['comment_id'],
                'comment_content' => $this->shorten((string) $r['comment_content']),
                'type'            => (int) $r['type'],
                'is_read'         => 1,
                'count'           => 1,
                'create_time'     => (int) $r['create_time'],
                'create_time_text' => $this->timeText((int) $r['create_time']),
            ];
        }

        // 合并：未读聚合（按最新时间）与已读单条按 create_time 倒序
        $list = array_merge($unreadItems, $list);
        usort($list, function ($a, $b) {
            return (int) $b['create_time'] <=> (int) $a['create_time'];
        });

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'list'         => $list,
                'total'        => $total,
                'unread_count' => $unread,
                'page'         => $page,
            ],
        ]);
    }

    /**
     * 通知聚合键：未读通知按目标+类型折叠
     * - type=3（关注）→ 全部合并（键 "3"）
     * - 其余（评论/回复/点赞/收藏）→ "type_postid_commentid"
     */
    protected function groupKey(array $r): string
    {
        $type = (int) $r['type'];
        if ($type === 3) {
            return '3';
        }
        return $type . '_' . (int) $r['post_id'] . '_' . (int) $r['comment_id'];
    }

    /**
     * 批量删除通知（业务接口）
     * POST：ids（JSON 数组，最多 200 条）
     */
    public function delete()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $ids = $this->request->post('ids');
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        if (!is_array($ids) || empty($ids)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $ids = array_slice(array_map('intval', $ids), 0, 200);

        Db::name('notification')
            ->where('user_id', $uid)
            ->whereIn('id', $ids)
            ->delete();

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 未读数（业务接口）
     * GET：无参数
     *
     * 客户端定时轮询/WS 重连后拉取，用于更新铃铛角标。
     */
    public function unreadCount()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $unread = (int) Db::name('notification')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->count();

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['unread_count' => $unread]]);
    }

    /**
     * 单条通知标记已读（业务接口）
     * POST：id
     */
    public function read()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        // 仅能操作自己的通知
        Db::name('notification')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->update(['is_read' => 1]);

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 一键全部已读（业务接口）
     * POST：无参数
     */
    public function readAll()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        Db::name('notification')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 截断正文用于通知展示（保留换行但限制长度）
     */
    protected function shorten(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) > self::CONTENT_SHOW_MAX) {
            $text = mb_substr($text, 0, self::CONTENT_SHOW_MAX);
        }
        // 通知卡片单行展示，将换行替换为空格
        return str_replace(["\r", "\n"], ' ', $text);
    }

    /**
     * 时间文案：今天显示 HH:mm，昨天显示"昨天"，其余显示 Y-m-d
     */
    protected function timeText(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        $now = time();
        $todayStart = strtotime(date('Y-m-d', $now));
        if ($ts >= $todayStart) {
            return date('H:i', $ts);
        }
        if ($ts >= $todayStart - 86400) {
            return '昨天';
        }
        return date('Y-m-d', $ts);
    }
}
