<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
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

        $rows = Db::name('notification')
            ->where('user_id', $uid)
            ->field('id,from_uid,from_nickname,from_avatar,post_id,post_content,comment_id,comment_content,type,is_read,create_time')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'              => (int) $r['id'],
                'from_uid'        => (int) $r['from_uid'],
                'from_nickname'   => (string) $r['from_nickname'],
                'from_avatar'     => (string) $r['from_avatar'],
                'post_id'         => (int) $r['post_id'],
                'post_content'    => $this->shorten((string) $r['post_content']),
                'comment_id'      => (int) $r['comment_id'],
                'comment_content' => $this->shorten((string) $r['comment_content']),
                'type'            => (int) $r['type'],
                'is_read'         => (int) $r['is_read'],
                'create_time'     => (int) $r['create_time'],
                'create_time_text' => $this->timeText((int) $r['create_time']),
            ];
        }

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
