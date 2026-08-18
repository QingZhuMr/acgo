# ACGO 后端 API 接口文档

> **维护约定**：每次新增或修改接口，**必须**同步更新本文档。更新时遵循本文格式（接口路径、HTTP 方法、鉴权、请求参数、返回结构、功能说明、错误码）。
>
> 应用：`api`（`app/api/`）。框架：ThinkPHP 8 多应用。

---

## 0. 通用约定

### 0.1 URL 格式

`/api/{控制器小写}/{方法驼峰名}`

> **重要**：ThinkPHP 8 方法分发**不做下划线→驼峰转换**，URL 中的方法名必须与控制器方法名**完全一致**（如 `deletePost` → `/post/deletePost`，**不是** `delete_post`）。

### 0.2 鉴权分级

| 级别 | 要求 | 接口 |
|------|------|------|
| **公开接口** | 仅签名 | `user/login`、`user/register`、`user/domain`、`captcha/image`、`post/list`、`post/detail` |
| **业务接口** | 签名 + 设备密钥 | 除公开外所有接口 |

### 0.3 签名机制（所有接口）

| 参数 | 位置 | 类型 | 必填 | 说明 |
|------|------|------|------|------|
| `X-Timestamp` | Header | int | 是 | 请求时间戳（秒），防重放；普通接口 ±300s，`user/domain` ±600s |
| `X-Signature` | Header | string | 是 | `HMAC-SHA256(API_SECRET, "timestamp\|path")` |
| `X-Device-Key` | Header | string | 业务接口必填 | 设备级密钥；也可用 query/body 的 `device_key` 传递 |

### 0.4 统一返回结构

```json
{ "code": int, "msg": string, "data": ... }
```

- `code=0`：成功
- `code=401`：未授权（设备未授权 / 签名失效 / 账号状态异常）
- `code=1`：业务校验失败（`msg` 为具体原因）

### 0.5 Redis 缓存使用约定

项目 Redis 缓存走 **ThinkPHP `Cache::store('redis')`**（配置 `config/cache.php` 的 `redis` store，参数 `REDIS_HOST/PORT/PASSWORD/DB/TIMEOUT` 读 `.env`，store 前缀为空、各 Cache 类自带完整业务前缀）。当前已使用缓存的场景：

| 场景 | 缓存类 | key 前缀 | TTL | 失效时机 |
|------|--------|----------|-----|---------|
| 用户昵称/头像（帖子列表、帖子详情评论、通知列表触发者） | `UserInfoCache` | `acgo:user_info:` | 10 分钟 | 改昵称/换头像时主动清除 |
| 表情包列表（`sticker/list`） | `StickerCache` | `acgo:sticker:list` | 10 分钟 | 上传/删除表情包时主动清除 |
| 客户端最新版本（`update/check`，按平台） | `UpdateCache` | `acgo:update:latest:{platform}` | 10 分钟 | 后台新增/编辑/删除/上下线版本时主动清除 |

> **降级策略**：Redis 不可用（未装 phpredis 扩展 / 未启动 / 连接失败）时自动降级直查库，业务不受影响，仅失去缓存红利。

### 0.6 用户被删除（幽灵用户）兜底约定

帖子/评论/通知可能引用已注销或物理删除的用户（`user_id` 成为"幽灵引用"）。为保证接口不报错且展示友好，统一兜底规则：

- **用户昵称/头像**：`UserInfoCache` 查不到的用户返回 `nickname="已注销用户"`、`avatar=默认头像`（帖子列表、帖子详情评论、通知列表触发者均自动生效）。
- **存在但昵称为空/头像为空**：昵称显示「用户+ID」，头像显示默认头像。
- **楼中楼 `reply_to_name`**：历史数据为空或回复目标已注销时，服务端按 `reply_to_uid` 联表补齐，目标不存在则显示「已注销用户」。
- **后台管理**（`admin/post` 帖子列表/详情、`admin/report` 举报列表）：`LEFT JOIN user` 结果为 NULL 时统一展示「已注销用户」+ 默认头像。

