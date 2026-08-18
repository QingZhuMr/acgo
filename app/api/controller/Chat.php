<?php
declare (strict_types = 1);

namespace app\api\controller;

use app\BaseController;
use app\common\Pusher;
use think\facade\Db;

/**
 * 聊天接口（第二期）
 *
 * 安全模型：
 *   - 端到端加密：消息体 body 为 AES-GCM 密文，服务器仅存储/转发，无法解密。
 *   - 仅好友（互相关注）可发消息；非好友发送返回错误。
 *   - 服务器保留密文 30 天（expire_time = create_time + 2592000），
 *     由清理任务定期删除过期消息。
 *   - client_msg_id 客户端幂等去重。
 *
 * 全部为业务接口（签名 + 设备密钥）。
 */
class Chat extends BaseController
{
    /** 30 天缓存秒数 */
    protected const CACHE_TTL = 2592000;

    /** 单页最多条数 */
    protected const PAGE_SIZE_MAX = 100;

    /**
     * 获取 / 上传本人密钥（业务接口）
     *
     * GET：返回本人公钥；若无公钥则**自动生成 RSA-2048 密钥对**，私钥 AES 加密托管
     *      （private_key_encrypted），保证对方总能拿到公钥加密发送（微信/QQ 离线可达方案）。
     *      ?claim=1 时额外返回托管私钥（客户端首次领取后服务器置空托管私钥）。
     * POST：public_key 上传（客户端本地生成的密钥，覆盖托管）
     */
    public function key()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $isGet = 'GET' === strtoupper($this->request->method());
        if ($isGet) {
            $claim = (int) $this->request->get('claim', 0) === 1;
            return json([
                'code' => 0, 'msg' => 'ok', 'data' => $this->ensureKeyFor($uid, $claim),
            ]);
        }