> 该兜底不影响正常用户展示，仅对"数据在但用户没了"的场景生效。

---

## 1. 用户（User）

### 1.1 用户注册（公开）

- **路径**：`POST /api/user/register`
- **鉴权**：公开（仅签名）
- **功能**：注册新用户，需图形验证码；校验手机号格式与唯一性；创建钱包记录。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| nickname | string | 是 | 昵称，非空 |
| password | string | 是 | 密码，≥6 位 |
| phone | string | 是 | 手机号，格式 `^1[3-9]\d{9}$`，唯一（登录标识） |
| captcha | string | 是 | 验证码内容（对应 captcha_id） |
| captcha_id | string | 是 | 验证码 ID（来自 captcha/image） |

> 验证码一次性，300 秒有效。

### 1.2 用户登录（公开）

- **路径**：`POST /api/user/login`
- **鉴权**：公开（仅签名）
- **功能**：登录返回 token + device_key；单设备在线（同账号同平台新登录踢旧设备）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| account | string | 是 | 手机号或 UID（数字） |
| password | string | 是 | 密码 |
| platform | string | 否 | `android/ios/win/mac/h5`，默认 win |
| device_id | string | 是 | 客户端设备 UUID（≤64 字符） |

**返回 data**：`token`、`device_key`、`platform`、`device_id`、`banned`、`ban_reason`、`user { id, phone, nickname, avatar, paw(熊爪余额 int), cny(人民币余额 float) }`

> **平台维护中**：返回 `code=503`，`msg` 为维护公告（或默认"平台维护中"），`data.maintenance` 为 `{ enabled, notice }`。客户端据此禁止登录并展示公告。

### 1.3 账号状态检查（业务）

- **路径**：`GET /api/user/status`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：自动登录/会话恢复检查；返回注销/封禁/密码被改/异地登录状态。

**返回 data**：`account_cancelled`、`banned`、`ban_reason`、`password_changed`、`kicked`、`user { id, phone, nickname, avatar, paw, cny }`

### 1.4 修改昵称（业务）

- **路径**：`POST /api/user/profile`
- **鉴权**：业务（签名 + 设备密钥）

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| nickname | string | 是 | 新昵称，≤50 字符 |

**返回 data**：`nickname`

### 1.5 上传头像（业务）

- **路径**：`POST /api/user/uploadAvatar`（multipart/form-data）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：保存到 `public/static/avatar/`，更新用户 avatar。>**256KB** 压缩到 ≤256KB（GIF 动画保留）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| avatar | file | 是 | 仅 jpg/png/gif/webp/bmp，原始 ≤**5MB** |

**返回 data**：`avatar`（相对路径 `/static/avatar/xxx`）

### 1.6 隐私设置（业务）

- **路径**：`GET /api/user/privacy`（获取） / `POST /api/user/privacy`（保存）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：主页「动态」「收藏」对他人的可见性（为访问他人主页功能做准备）。
  - GET：返回当前用户隐私配置。
  - POST：保存隐私配置，可只传其一（未传的保持原值）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| privacy_posts | int | POST 可选 | 动态可见性：`1`公开 `0`仅自己 |
| privacy_favorites | int | POST 可选 | 收藏可见性：`1`公开 `0`仅自己 |

**返回 data（GET / POST）**：`privacy_posts`、`privacy_favorites`（`1`公开 `0`仅自己）

> **他人主页访问规则**：`post/myPosts`、`post/favorites` 支持 `target_uid` 参数；当 `target_uid` 为他人且对方相应可见性为 `0` 时，返回空 `list` + `visible=0`（客户端据此展示"仅自己可见"占位）。

### 1.7 浏览历史（业务）

- **路径**：`GET /api/user/browseHistory`（获取） / `POST /api/user/browseHistory`（记录或清空）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：跨设备浏览历史（存 `user.browse_history` JSON 字段，**最多 120 条**）。
  - GET：返回浏览历史列表（联表帖子 + 作者昵称/头像，帖子已删除/隐藏的自动过滤）。
  - POST `post_id`：记录一条浏览（同 post_id 去重置顶，限 120 条），返回更新后的完整列表。
  - POST `clear=1`：清空全部浏览历史。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | POST 记录时 | 帖子 ID |
| clear | int | POST 清空时 | `1`=清空全部 |
| delete_post_id | int | POST 删除单条时 | 删除指定帖子 ID 的浏览记录 |

**返回 data（GET / POST）**：`list[]`（字段同广场列表 + `browse_at`、`browse_time_text` 浏览时间）、`page`（无分页）

### 1.8 关注体系（业务）

**关注 / 取关**：`POST /api/user/follow`
- 参数：`target_uid`（必填，被关注用户 ID）
- 行为：已关注则取消关注，未关注则关注（切换）；不能关注自己
- 返回 data：`is_following`（当前是否已关注）、`is_mutual`（是否互相关注=好友）

**好友列表**：`GET /api/user/friends`
- 参数：`page`、`page_size`（默认 10，最大 50）
- 功能：**好友 = 互相关注**（我关注了他 且 他关注了我），按成为好友时间倒序
- 返回 data：`list[] { user_id, nickname, avatar, follow_time }`、`page`、`total`、`has_more`

**关注状态**：`GET /api/user/followStatus`
- 参数：`target_uid`
- 返回 data：`is_following`、`is_mutual`

**我关注的列表**：`GET /api/user/following`
- 参数：`page`、`page_size`（默认 20，最大 50）
- 返回 data：`list[]{ user_id, nickname, avatar, follow_time, is_mutual }`、`page`、`total`、`has_more`

**我的粉丝列表**：`GET /api/user/followers`
- 参数：`page`、`page_size`
- 返回 data：同上（`is_mutual` = 我是否也关注了对方）

**关注统计**：`GET /api/user/followCounts`
- 返回 data：`following`（关注数）、`followers`（粉丝数）、`friends`（互相关注数）

### 1.9 修改密码（业务）

- **路径**：`POST /api/user/changePassword`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：校验原密码后改密，强制当前用户所有设备下线（需重新登录）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| old_password | string | 是 | 原密码 |
| new_password | string | 是 | 新密码，≥6 位 |

### 1.10 获取域名 + WebSocket 参数（公开，校时入口）

- **路径**：`GET /api/user/domain`
- **鉴权**：公开（仅签名，时间戳放宽 ±600s，为校时入口）
- **功能**：客户端启动后第一个调用；返回 api 域名、环境、WebSocket 参数、平台维护状态；同时返回服务端权威时间校时。

**返回 data**：`api_domain`、`app_env(dev/prod)`、`ws_scheme(ws/wss)`、`ws_host`、`ws_port`、`ws_url`、`server_time`、`maintenance`

- `maintenance` (object)：平台维护状态 `{ enabled(bool), notice(string 公告) }`。客户端启动时据此展示维护公告。

---

## 2. 图形验证码（Captcha）

### 2.1 生成验证码（公开）

- **路径**：`GET /api/captcha/image`
- **鉴权**：公开（仅签名）
- **功能**：生成 4 位图形验证码（base64 PNG），用于注册防机器人。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| captcha_id | string | 是 | 客户端生成的 UUID（≤64 字符） |

**返回 data**：`captcha_id`、`image`（`data:image/png;base64,...`）

> 验证码 300 秒有效，一次性，存 `verify_code` 表（type=register）。

---

## 3. 广场帖子（Post）

### 3.1 帖子列表（公开）

- **路径**：`GET /api/post/list`
- **鉴权**：公开（仅签名）
- **功能**：热度权重随机推荐（刷新结果不同）；支持关键词搜索和加载更多排除。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 10，最大 50 |
| exclude_ids | string | 否 | 逗号分隔的帖子 ID，用于"加载更多"排除已看 |
| keyword | string | 否 | 关键词，模糊匹配正文，此时按时间倒序 |
| device_key | string | 否 | 可选登录态，用于返回 `is_liked` |