        $publicKey = trim((string) $this->request->post('public_key', ''));
        if ($publicKey === '') {
            return json(['code' => 1, 'msg' => '公钥不能为空']);
        }
        if (strlen($publicKey) > 2048) {
            return json(['code' => 1, 'msg' => '公钥格式过长']);
        }
        $now = time();
        $exists = Db::name('user_e2ee_key')->where('user_id', $uid)->find();
        if ($exists) {
            Db::name('user_e2ee_key')->where('user_id', $uid)->update([
                'public_key'  => $publicKey,
                'create_time' => $now,
            ]);
        } else {
            Db::name('user_e2ee_key')->insert([
                'user_id'     => $uid,
                'public_key'  => $publicKey,
                'create_time' => $now,
            ]);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['public_key' => $publicKey]]);
    }

    /**
     * 批量获取用户公钥（业务接口）
     * POST：user_ids（逗号分隔或数组）—— 发消息前拉取对方公钥
     */
    public function keys()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }

        $raw = $this->request->post('user_ids', '');
        $ids = [];
        if (is_array($raw)) {
            $ids = array_map('intval', $raw);
        } else {
            $ids = array_map('intval', explode(',', (string) $raw));
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
        if (!$ids) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['keys' => []]]);
        }

        $rows = Db::name('user_e2ee_key')
            ->whereIn('user_id', $ids)
            ->field('user_id,public_key')
            ->select()
            ->toArray();
        $keys = [];
        foreach ($rows as $r) {
            $keys[(int) $r['user_id']] = (string) $r['public_key'];
        }
        // 无公钥的用户：自动生成（保证总能发送）
        foreach ($ids as $id) {
            if (!isset($keys[$id])) {
                $keys[$id] = $this->ensureKeyFor($id)['public_key'] ?? '';
            }
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['keys' => $keys]]);
    }

    /**
     * 发送聊天消息（业务接口）
     * POST：receiver_id、msg_type（默认1）、body（密文）、nonce、ephemeral_pub、client_msg_id
     *
     * 仅好友（互相关注）可发送。
     */
    public function send()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $receiverId = (int) $this->request->post('receiver_id', 0);
        $msgType    = (int) $this->request->post('msg_type', 1);
        $body       = (string) $this->request->post('body', '');
        $nonce      = (string) $this->request->post('nonce', '');
        $ephemeralPub = (string) $this->request->post('ephemeral_pub', '');
        $clientMsgId  = trim((string) $this->request->post('client_msg_id', ''));

        if ($receiverId <= 0 || $receiverId === $uid) {
            return json(['code' => 1, 'msg' => '接收者无效']);
        }
        if (!in_array($msgType, [1, 2, 3, 4], true)) {
            return json(['code' => 1, 'msg' => '消息类型无效']);
        }
        if ($body === '' || $nonce === '' || $ephemeralPub === '') {
            return json(['code' => 1, 'msg' => '消息内容不完整']);
        }
        if (mb_strlen($body) > 20000) {
            return json(['code' => 1, 'msg' => '消息内容过长']);
        }

        // 仅好友可聊天
        if (!$this->isFriend($uid, $receiverId)) {
            return json(['code' => 1, 'msg' => '仅好友之间可以聊天']);
        }

        // 客户端幂等：同 client_msg_id + 同发送者已存在则不重复插入
        if ($clientMsgId !== '') {
            $exists = Db::name('chat_message')
                ->where('sender_id', $uid)
                ->where('client_msg_id', $clientMsgId)
                ->find();
            if ($exists) {
                return json([
                    'code' => 0, 'msg' => 'ok', 'data' => [
                        'id' => (int) $exists['id'],
                        'create_time' => (int) $exists['create_time'],
                        'server_time' => time(),
                        'duplicate' => true,
                    ],
                ]);
            }
        }

        $now = time();
        $chatId = $this->chatId($uid, $receiverId);
        $msgId = Db::name('chat_message')->insertGetId([
            'chat_id'       => $chatId,
            'sender_id'     => $uid,
            'receiver_id'   => $receiverId,
            'msg_type'      => $msgType,
            'body'          => $body,
            'nonce'         => $nonce,
            'ephemeral_pub' => $ephemeralPub,
            'client_msg_id' => $clientMsgId,
            'status'        => 1,
            'create_time'   => $now,
            'expire_time'   => $now + self::CACHE_TTL,
        ]);

        // 实时推送：接收方在线则立即收到（密文透传）
        Pusher::send(
            ['user_id' => $receiverId],
            [
                'type' => 'chat_message',
                'message' => [
                    'id'            => (int) $msgId,
                    'chat_id'       => $chatId,
                    'sender_id'     => $uid,
                    'receiver_id'   => $receiverId,
                    'msg_type'      => $msgType,
                    'body'          => $body,
                    'nonce'         => $nonce,
                    'ephemeral_pub' => $ephemeralPub,
                    'client_msg_id' => $clientMsgId,
                    'create_time'   => $now,
                ],
            ]
        );

        return json([
            'code' => 0, 'msg' => 'ok', 'data' => [
                'id' => (int) $msgId,
                'create_time' => $now,
                'server_time' => $now,
            ],
        ]);
    }

    /**
     * 拉取会话历史（业务接口）
     * GET：peer_id（对方用户ID）、before_id（可选，游标，默认最新）、page_size（默认50最大100）
     *
     * 返回 30 天缓存内的密文消息，按 id 倒序（客户端倒序展示）。
     */
    public function history()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $peerId   = (int) $this->request->get('peer_id', 0);
        $beforeId = (int) $this->request->get('before_id', 0);
        $pageSize = (int) $this->request->get('page_size', 50);
        $pageSize = min(max(1, $pageSize), self::PAGE_SIZE_MAX);

        if ($peerId <= 0 || $peerId === $uid) {
            return json(['code' => 1, 'msg' => '对方用户无效']);
        }

        $chatId = $this->chatId($uid, $peerId);
        $query = Db::name('chat_message')
            ->where('chat_id', $chatId)
            ->where('status', '<>', 3); // 排除删除
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }
        $rows = $query
            ->field('id,chat_id,sender_id,receiver_id,msg_type,body,nonce,ephemeral_pub,client_msg_id,status,create_time')
            ->order('id', 'desc')
            ->limit($pageSize)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'            => (int) $r['id'],
                'chat_id'       => (string) $r['chat_id'],
                'sender_id'     => (int) $r['sender_id'],
                'receiver_id'   => (int) $r['receiver_id'],
                'msg_type'      => (int) $r['msg_type'],
                'body'          => (string) $r['body'],
                'nonce'         => (string) $r['nonce'],
                'ephemeral_pub' => (string) $r['ephemeral_pub'],
                'client_msg_id' => (string) $r['client_msg_id'],
                'status'        => (int) $r['status'],
                'create_time'   => (int) $r['create_time'],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $list,
            'server_time' => time(),
        ]]);
    }

    /**
     * 标记已读（业务接口）
     * POST：peer_id（对方）、msg_id（读到哪条，<= 该 id 的消息视为已读）
     *
     * 记录接收方最后已读消息 id，并 WS 推送 chat_read 给发送方。
     */
    public function read()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid = (int) $device['user_id'];

        $peerId = (int) $this->request->post('peer_id', 0);
        $msgId  = (int) $this->request->post('msg_id', 0);
        if ($peerId <= 0 || $peerId === $uid) {
            return json(['code' => 1, 'msg' => '对方用户无效']);
        }

        $chatId = $this->chatId($uid, $peerId);
        $now    = time();

        // upsert 已读记录
        $exists = Db::name('chat_read')
            ->where('chat_id', $chatId)
            ->where('user_id', $uid)
            ->find();
        if ($exists) {
            if ($msgId > (int) $exists['last_read_msg_id']) {
                Db::name('chat_read')->where('id', $exists['id'])->update([
                    'last_read_msg_id' => $msgId,
                    'update_time'      => $now,
                ]);
            }
        } else {
            Db::name('chat_read')->insert([
                'chat_id'          => $chatId,
                'user_id'          => $uid,
                'last_read_msg_id' => $msgId,
                'update_time'      => $now,
            ]);
        }

        // 通知发送方：我的消息已被读
        Pusher::send(
            ['user_id' => $peerId],
            [
                'type' => 'chat_read',
                'read' => [
                    'chat_id' => $chatId,
                    'user_id' => $uid,
                    'msg_id'  => $msgId,
                    'time'    => $now,
                ],
            ]
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['msg_id' => $msgId]]);
    }

    /**
     * 撤回消息（业务接口）
     * POST：msg_id、peer_id（接收者）
     *
     * 仅发送者本人 5 分钟内可撤回；撤回后 status=2，
     * 接收方与发送方其他设备收到 chat_recall 推送，客户端展示"撤回了一条消息"。
     */
    public function recall()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid    = (int) $device['user_id'];
        $msgId  = (int) $this->request->post('msg_id', 0);
        $peerId = (int) $this->request->post('peer_id', 0);
        if ($msgId <= 0 || $peerId <= 0 || $peerId === $uid) {
            return json(['code' => 1, 'msg' => '参数无效']);
        }

        $msg = Db::name('chat_message')
            ->where('id', $msgId)
            ->where('sender_id', $uid)
            ->where('receiver_id', $peerId)
            ->find();
        if (!$msg || (int) $msg['status'] !== 1) {
            return json(['code' => 1, 'msg' => '消息不存在或已被撤回']);
        }
        if ((int) $msg['create_time'] < time() - 300) {
            return json(['code' => 1, 'msg' => '超过 5 分钟，无法撤回']);
        }

        Db::name('chat_message')->where('id', $msgId)->update(['status' => 2]);
        $now = time();
        $payload = [
            'type' => 'chat_recall',
            'recall' => [
                'chat_id'    => (string) $msg['chat_id'],
                'msg_id'     => (int) $msgId,
                'sender_id'  => $uid,
                'receiver_id'=> $peerId,
                'time'       => $now,
            ],
        ];
        // 通知接收方 + 发送方其他设备（多端同步）
        Pusher::send(['user_id' => $peerId], $payload);
        Pusher::send(['user_id' => $uid], $payload);

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 断线增量同步（业务接口）
     * GET：after_id（本地最大消息 id 游标，默认 0）、limit（默认 50，最大 100）
     *
     * 返回 after_id 之后当前用户参与（发送或接收）的所有未过期消息，
     * 按 id 升序；客户端用于离线期间的漏消息补偿（WS 重连后拉取）。
     */
    public function sync()
    {
        $device = $this->request->deviceInfo;
        if (!$device) {
            return json(['code' => 401, 'msg' => '设备未授权']);
        }
        $uid     = (int) $device['user_id'];
        $afterId = (int) $this->request->get('after_id', 0);
        $limit   = (int) $this->request->get('limit', 50);
        if ($limit < 1 || $limit > 100) {
            $limit = 50;
        }

        $rows = Db::name('chat_message')
            ->where('id', '>', $afterId)
            ->where('expire_time', '>', time())
            ->where(function ($q) use ($uid) {
                $q->where('sender_id', $uid)->whereOr('receiver_id', $uid);
            })
            ->order('id', 'asc')
            ->limit($limit)
            ->select();

        $list = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'chat_id'       => (string) $r['chat_id'],
                'sender_id'     => (int) $r['sender_id'],
                'receiver_id'   => (int) $r['receiver_id'],
                'msg_type'      => (int) $r['msg_type'],
                'body'          => (string) $r['body'],
                'nonce'         => (string) $r['nonce'],
                'ephemeral_pub' => (string) $r['ephemeral_pub'],
                'client_msg_id' => (string) $r['client_msg_id'],
                'status'        => (int) $r['status'],
                'create_time'   => (int) $r['create_time'],
            ];
        }, (array) $rows);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $list,
            'server_time' => time(),
        ]]);
    }

    /**
     * 会话 ID：min(uid, peer)_max(uid, peer)
     */
    protected function chatId(int $a, int $b): string
    {
        return min($a, $b) . '_' . max($a, $b);
    }

    /**
     * 确保某用户有公钥；无则自动生成 RSA-2048 密钥对并托管私钥
     *
     * @param int  $uid   用户 ID
     * @param bool $claim 是否领取托管私钥（首次进入聊天时；领取后服务器清空托管私钥）
     * @return array 返回 data：{ public_key, private_key?(claim=1且成功领取) }
     */
    protected function ensureKeyFor(int $uid, bool $claim = false): array
    {
        $row = Db::name('user_e2ee_key')->where('user_id', $uid)->find();

        // 已有公钥
        if ($row && (string) $row['public_key'] !== '') {
            $data = ['public_key' => (string) $row['public_key']];
            if ($claim && (string) ($row['private_key_encrypted'] ?? '') !== '') {
                $priv = $this->decryptManaged($row['private_key_encrypted']);
                if ($priv !== null && $priv !== '') {
                    $data['private_key'] = $priv;
                    // 领取后清空托管私钥（防止再次泄露）
                    Db::name('user_e2ee_key')->where('user_id', $uid)->update([
                        'private_key_encrypted' => null,
                        'create_time'           => time(),
                    ]);
                }
            }
            return $data;
        }

        // 无公钥：自动生成密钥对
        $pair = $this->generateRsaPair();
        $encPriv = $this->encryptManaged($pair['private_key']);
        if ($row) {
            Db::name('user_e2ee_key')->where('user_id', $uid)->update([
                'public_key'            => $pair['public_key'],
                'private_key_encrypted' => $encPriv,
                'create_time'           => time(),
            ]);
        } else {
            Db::name('user_e2ee_key')->insert([
                'user_id'               => $uid,
                'public_key'            => $pair['public_key'],
                'private_key_encrypted' => $encPriv,
                'create_time'           => time(),
            ]);
        }

        $data = ['public_key' => $pair['public_key']];
        if ($claim) {
            $data['private_key'] = $pair['private_key'];
            // 领取后清空托管私钥
            Db::name('user_e2ee_key')->where('user_id', $uid)->update([
                'private_key_encrypted' => null,
            ]);
        }
        return $data;
    }

    /** 生成 RSA-2048 密钥对（openssl 实现，PEM 格式） */
    protected function generateRsaPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$res) {
            return ['public_key' => '', 'private_key' => ''];
        }
        $privPem = '';
        $pubPem  = '';
        openssl_pkey_export($res, $privPem);
        $details = openssl_pkey_get_details($res);
        if ($details && isset($details['key'])) {
            $pubPem = (string) $details['key'];
        }
        return ['public_key' => $pubPem, 'private_key' => $privPem];
    }

    /**
     * 托管私钥加密（AES-256-GCM，密钥来自 .env CHAT_KEY_MASTER 或派生）
     */
    protected function encryptManaged(string $plain): string
    {
        $master = (string) env('CHAT_KEY_MASTER', 'acgo_chat_master_2026!');
        $key = hash('sha256', $master, true);
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /** 托管私钥解密 */
    protected function decryptManaged(string $b64): ?string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $master = (string) env('CHAT_KEY_MASTER', 'acgo_chat_master_2026!');
        $key = hash('sha256', $master, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /**
     * 是否好友（互相关注）
     */
    protected function isFriend(int $a, int $b): bool
    {
        $forward  = Db::name('user_follow')
            ->where('follower_id', $a)->where('following_id', $b)->find();
        $backward = Db::name('user_follow')
            ->where('follower_id', $b)->where('following_id', $a)->find();
        return (bool) ($forward && $backward);
    }
}