**返回 data**：`list[] { id, user_id, content(截取80字), images[], comment_count, like_count, is_liked, create_time, create_time_text, nickname, avatar, location }`、`page`

### 3.2 帖子详情（公开）

- **路径**：`GET /api/post/detail`
- **鉴权**：公开（仅签名）
- **功能**：帖子详情 + 顶层评论分页 + 楼中楼子评论。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 帖子 ID |
| page | int | 否 | 顶层评论页码，默认 1 |
| page_size | int | 否 | 评论每页条数，默认 20，最大 50 |
| device_key | string | 否 | 可选登录态，用于返回 `is_liked`/`is_favorited` |

**返回 data**：
- `post { id, user_id, content, images[], comment_count, favorite_count, like_count, is_liked, is_favorited, create_time, create_time_text, nickname, avatar, location }`
- `comments[]`：顶层评论，每项含 `id, user_id, nickname, avatar, content, images[], like_count, is_liked, parent_id, reply_to_uid, create_time, create_time_text, location, children[]`（楼中楼，子评论字段同顶层，另含 `reply_to_name`）
- `comment_total`、`comment_top_total`、`page`

> **`comment_total` 为帖子评论总数（含楼中楼子评论）**——楼中楼也算评论，与帖子 `comment_count` 口径一致。
> **`comment_top_total` 为顶层评论总数**——顶层评论分页依据，客户端用它判断"加载更多评论"是否还有剩余（避免含楼中楼时误判永远加载不完）。

> 评论的 `nickname`/`avatar` 为**实时联表 user 查询**（`post_comment` 表已不存昵称/头像冗余字段），用户改名/换头像后评论即时同步。
>
> `location` 为发布/评论时的 **IP 归属地快照**（省/直辖市，解析失败为「未知」）。服务端在写入时调用 IP 定位解析一次并落库（`post.location` / `post_comment.location`），读取时直接返回、**不再调接口**。原始 IP 不返回给客户端。IP 定位服务商在后台「系统设置」可切换（**高德 / 腾讯 / 百度**），需填写对应 Key；未配置时 `location` 恒为「未知」。

### 3.3 发帖（业务）

- **路径**：`POST /api/post/publish`（multipart/form-data）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：发布帖子，含文字审核和图片上传。图片经服务端压缩（>1MB 压缩到 ≤1MB，GIF 动画保留）。服务端自动记录发布 IP 并解析归属地为 `location` 快照。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| content | string | 是 | 正文，≤2000 字，需过敏感词审核 |
| images | file[] | 否 | 最多 **5** 张，单张原始 ≤**10MB**，仅 jpg/png/gif/webp/bmp |

**返回 data**：`id`（新帖子 ID）

### 3.4 回帖 / 评论（业务）

- **路径**：`POST /api/post/comment`（multipart/form-data）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：发表评论或楼中楼回复；帖子热度 +1、评论数 +1。图片经服务端压缩（>1MB 压缩到 ≤1MB）。服务端自动记录评论 IP 并解析归属地为 `location` 快照。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 帖子 ID |
| content | string | 是 | 评论内容，≤500 字 |
| parent_id | int | 否 | 父评论 ID（楼中楼），0 为顶层 |
| reply_to_uid | int | 否 | 回复目标用户 ID（默认取父评论作者） |
| images | file[] | 否 | 评论图片，**只能 1 张**，单张原始 ≤**10MB** |

**返回 data**：`id`（新评论 ID）

### 3.5 举报（业务）

- **路径**：`POST /api/post/report`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：举报用户 / 帖子 / 评论；防重复举报；被举报帖子/评论计数 +1（举报用户不累加计数）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| target_uid | int | 否 | 被举报用户 ID（>0 举报用户，此时可不传 post_id） |
| post_id | int | 否 | 帖子 ID（举报帖子/评论时必填） |
| comment_id | int | 否 | 评论 ID（>0 举报评论，0 举报帖子） |
| reason | string | 是 | 举报原因，枚举：`spam/porn/abuse/ad/infringe/other` |
| content | string | 否 | 举报说明，≤500 字 |

> **举报用户**（`target_uid>0`）：不能举报自己；后台「举报处理」选择删除时封禁该用户（status=2）并级联软删其帖子与评论。

### 3.6 帖子点赞 / 取消（切换，业务）

- **路径**：`POST /api/post/like`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：切换帖子点赞；**不允许给自己的帖子点赞**。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 帖子 ID |

**返回 data**：`is_liked`、`like_count`

### 3.7 帖子收藏 / 取消（切换，业务）

- **路径**：`POST /api/post/favorite`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：切换帖子收藏；**不允许收藏自己的帖子**。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 帖子 ID |

**返回 data**：`is_favorited`、`favorite_count`

### 3.8 我的收藏列表（业务）

- **路径**：`GET /api/post/favorites`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回指定用户收藏的帖子，按收藏时间倒序，只取正常状态帖子。
  - `target_uid` 为空或等于当前 uid：返回**自己**的收藏（个人中心「收藏」tab）。
  - `target_uid` 为**他人**：访问他人主页场景，按对方 `privacy_favorites` 校验——为 `0`（仅自己）时返回空 `list` + `visible=0`。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 10，最大 50 |
| target_uid | int | 否 | 目标用户 ID（访问他人主页时传） |

**返回 data**：`list[]`（字段同广场列表：`id, user_id, nickname, avatar, content, images[], comment_count, like_count, is_liked, is_favorited(恒为 true), favorite_count, create_time, create_time_text, location`）、`page`、`visible(1可见 0对方设为仅自己可见)`

### 3.9 我的动态列表（业务）

- **路径**：`GET /api/post/myPosts`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回指定用户发布的帖子，按发布时间倒序，只取正常状态帖子（个人中心「动态」tab / 他人主页「动态」）。
  - `target_uid` 为空或等于当前 uid：返回**自己**的动态。
  - `target_uid` 为**他人**：访问他人主页场景，按对方 `privacy_posts` 校验——为 `0`（仅自己）时返回空 `list` + `visible=0`。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 10，最大 50 |
| target_uid | int | 否 | 目标用户 ID（访问他人主页时传） |

**返回 data**：`list[]`（字段同广场列表：`id, user_id, nickname, avatar, content, images[], comment_count, like_count, is_liked, is_favorited(恒为 false——自己的帖子不能收藏), favorite_count, create_time, create_time_text, location`）、`page`、`visible(1可见 0对方设为仅自己可见)`

### 3.10 聊天（业务，第二期，E2EE）

> **安全模型**：端到端加密——消息体 `body` 为 AES-GCM 密文，`enc_key` 为 RSA-OAEP 加密后的 AES 密钥，**服务器仅存储/转发密文，无法解密**（零知识）。仅**好友（互相关注）**可聊天；服务器保留密文 **30 天**自动清理；`client_msg_id` 客户端幂等去重。接口见 `docs/聊天系统设计.md`。

**上传/获取公钥**：`POST /api/chat/key`（上传 `public_key`） / `GET /api/chat/key`（获取）
- 返回 data：`public_key`（未上传为 null）

**批量获取用户公钥**：`POST /api/chat/keys`
- 参数：`user_ids`（逗号分隔）
- 返回 data：`keys{ user_id: public_key }`

**发送消息**：`POST /api/chat/send`
- 参数：`receiver_id`、`msg_type`（默认1）、`body`（密文）、`nonce`、`ephemeral_pub`（加密后的 AES 密钥）、`client_msg_id`
- 校验：仅好友可发；`client_msg_id` 幂等
- 返回 data：`id`、`create_time`；成功后 WS 推送 `chat_message`（密文透传）
- 30 天过期：`expire_time = create_time + 2592000`

**拉取会话历史**：`GET /api/chat/history`
- 参数：`peer_id`、`before_id`（游标）、`page_size`（默认50最大100）
- 返回 data：`list[]`（密文消息，按 id 倒序）

### 3.10 评论点赞 / 取消（切换，业务）

- **路径**：`POST /api/post/commentLike`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：切换评论点赞；**不允许给自己的评论点赞**。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| comment_id | int | 是 | 评论 ID |

**返回 data**：`is_liked`、`like_count`

### 3.11 删除帖子（业务）

- **路径**：`POST /api/post/deletePost`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：软删自己的帖子（status=0），级联软删所有评论。仅限本人。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 帖子 ID |

### 3.12 删除评论 / 楼中楼（业务）

- **路径**：`POST /api/post/deleteComment`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：软删自己的评论（status=0）；删除顶层评论时级联软删其楼中楼。仅限本人。
- **评论数联动**：删除后**自动扣减帖子 `comment_count`**（楼中楼也算评论）：删楼中楼减 1，删顶层评论减 1 + 其楼中楼数。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| comment_id | int | 是 | 评论 ID |

---

## 4. 表情包（Sticker）

> 表情包功能当前**未在帖子/评论中使用**，保留供未来用户聊天场景。

### 4.1 表情包列表（业务）

- **路径**：`GET /api/sticker/list`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回全局正常表情包列表（≤200 条，时间倒序）。列表本体走 `StickerCache`（Redis 缓存，TTL 10 分钟），`is_mine` 按当前用户实时计算；上传/删除表情包后缓存自动失效。

**返回 data**：`list[] { id, url, width, height, mime, is_mine }`

### 4.2 上传表情包（业务）

- **路径**：`POST /api/sticker/upload`（raw body：`application/octet-stream`）
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：上传表情包，服务端兜底校验格式/大小/尺寸。文件内容经 `php://input` 原始字节。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| body | binary | 是 | 文件原始字节（raw body） |
| filename | string(query) | 否 | 文件名（经 query 传，用于扩展名判定） |

**校验规则**：大小 ≤256KB、边长 ≤240px、格式 jpg/png/gif/webp（按文件头签名识别）。

**返回 data**：`id, url, width, height, mime`

### 4.3 删除表情包（业务）

- **路径**：`POST /api/sticker/delete`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：软删自己上传的表情包（status=0），保留历史评论图片访问。仅限本人。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| sticker_id | int | 是 | 表情包 ID |

---

## 5. 版本更新（Update）

### 5.1 检查更新（业务）

- **路径**：`GET /api/update/check`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回该平台最新上线版本信息；无更新返回空对象。最新版本行走 `UpdateCache`（按平台 Redis 缓存，TTL 10 分钟）；后台新增/编辑/删除/上下线版本时缓存自动失效。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| platform | string | 否 | `win/mac/ios/android`，默认 win |
| client_version | string | 否 | 客户端当前版本号，如 `0.1.0` |

**返回 data（有更新时）**：`version`、`title`、`message`、`platform`、`download_url`、`mandatory`

> 无更新时 `data` 为 `{}`（`new \stdClass()`）。

---

## 6. 通知（Notification）

> 他人评论了我的帖子 / 回复了我的评论时生成一条通知。通知在 `post/comment` 落库评论后同步写入 `notification` 表，并经 `Pusher` 实时推送 `reply_notice`（对方在线立即收到），离线时由客户端下次轮询未读数兜底。

### 6.1 通知列表（业务）

- **路径**：`GET /api/notification/list`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回当前用户的通知列表（时间倒序，分页），并返回未读数。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 20，最大 50 |

**返回 data**：`list[] { id, from_uid, from_nickname, from_avatar, post_id, post_content, comment_id, comment_content, type, is_read, create_time, create_time_text }`、`total`、`unread_count`、`page`

- `type`：`1`=评论了我的帖子，`2`=回复了我的评论
- `post_content`/`comment_content`：展示用正文摘要（已截断、换行替换为空格）
- `from_nickname`/`from_avatar`：触发者昵称/头像**实时联表 `user` 表获取**（经 Redis 缓存，TTL 10 分钟，见 `app/common/UserInfoCache.php`），`notification` 表不再存冗余快照；头像为空时返回默认头像

> **Redis 缓存说明**：昵称/头像缓存走 **ThinkPHP `Cache::store('redis')` 门面**（配置见 `config/cache.php` 的 `redis` store，参数 `REDIS_HOST/PORT/PASSWORD/DB/TIMEOUT` 从 `.env` 读取，key 前缀 `acgo:user_info:`）。Redis 不可用（未装 phpredis 扩展 / 未启动 / 连接失败）时自动降级**直查库**，业务不受影响，仅失去缓存红利。用户改昵称 / 换头像后调用 `UserInfoCache::forget()` 主动失效。

### 6.2 未读数（业务）

- **路径**：`GET /api/notification/unreadCount`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回当前用户未读通知数量（客户端定时轮询更新铃铛角标）。

**返回 data**：`unread_count`

### 6.3 单条已读（业务）

- **路径**：`POST /api/notification/read`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：将指定通知标记为已读（仅能操作自己的通知）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 通知 ID |

### 6.4 一键全部已读（业务）

- **路径**：`POST /api/notification/readAll`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：将当前用户所有未读通知标记为已读。

---

## 7. 应用标识（Index）

### 7.1 应用信息

- **路径**：`GET /api/index/index`
- **鉴权**：见下方说明
- **功能**：返回应用标识。

**返回 data**：`app`（`api`）、`domain`（如 `127.0.1.3`）

> ⚠️ `index/index` **未列入 publicActions**，按中间件逻辑会被当作业务接口处理（需签名 + 设备密钥）。若客户端实际不调用此接口，可忽略或后续调整。

---

## 接口索引

| 接口 | 路径 | 方法 | 鉴权 |
|------|------|------|------|
| 用户注册 | `/api/user/register` | POST | 公开 |
| 用户登录 | `/api/user/login` | POST | 公开 |
| 账号状态 | `/api/user/status` | GET | 业务 |
| 修改昵称 | `/api/user/profile` | POST | 业务 |
| 上传头像 | `/api/user/uploadAvatar` | POST | 业务 |
| 隐私设置 | `/api/user/privacy` | GET/POST | 业务 |
| 浏览历史 | `/api/user/browseHistory` | GET/POST | 业务 |
| 关注/取关 | `/api/user/follow` | POST | 业务 |
| 好友列表 | `/api/user/friends` | GET | 业务 |
| 关注状态 | `/api/user/followStatus` | GET | 业务 |
| 修改密码 | `/api/user/changePassword` | POST | 业务 |
| 域名+WS | `/api/user/domain` | GET | 公开 |
| 验证码 | `/api/captcha/image` | GET | 公开 |
| 帖子列表 | `/api/post/list` | GET | 公开 |
| 帖子详情 | `/api/post/detail` | GET | 公开 |
| 发帖 | `/api/post/publish` | POST | 业务 |
| 评论 | `/api/post/comment` | POST | 业务 |
| 举报 | `/api/post/report` | POST | 业务 |
| 帖子点赞 | `/api/post/like` | POST | 业务 |
| 帖子收藏 | `/api/post/favorite` | POST | 业务 |
| 我的收藏列表 | `/api/post/favorites` | GET | 业务 |
| 我的动态列表 | `/api/post/myPosts` | GET | 业务 |
| 评论点赞 | `/api/post/commentLike` | POST | 业务 |
| 删除帖子 | `/api/post/deletePost` | POST | 业务 |
| 删除评论 | `/api/post/deleteComment` | POST | 业务 |
| 表情包列表 | `/api/sticker/list` | GET | 业务 |
| 上传表情包 | `/api/sticker/upload` | POST | 业务 |
| 删除表情包 | `/api/sticker/delete` | POST | 业务 |
| 检查更新 | `/api/update/check` | GET | 业务 |
| 通知列表 | `/api/notification/list` | GET | 业务 |
| 通知未读数 | `/api/notification/unreadCount` | GET | 业务 |
| 通知单条已读 | `/api/notification/read` | POST | 业务 |
| 通知全部已读 | `/api/notification/readAll` | POST | 业务 |
| 应用信息 | `/api/index/index` | GET | 业务* |

---

## 8. WebSocket 实时推送（非 HTTP 接口）

客户端登录后建立 WebSocket 长连接（地址来自 `user/domain` 的 `ws_url`），服务端可主动推送：

| type | 说明 | payload |
|------|------|---------|
| `auth_result` | 认证结果 | `{ type, ok(bool), msg }` |
| `force_logout` | 强制下线（封禁/密码变更/异地登录） | `{ type, reason }` |
| `update` | 版本更新推送 | `{ type, update{ version,title,message,platform,download_url,mandatory } }` |
| `wallet` | 钱包余额推送 | `{ type, wallet{ paw, cny } }` |
| `maintenance` | 平台维护状态推送 | `{ type, enabled(bool), notice(string) }` |
| `reply_notice` | 帖子回复通知（他人评论了我的帖子/回复了我的评论） | `{ type, notification{ id, from_uid, from_nickname, from_avatar, post_id, post_content, comment_id, comment_content, type, create_time } }` |

> **维护推送机制**：后台系统设置开启/关闭维护并保存时，向 `push_queue` 写入广播（user_id=0），Swoole 轮询推送给所有在线客户端。客户端收到后实时展示/移除维护公告（**不强制退出登录**）。

> **通知推送机制**：`post/comment` 写入评论后同步写入 `notification` 表（不存触发者昵称/头像冗余快照），并经 `Pusher::send` 向被回复人推送 `reply_notice`（推送体携带实时昵称/头像供立即展示）。客户端收到后弹出应用内/系统通知并刷新铃铛角标；离线时通过定时轮询 `notification/unreadCount` 兜底补齐。
>
> **type=3 被关注通知**：`user/follow` 关注成功时给被关注者落库 `notification`（type=3）+ WS 推送 `follow_notice`；通知列表展示「xx 关注了你」，点击跳转**关注人的个人主页**。

---

## 9. 后台维护管理

后台「系统设置」页可配置平台维护：

- **维护开关**：`maintenance_enabled`（1=开启，0=关闭）
- **维护公告**：`maintenance_notice`（≤500 字）

开启后效果：
- 客户端登录接口返回 `503`（禁止登录 + 展示公告）
- 已在线客户端通过 WebSocket 实时收到 `maintenance` 推送 → 展示常驻公告（不退出登录）
- 新启动客户端通过 `user/domain` 的 `maintenance` 字段展示公告

---

## 10. 后台帖子 / 评论管理接口（admin）

后台帖子管理（`admin/Post`）提供帖子与评论的管理接口，均需管理员登录 + 密码二次验证：

| 接口 | 路径 | 说明 |
|------|------|------|
| 帖子列表 | `GET admin/Post/getList` | 分页 + 搜索 + 排序（含 `comment_count`） |
| 帖子预览（含评论树） | `GET admin/Post/detail` | 帖子详情 + 顶层/楼中楼评论 |
| 删除帖子 | `POST admin/Post/delete` | 软删帖子（密码二次验证），级联软删其所有评论 |
| 增加热度 | `POST admin/Post/addHeat` | 帖子热度 +N |
| **删除评论** | `POST admin/Post/deleteComment` | **软删评论/楼中楼（密码二次验证）**：删顶层级联删其楼中楼，并**自动扣减帖子 `comment_count`** |

> `admin/Post/deleteComment` 参数：`comment_id`、`admin_password`。删除后写入 `admin_log`（类型 `comment_delete`）。后台帖子预览弹框内每条正常评论右侧提供「删除」入口。
