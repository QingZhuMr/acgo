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
- 功能：**好友 = 互相关注**（我关注了他 且 他关注了我）；**联系人排序**：按昵称拼音排序（GBK 编码，英文排中文前），并返回 `initial`（昵称首字拼音首字母 A-Z，无法识别为 `#`），供客户端按首字母分组（微信/QQ 联系人风格）
- 返回 data：`list[] { user_id, nickname, avatar, follow_time, online, initial }`、`page`、`total`、`has_more`

**关注状态**：`GET /api/user/followStatus`
- 参数：`target_uid`
- 返回 data：`is_following`、`is_mutual`

**拉黑 / 取消拉黑**：`POST /api/user/block`
- 参数：`target_uid`（必填）
- 行为：已拉黑则解除，未拉黑则拉黑（切换）；**拉黑时自动解除双方关注**（好友关系解除）
- 返回 data：`is_blocked`

**拉黑状态**：`GET /api/user/blockStatus`
- 参数：`target_uid`
- 返回 data：`is_blocked`（我是否拉黑了他）、`blocked_me`（他是否拉黑了我）

**取消好友**：`POST /api/user/removeFriend`
- 参数：`target_uid`（必填）
- 行为：解除**双方**的关注关系（好友 = 互相关注，取关一方即可解除好友）

**设置好友备注**：`POST /api/user/setRemark`
- 参数：`target_uid`（必填）、`remark`（最长 50 字，可空=清除备注）
- 行为：备注存于「我关注对方」的 `user_follow` 行，仅自己可见；`friends` 列表返回 `remark` 字段

**拉黑可见性规则**：
- 关注互斥：任一方向已拉黑则 `follow` 关注被拒
- 广场 `post/list`、帖子详情 `post/detail`：过滤「我拉黑的」和「拉黑我的」用户的帖子
- 他人主页 `post/myPosts` / `post/favorites`：任一方拉黑 → 返回空 `list` + `blocked=1`（客户端展示"已被拉黑"占位，且不能关注）

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

**返回 data**：`list[] { id, user_id, content(截取80字), images[], image_sizes[], comment_count, like_count, is_liked, create_time, create_time_text, nickname, avatar, location }`、`page`（`image_sizes` 与 images 等长的 `[[宽,高],...]`，客户端据此按图片真实比例动态布局卡片封面，最大 3:4；旧帖子无该字段）

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

**上传/获取密钥**：`POST /api/chat/key`（上传 `public_key`） / `GET /api/chat/key`（获取）
- `GET`：返回本人 `public_key`。**用户无公钥时服务器自动生成 RSA-2048 密钥对并 AES 托管私钥**（保证对方总能拿到公钥加密发送，微信/QQ 离线可达方案）
- `GET ?claim=1`：额外返回 `private_key`（领取托管私钥后服务器清空托管，仅本人可领）
- `POST`：上传本地生成公钥，覆盖托管
- 返回 data：`public_key`（+ `private_key` 当 claim 成功）

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

**标记已读**：`POST /api/chat/read`
- 参数：`peer_id`、`msg_id`（读到哪条）
- 功能：记录接收方最后已读消息 id（`chat_read` 表），并 WS 推送：
  - `chat_read` 给**发送方**（发送方可展示"已读"状态，多端一致）
  - `chat_read_sync` 给**读方自己**所有在线设备（**跨端已读同步**：清零未读角标，发起读的设备也收，幂等无害）
- **离线恢复**：`history()`/`sync()` 返回每条消息附带 `peer_read` 字段（0/1）——对**当前用户发出的消息**，对方在 `chat_read` 表的 `last_read_msg_id >= 本条 msg_id` 即为 1。这样**接收方离线期间读过的消息，发送方上线/切号后拉取历史也能恢复"已读"状态**（不依赖 WS 实时推送，`peerReadMap()` 按 chat_id 批量查游标）

**撤回消息**：`POST /api/chat/recall`
- 参数：`peer_id`、`msg_id`
- 功能：**仅发送者本人 2 分钟内可撤回**；撤回后 `status=2`，WS 推送 `chat_recall` 给接收方与发送方其他设备（多端同步），客户端展示"撤回了一条消息"
- 限制：消息不存在/已被撤回/超过 2 分钟返回错误

**断线增量同步**：`GET /api/chat/sync`
- 参数：`after_id`（本地最大消息 id 游标，默认 0）、`limit`（默认 50，最大 100）
- 功能：返回 `after_id` 之后当前用户**参与（发送或接收）**的所有未过期消息，按 id 升序；客户端用于**离线期间的漏消息补偿**（WS 重连后拉取），与 WS 推送共用同一套解密入库逻辑
- 返回：`data.list`（消息数组，字段同 history：id/chat_id/sender_id/receiver_id/msg_type/body/nonce/ephemeral_pub/client_msg_id/status/create_time）、`data.server_time`
- 坑：`select()` 后**必须 `->toArray()`** 再 array_map（ThinkPHP Collection 直接 `(array)` 强转会得到 `["items"=>...]` 结构，`$r['id']` 未定义 → 500，曾导致离线同步完全失效）
- 返回 data：`list[]`（同 history 消息结构）、`server_time`

**聊天图片上传**：`POST /api/chat/upload`
- multipart/form-data：字段 `image`
- 功能：图片压缩保存到 `public/static/chat/`（最大边 1080px、≤512KB），返回相对 URL；客户端将 URL 作为 JSON（`{"url":"..."}`）**加密为消息 body** 发送（`msg_type=2`），接收方解密后渲染图片
- 返回 data：`{ url }`

**清空会话**：`POST /api/chat/clear`
- 参数：`peer_id`
- 功能：删除当前用户与该 peer 之间的**服务器密文记录**，并 WS 推送 `chat_clear` 给双方所有在线设备（多端同步清空本地聊天记录）
- 注意：**当前 Windows/macOS 客户端"清空聊天记录"为纯本地操作，不调用此接口**（不影响对方设备）。此接口保留供其他端/兼容旧版本使用

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

> 表情包用于**用户聊天**（消息页表情面板选择、发送 `msg_type=3` 表情消息）；聊天中收到的表情可右键「添加到表情」收藏进自己的表情库。
> **账号隔离**：`sticker/list` 仅返回**当前用户自己**的表情包（上传的 + 收藏的，按 uid 隔离），不同账号看到的内容互不相同；缓存 key 按 uid 维度（`acgo:sticker:list:<uid>`），上传/删除/收藏只清本人缓存。

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

### 4.4 添加到表情（业务）

- **路径**：`POST /api/sticker/add`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：收藏聊天中收到的表情包到自己表情库（软引用：图片文件不复制，删除收藏不影响他人）。校验 url 必须是平台已有表情包；已添加过幂等返回成功。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| url | string | 是 | 表情相对路径（`/static/sticker/xx.png`） |

---

## 5. 版本更新（Update）

### 5.1 检查更新（业务）

- **路径**：`GET /api/update/check`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回该平台最新上线版本信息；无更新返回空对象。最新版本行走 `UpdateCache`（按平台 Redis 缓存，TTL 10 分钟）；后台新增/编辑/删除/上下线版本时缓存自动失效。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| platform | string | 否 | `win/mac/linux/android/ios/ipados/harmony`，默认 win |
| client_version | string | 否 | 客户端当前版本号，如 `0.1.0` |

**返回 data（有更新时）**：`version`、`title`、`message`、`platform`、`download_url`、`mandatory`

> 无更新时 `data` 为 `{}`（`new \stdClass()`）。

### 5.2 上传安装包（管理后台）

- **路径**：`POST /admin/update/upload`
- **鉴权**：管理员会话
- **功能**：按平台 + 版本号生成文件名并存储到 `public/app/{platform}/`，返回完整下载 URL（index 域名）。
  文件名规则：`{appEn}-{platform}-{version}.{ext}`，如 `yeahbear-win-0.1.1.zip`。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| platform | string | 是 | `win/mac/linux/android/ios/ipados/harmony` |
| version | string | 是 | 版本号，如 `0.1.1` |
| file | file | 是 | 安装包文件（≤200MB；扩展名 exe/zip/dmg/pkg/apk/hsp/tar/gz/appimage/aab/7z） |

**返回 data**：`url`（如 `https://www.yeahbear.cn/app/win/yeahbear-win-0.1.1.zip`）、`filename`

> 管理页「新增版本推送」表单的下载地址栏有「上传安装包」按钮：选择文件 → 按已填平台/版本号上传 → 成功后自动填入 URL。

---

## 6. 通知（Notification）

> 他人评论了我的帖子 / 回复了我的评论时生成一条通知。通知在 `post/comment` 落库评论后同步写入 `notification` 表，并经 `Pusher` 实时推送 `reply_notice`（对方在线立即收到），离线时由客户端下次轮询未读数兜底。
>
> 「开始寻找」配对成功（type=6）/ 解除绑定（type=7）/ 绑定对象长期未上线自动解除并补偿（type=8）也落库通知，经 `Pusher` 推送 `match_notice`（客户端 `NotificationCenter` 实时处理 + 系统通知）。

### 6.1 通知列表（业务）

- **路径**：`GET /api/notification/list`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回当前用户的通知列表（时间倒序，分页），并返回未读数。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 20，最大 50 |

**返回 data**：`list[] { id, from_uid, from_nickname, from_avatar, post_id, post_content, comment_id, comment_content, type, is_read, create_time, create_time_text }`、`total`、`unread_count`、`page`

- `type`：`1`=评论了我的帖子，`2`=回复了我的评论，`3`=关注了我，`4`=赞了我的动态，`5`=收藏了我的动态，`6`=配对成功（`post_content` 存星球名），`7`=解除绑定，`8`=绑定对象长期未上线自动解除（`post_content` 存星球 ID，`comment_content` 存「补偿 X 熊爪」）
- `post_content`/`comment_content`：展示用正文摘要（已截断、换行替换为空格）
- `from_nickname`/`from_avatar`：触发者昵称/头像**实时联表 `user` 表获取**（经 Redis 缓存，TTL 10 分钟，见 `app/common/UserInfoCache.php`），`notification` 表不再存冗余快照；头像为空时返回默认头像

---

## 12. AI/机器人专用接口（`app/api/controller/Ai.php`）

**鉴权**：**免 HMAC 签名、免设备密钥**（中间件对 `ai/*` 前缀直接放行），仅需请求头：

```
X-AI-Token: <AI_TOKEN>   // 对应 .env 的 AI_TOKEN，生产可随时更换
```

> 用途：本地自动化脚本（机器人灌帖/评论/互动等）直接调用，无需模拟客户端签名与登录流程。

**绑定 IP 规则**：批量建号时为每个账号分配**固定随机国内 IP**（`app/common/AiIpPool.php`，内置 31 省运营商段，含省份归属地），存 `user.bind_ip` / `user.bind_location`。AI 发帖/回帖一律使用该账号绑定 IP 落库（`post.ip` / `post_comment.ip`），保证同账号 IP 恒定、账号间分散全国。可用 `rebindIp` 重绑。

### 12.1 批量创建用户

- **路径**：`POST /api/ai/batchCreate`
- **功能**：批量创建机器人/种子账号，手机号从 `10000000001` 起始递增（格式正确 11 位、不占用真实手机号段、不冲突）；每个账号自动分配固定随机国内 IP。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| prefix | string | 是 | 昵称前缀（昵称 = 前缀 + 序号，如 `二次元酱01`） |
| count | int | 是 | 创建数量 1-50 |
| password | string | 否 | 统一密码，默认 `123456`（≥6位） |

**返回 data**：`accounts[] { id, nickname, phone, password, ip, location }`

### 12.2 上传头像

- **路径**：`POST /api/ai/uploadAvatar`（multipart）
- **功能**：给指定用户设置头像（存 `public/static/avatar/ai_{uid}.{ext}`，覆盖同名，更新 user 表）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 目标用户 id |
| avatar | file | 是 | 头像图片（png/jpg/jpeg/gif/webp，≤5MB） |

**返回 data**：`avatar`（相对路径，如 `/static/avatar/ai_22.png`）

### 12.3 AI 发帖

- **路径**：`POST /api/ai/publish`（multipart）
- **功能**：以指定账号发布帖子，文字审核/图片压缩与前台一致；IP/归属地取该账号绑定 IP。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 发帖账号 id |
| content | string | 是 | 正文，≤2000 字 |
| images | file[] | 否 | 图片，≤5 张，≤10MB/张（jpg/png/gif/webp/bmp） |

**返回 data**：`post_id`

### 12.4 AI 回帖/评论

- **路径**：`POST /api/ai/comment`（multipart）
- **功能**：以指定账号评论/回复（支持楼中楼）；与前台一致生成通知 + WS 推送（可用 `notify=0` 关闭）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 评论账号 id |
| post_id | int | 是 | 目标帖子 id |
| content | string | 是 | 评论内容，≤500 字 |
| parent_id | int | 否 | 楼中楼父评论 id，默认 0（顶层） |
| reply_to_uid | int | 否 | 楼中楼回复目标用户 id（不传则取父评论作者） |
| images | file[] | 否 | 图片，≤1 张，≤10MB/张 |
| notify | int | 否 | 是否通知帖子作者/被回复人，默认 1 |

**返回 data**：`comment_id`

### 12.5 AI 帖子点赞

- **路径**：`POST /api/ai/like`
- **功能**：点赞/取消点赞（切换），不能给自己的帖子点赞；首次点赞默认通知作者。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 点赞账号 id |
| post_id | int | 是 | 目标帖子 id |
| notify | int | 否 | 是否通知帖子作者，默认 1 |

**返回 data**：`is_liked`、`like_count`

### 12.6 AI 帖子收藏

- **路径**：`POST /api/ai/favorite`
- **功能**：收藏/取消收藏（切换），不能收藏自己的帖子；首次收藏默认通知作者。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 收藏账号 id |
| post_id | int | 是 | 目标帖子 id |
| notify | int | 否 | 是否通知帖子作者，默认 1 |

**返回 data**：`is_favorited`、`favorite_count`

### 12.7 AI 评论点赞

- **路径**：`POST /api/ai/commentLike`
- **功能**：评论点赞/取消点赞（切换），不能给自己的评论点赞。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 点赞账号 id |
| comment_id | int | 是 | 目标评论 id |

**返回 data**：`is_liked`、`like_count`

### 12.8 AI 关注/取关

- **路径**：`POST /api/ai/follow`
- **功能**：关注/取关（切换），拉黑互斥、不能关注自己，规则与前台一致；关注时默认通知对方。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 发起账号 id |
| target_uid | int | 是 | 目标用户 id |
| notify | int | 否 | 是否通知被关注人，默认 1 |

**返回 data**：`is_following`、`is_mutual`

### 12.9 查询账号信息

- **路径**：`GET /api/ai/accounts?uid=1,2,3` 或 `?phone=10000000001`
- **功能**：查询账号信息（含绑定 IP），最多 100 条。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | string | 二选一 | 单个或逗号分隔多个用户 id |
| phone | string | 二选一 | 单个手机号 |

**返回 data**：`accounts[] { id, nickname, phone, avatar, status, bind_ip, bind_location }`

### 12.10 重新绑定 IP

- **路径**：`POST /api/ai/rebindIp`
- **功能**：给指定账号重新分配绑定 IP（可指定省份）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| uid | int | 是 | 目标账号 id |
| province | string | 否 | 指定省份（如 `广东`），不传随机省份 |

**返回 data**：`ip`、`location`

### 12.11 帖子列表

- **路径**：`GET /api/ai/postList?page=1&page_size=20&full=0`
- **功能**：返回正常（status=1）帖子列表，按 id 倒序；供 `comment`/`interact` 脚本不指定 `post_id` 时随机选帖；`full=1` 返回完整帖子内容供 DeepSeek AI 分析生成评论/判断点赞。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页 1-50，默认 20 |
| full | int | 否 | 0=content 截断200字（默认），1=完整帖子内容 |

**返回 data**：`list[] { id, content, comment_count, create_time }`
- **折叠聚合**（未读）：同目标多条未读合并为一条——键 `type + post_id + comment_id`（type=3 关注全部合并）：
  - `from_nickname` 为「昵称1、昵称2等N人」（最多前 2 个昵称 + 等N人）
  - `id_list`：聚合内通知 id 数组（点击标已读/删除用）；`from_uids`：触发者 uid 数组；`count`：聚合条数
  - **已读**通知不聚合，单条返回（`id_list=[id]`、`count=1`）

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

### 6.5 批量删除（业务）

- **路径**：`POST /api/notification/delete`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：删除指定通知（仅能删自己的；最多 200 条）。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| ids | JSON 数组字符串 | 是 | 通知 ID 列表，如 `[1,2,3]` |

---

## 7. 星球（Planet）

### 7.1 星球列表（公开）

- **路径**：`GET /api/planet/list`
- **鉴权**：公开（仅签名，游客可浏览）
- **功能**：返回后台创建的**已启用**星球列表，按 `sort` 升序、`id` 升序。客户端星球页据此展示星球名称/介绍，并按 `seed`+`type` 程序化生成对应星球外观（同一种子+类型 → 同一星球）。

**返回 data**：数组，每项：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 星球 ID |
| seed | int | 星球种子（后台创建时自动生成，决定星球地形） |
| type | string | 星球类型（见下表） |
| name | string | 星球名称 |
| intro | string | 星球介绍 |

**星球类型 `type`**：

| type 值 | 说明 | 客户端表现 |
|---------|------|-----------|
| `earth` | 类地行星 | 蓝海/绿草地/白沙漠/极地冰雪 |
| `jupiter` | 气态行星 | 木星式纬向条纹云带+大红斑 |
| `lava` | 岩浆行星 | 熔岩海+亮橙熔岩陆块+黑曜石裂纹 |
| `rock` | 岩石行星 | 灰蓝岩海+灰岩陆块（无植被） |
| `gradient_green` | 绿色渐变 | 渐变海+浅绿渐变陆块 |
| `gradient_blue` | 蓝色渐变 | 渐变海+浅蓝渐变陆块 |
| `gradient_pink` | 粉色渐变 | 渐变海+浅粉渐变陆块 |
| `gradient_red` | 红色渐变 | 渐变海+浅红渐变陆块 |
| `blackhole` | 黑洞 | 全黑+橙色亮光晕+不自转 |

**示例**：
```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    { "id": 1, "seed": 36184205, "type": "earth", "name": "翡翠星", "intro": "一片广袤的绿色大陆。" }
  ]
}
```

### 7.2 后台星球管理（admin，`app/admin/controller/Planet.php`）

| 接口 | 路径 | 方法 | 说明 |
|------|------|------|------|
| 管理页 | `/admin/planet/index` | GET | 页面视图 |
| 列表 | `/admin/planet/getList` | GET | 分页 + 关键词/状态搜索 |
| 新增 | `/admin/planet/add` | POST | `name`（必填≤50）/`type`（必填，9 类白名单）/`intro`（≤500）/`sort`；**种子自动生成 `random_int(1,99999999)`** |
| 编辑 | `/admin/planet/edit` | POST | `id`/`name`/`type`/`intro`/`sort`/`status`（种子不可修改）/`match_cost`（单次匹配费用，熊爪）/`free_match_count`（该星球每账号默认永久免费次数，0=无） |
| 删除 | `/admin/planet/delete` | POST | `id` |
| 上下线 | `/admin/planet/toggle` | POST | `id`/`status` |
| 寻找配置 | `/admin/planet/findConfig` | POST | `id`/`enable_find`（是否开放"开始寻找"）/`find_fields`（JSON 数组，参与匹配的字段）/`force_face_auth`、`force_edu_auth`、`force_asset_auth`（是否强制对应认证才可配对）/`match_cost`（单次匹配费用，熊爪，0=未配置回落默认）/`free_match_count`（该星球每账号默认永久免费次数，0=无） |

#### 6.x 赠送寻人免费次数（Find，`app/admin/controller/Find.php`）

> 后台运营针对 **某个账号 + 某个星球** 赠送「永久免费次数」，累计存 `user_find_free` 表；用户发起寻找时用尽前不扣熊爪，用尽后回落 `match_cost` 扣熊爪。另外每个星球可在「星球管理」创建/编辑或「寻人配置」设置 `free_match_count`（该星球每账号默认永久免费次数），用户首次发起自动生效；此页赠送为在默认次数之上**额外追加**。菜单入口：运营 → 赠送寻人次数。

| 接口 | 路径 | 方法 | 说明 |
|------|------|------|------|
| 赠送页面 | `/admin/find/grant` | GET | 赠送表单页（选账号/手机号、星球、次数，密码二次验证） |
| 执行赠送 | `/admin/find/doGrant` | POST | `target`（UID 或手机号）/`planet_id`/`count`(>0)/`admin_password`；幂等叠加，写 `admin_log`（action=`find_grant_free`） |
| 查询余额 | `/admin/find/userFree` | GET | `target`（UID 或手机号），返回该账号在各星球的 `free_total`/`free_used`/`free_remaining` |

> 建表 SQL：`sql/planet.sql`；后台菜单「运营 → 星球管理」由同一 SQL 插入（按标题动态查找父级菜单，不硬编码 id）。

### 7.3 星球寻找配对（Find，`app/api/controller/Find.php`）

> 「开始寻找」：用户在某星球填写「我的资料 + 目标条件」，进入寻找状态（同星球另一用户也在寻找且目标条件命中 → 立即配对）。**配对成功即成为绑定人**：自动互相关注（好友状态）、双方匹配状态结束；**绑定期间**不能取关/拉黑/解除好友，也不能在任何星球发起寻找；可手动解除绑定。各星球独立配置字段，认证为「配置 + 预留校验」（后台勾选强制认证，发起/匹配时校验 `user_auth` 表，未认证返回需认证；认证功能后续单独开发）。我的资料首填后自动复用（`user_find_profile` 按 uid 存一份，其他星球自动带出可修改）。

| 接口 | 路径 | 方法 | 鉴权 | 说明 |
|------|------|------|------|------|
| 获取寻找配置 | `/api/find/config?planet_id=1` | GET | 业务 | 星球寻找开关、字段配置、强制认证标志、我的资料、我当前寻找/绑定状态 |
| 发起寻找 | `/api/find/start` | POST | 业务 | 校验绑定/认证后写入资料并进入寻找，同步匹配，成功则落库配对 + 双向关注 + 通知双方 |
| 停止寻找 | `/api/find/stop` | POST | 业务 | 手动停止当前星球的寻找状态 |
| 寻找状态 | `/api/find/status` | GET | 业务 | 我当前寻找/绑定状态（客户端启动/回到前台兜底查询） |
| 解除绑定 | `/api/find/unbind` | POST | 业务 | 解除与绑定人的绑定关系，通知对方 + WS 推送 |

#### 7.3.1 获取寻找配置（业务）

- **路径**：`GET /api/find/config?planet_id=1`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回星球「开始寻找」配置与我的资料/状态。

**返回 data**：

| 字段 | 类型 | 说明 |
|------|------|------|
| enable_find | int | 0=该星球未开放寻找 |
| find_fields | array | 参与匹配的字段列表（JSON 数组），元素见下表 |
| force_face_auth | int | 是否强制颜值认证 |
| force_edu_auth | int | 是否强制学历认证 |
| force_asset_auth | int | 是否强制资产认证 |
| auth | object | 我的认证状态 `{ face_status, edu_status, asset_status }`（1=已认证，2=未通过，0=未认证） |
| profile | object \| null | 我的资料（`user_find_profile`，首填后自动复用）：`gender`、`age`、`income_range`、`personality`、`city`、`face_score`、`education` |
| my_find | object \| null | 我正在寻找：`{ id, planet_id, status, update_time }`（status 0=寻找中 1=已配对） |
| my_bind | object \| null | 我的绑定关系：`{ id, uid, nickname, avatar, planet_id, planet_name, create_time }` |
| balance_paw | int | 我当前的熊爪余额（用于发起寻找付费时校验/展示） |
| match_cost | int | 本次发起寻找的**有效单次费用**（熊爪）：>0 表示付费，0 表示免费。星球 `match_cost` 为 0 时回落 `config.find_default_match_cost` 默认值 |
| free_remaining | int | 该账号在本星球的**永久免费次数剩余**（星球 `free_match_count` 默认 + 后台按 账号+星球 赠送累计，用尽前本次免费；≤0 时本次将扣 `match_cost` 熊爪） |
| match_timeout | int | 匹配超时秒数（后台 `config.find_match_timeout` 配置，默认 60）：发起后超时未配对自动停止并退回本次扣费 |
| cost_notice | string | 费用规则说明文案（后台 `config.find_cost_notice` 配置），前端展示用 |

**find_fields 元素**：`gender`（性别，必选）、`age`（年龄，必选）、`income_range`（月收入范围）、`personality`（性格）、`city`（是否只寻找同城）、`face_score`（颜值打分）、`education`（学历）。元素格式：`{ "key": "gender", "label": "性别", "required": 1 }`。

> **费用规则（寻人配对付费）**：`planet` 表 `match_cost`（单次费用，0=免费）由后台 `planet/findConfig` 配置；`config` 表 `find_default_match_cost`/`find_cost_notice` 作星球未配置时的全局默认/文案。**免费次数为「账号永久次数」**：① 每个星球可在后台「星球管理」创建/编辑或「寻人配置」设置 `planet.free_match_count`（该星球每个账号的**默认永久免费次数**，0=无），用户在该星球首次发起寻找时自动建档生效；② 运营还可在后台「赠送寻人次数」页（`/admin/find/grant`）针对 **某个账号 + 某个星球** 额外赠送。累计记录于 `user_find_free` 表（free_total/free_used，用尽前不扣熊爪，用尽后回落 `match_cost` 扣熊爪）。**原每日免费次数（`user_find.free_used_count`/`free_stat_date`、`config.find_default_free_match_count`）已废弃不再使用。**

> **配对失败退回**：每次发起寻找会把本次扣费写入 `user_find`（`charge_type`=free/paw、`charge_amount`、`refunded`=0）。配对未成功时退回：手动取消/重复发起调用 `find/stop` 时退回；超时（超过 `config.find_match_timeout` 秒未配对）在访问 `find/config`、`find/status` 时懒清理并退回；过期任务不再参与后续配对。免费次数回补 `user_find_free.free_used`（-1），熊爪回补 `wallet.balance_paw`。

#### 7.3.2 发起寻找（业务）

- **路径**：`POST /api/find/start`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：校验后写入/更新我的资料（自动复用），进入寻找状态并尝试匹配同星球寻找中用户。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| planet_id | int | 是 | 星球 ID |
| params | JSON 字符串 | 是 | 我的资料 + 目标条件，如 `{"my_gender":"男","target_gender":"女","my_age":"25","target_age_min":"20","target_age_max":"30",...}` |

**返回 data**：

| 字段 | 类型 | 说明 |
|------|------|------|
| status | int | 0=进入寻找中（暂未匹配到），1=匹配成功 |
| matched_user | object \| null | 匹配成功的对方：`{ id, nickname, avatar, profile }`（`profile` 为对方填写的资料：`{ gender, age, income_range, personality, city, face_score, edu_level }`） |
| bind | object \| null | 匹配成功后的绑定关系：`{ id, uid, nickname, avatar, planet_id, planet_name, create_time }` |
| is_free | bool | 本次发起是否免费（免费次数内） |
| cost | int | 本次发起消耗熊爪（免费时为 0） |

> **扣费行为**：进入寻找（无论最终是否立即配对）即按费用规则结算（**发起匹配即扣费**）——永久免费次数内（`free_remaining>0`）本次免费并消耗 1 次（无 `user_find_free` 记录时按星球 `free_match_count` 自动建档）；否则若 `match_cost>0` 则从 `wallet.balance_paw` 扣减对应熊爪。余额不足（或钱包记录缺失）返回 `code=1`、`msg='熊爪不足，无法发起寻找'`，不写入寻找记录。免费次数与扣费在数据库事务内完成（扣费失败回滚）。本次扣费写入 `user_find.charge_type`/`charge_amount`（配对失败退回，见 7.3.1 费用规则）。

> 匹配规则：同星球另一 `status=0` 用户（**无论在线/离线**，用户未手动停止寻找即持续参与配对），且互相满足对方的目标条件（年龄范围 / 性别 / 收入 / 性格 / 同城 / 颜值 / 学历按星球 find_fields 配置过滤）；同一星球同一用户不能重复匹配自己；命中后双方 `user_find` 置 `status=1`，写入 `user_bind`（status=1），强制双向关注（`user_follow` 双方互插），双方各落库一条通知（type=6，`post_content`=星球名），并 `Pusher` 推送 `match_notice`。未命中则仅进入寻找状态，返回 `status=0`。
>
> `match_notice` 推送 `notification` 字段：`{ id, from_uid, from_nickname, from_avatar, from_profile, planet_id, planet_name, type, create_time }`（`from_profile` 为对方填写的资料，`id` 为 notification 表通知 id，客户端弹窗后点按钮可标记已读）。**离线兜底**：`Pusher` 写入 `push_queue` 的通知类消息（配对/评论/回复/关注等）在用户离线期间不会被标记为已推送，用户上线 WS 认证后自动补推；`force_logout`/`maintenance` 为即时控制消息，离线不重放。解除绑定推送（type=7）同样携带 `id`。

#### 7.3.3 停止寻找（业务）

- **路径**：`POST /api/find/stop`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：手动停止我当前星球的寻找状态（`user_find` 置 `status=2`），**未成功配对的本次扣费自动退回**（免费次数回补 1 次 / 熊爪回补钱包，幂等）。

**返回 data**：`{ count: 停止条数, refund_type: 'free' | 'paw' | '', refund_amount: int }`；`msg` 携带退回信息（如「已停止寻找，已退回 10 熊爪」「已停止寻找，本次免费次数已退回」）。

#### 7.3.4 寻找状态（业务）

- **路径**：`GET /api/find/status`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：返回我当前寻找/绑定状态（客户端启动、回到前台时兜底，处理 WS 离线期间的配对）。访问时会顺带清理我超时未配对的任务（自动停止并退回扣费）。

**返回 data**：`my_find`、`my_bind`（同 7.3.1）

#### 7.3.5 解除绑定（业务）

- **路径**：`POST /api/find/unbind`
- **鉴权**：业务（签名 + 设备密钥）
- **功能**：解除与绑定人的绑定关系。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| target_uid | int | 是 | 绑定人 uid |

**返回 data**：空

> 解除后 `user_bind` 置 `status=0`；对方落库一条通知（type=7），`Pusher` 推送 `match_notice`；解除后双方恢复可自由取关/拉黑/发起寻找。

> **相关约束**：`user/follow` 取关、`user/removeFriend`、`user/block` 在绑定关系有效期间返回错误「你们是绑定关系，请先解除绑定」；`find/start` 在已有绑定关系时禁止发起。

---

## 8. 应用标识（Index）

### 8.1 应用信息

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
| 拉黑/取消拉黑 | `/api/user/block` | POST | 业务 |
| 拉黑状态 | `/api/user/blockStatus` | GET | 业务 |
| 取消好友 | `/api/user/removeFriend` | POST | 业务 |
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
| 添加到表情 | `/api/sticker/add` | POST | 业务 |
| 删除表情包 | `/api/sticker/delete` | POST | 业务 |
| 检查更新 | `/api/update/check` | GET | 业务 |
| 通知列表 | `/api/notification/list` | GET | 业务 |
| 通知未读数 | `/api/notification/unreadCount` | GET | 业务 |
| 通知单条已读 | `/api/notification/read` | POST | 业务 |
| 通知全部已读 | `/api/notification/readAll` | POST | 业务 |
| 通知批量删除 | `/api/notification/delete` | POST | 业务 |
| 星球列表 | `/api/planet/list` | GET | 公开 |
| 寻找配置 | `/api/find/config` | GET | 业务 |
| 发起寻找 | `/api/find/start` | POST | 业务 |
| 停止寻找 | `/api/find/stop` | POST | 业务 |
| 寻找状态 | `/api/find/status` | GET | 业务 |
| 解除绑定 | `/api/find/unbind` | POST | 业务 |
| 后台·社交关系管理 | `/social/findList` `/social/bindList` `/social/blockList` `/social/followList` `/social/stopFind` `/social/delFind` `/social/unbind` `/social/delBind` `/social/delBlock` `/social/delFollow` | GET/POST | 后台（密码二次验证） |
| 后台·通知管理 | `/notification/getList` `/notification/read` `/notification/readAll` `/notification/del` | GET/POST | 后台（密码二次验证） |
| 后台·表情包管理 | `/sticker/getList` `/sticker/add` `/sticker/setStatus` `/sticker/del` | GET/POST | 后台（密码二次验证） |
| 应用信息 | `/api/index/index` | GET | 业务* |


---

## 9. WebSocket 实时推送（非 HTTP 接口）

客户端登录后建立 WebSocket 长连接（地址来自 `user/domain` 的 `ws_url`），服务端可主动推送：

| type | 说明 | payload |
|------|------|---------|
| `auth_result` | 认证结果 | `{ type, ok(bool), msg }` |
| `force_logout` | 强制下线（封禁/密码变更/异地登录） | `{ type, reason }` |
| `update` | 版本更新推送 | `{ type, update{ version,title,message,platform,download_url,mandatory } }` |
| `wallet` | 钱包余额推送 | `{ type, wallet{ paw, cny } }` |
| `maintenance` | 平台维护状态推送 | `{ type, enabled(bool), notice(string) }` |
| `reply_notice` | 帖子回复通知（他人评论了我的帖子/回复了我的评论） | `{ type, notification{ id, from_uid, from_nickname, from_avatar, post_id, post_content, comment_id, comment_content, type, create_time } }` |
| `like_notice` | 点赞通知（他人赞了我的动态） | `{ type, notification{ ... type=4 } }` |
| `favorite_notice` | 收藏通知（他人收藏了我的动态） | `{ type, notification{ ... type=5 } }` |
| `chat_message` | 新聊天消息（密文透传，客户端 E2EE 解密） | `{ type, message{ id, chat_id, sender_id, receiver_id, msg_type, body, nonce, ephemeral_pub, client_msg_id, create_time } }` |
| `chat_read` | 已读回执：对方读了我的消息 | `{ type, read{ chat_id, user_id, msg_id, time } }` |
| `chat_read_sync` | 跨端已读同步：自己其他设备读了消息（清未读角标） | `{ type, read{ chat_id, user_id, msg_id, time } }` |
| `chat_recall` | 消息撤回 | `{ type, recall{ chat_id, msg_id, sender_id, receiver_id, time } }` |
| `chat_clear` | 清空会话（多端同步清本地记录） | `{ type, clear{ chat_id, sender_id, receiver_id, time } }` |
| `typing` | 对方正在输入（不落库） | `{ type, typing{ chat_id, sender_id, receiver_id } }` |

> **维护推送机制**：后台系统设置开启/关闭维护并保存时，向 `push_queue` 写入广播（user_id=0），Swoole 轮询推送给所有在线客户端。客户端收到后实时展示/移除维护公告（**不强制退出登录**）。

> **通知推送机制**：`post/comment` 写入评论后同步写入 `notification` 表（不存触发者昵称/头像冗余快照），并经 `Pusher::send` 向被回复人推送 `reply_notice`（推送体携带实时昵称/头像供立即展示）。客户端收到后弹出应用内/系统通知并刷新铃铛角标；离线时通过定时轮询 `notification/unreadCount` 兜底补齐。
>
> **type=3 被关注通知**：`user/follow` 关注成功时给被关注者落库 `notification`（type=3）+ WS 推送 `follow_notice`；单条点击跳转**关注人的个人主页**，**聚合（多条未读关注）点击跳转我的粉丝列表**。
>
> **type=4/5 点赞/收藏通知**：`post/like`、`post/favorite` 首次点赞/收藏时给帖子作者落库 `notification`（type=4/5）+ WS 推送 `like_notice`/`favorite_notice`；取消点赞/收藏不产生通知（避免取消轰炸）。

---

## 10. 后台维护管理

后台「系统设置」页可配置平台维护：

- **维护开关**：`maintenance_enabled`（1=开启，0=关闭）
- **维护公告**：`maintenance_notice`（≤500 字）

开启后效果：
- 客户端登录接口返回 `503`（禁止登录 + 展示公告）
- 已在线客户端通过 WebSocket 实时收到 `maintenance` 推送 → 展示常驻公告（不退出登录）
- 新启动客户端通过 `user/domain` 的 `maintenance` 字段展示公告

### 10.1 运营设置（admin，`app/admin/controller/System.php`）

后台「系统设置」页内新增「运营设置」Tab（layui-tab 双面板），与系统设置**分开保存**：

| 接口 | 路径 | 说明 |
|------|------|------|
| 获取全部配置 | `GET admin/System/getConfig` | 返回系统设置 + 运营设置全部配置项 |
| 保存运营设置 | `POST admin/System/saveOperation` | 仅保存运营配置，参数：`unbind_auto_days`、`unbind_auto_compensation` |

运营配置项（存 `config` 表）：

| 配置名 | 默认值 | 说明 |
|--------|--------|------|
| `unbind_auto_days` | `3` | 绑定对象连续多少天未上线则自动解除绑定（1-365） |
| `unbind_auto_compensation` | `20` | 自动解除绑定时补偿被离开方的熊爪数量（0-1000000） |

**自动解绑 + 补偿规则**：
- 判定依据：绑定方（`user_bind` status=1 中任一方）最后在线时间（取 `user_device` 最大 `last_online_time`，兜底 `user.last_login_time`）早于 `time() - unbind_auto_days * 86400` 即视为长期未上线。
- 触发方式：**懒检查**——`Find::config()`/`Find::status()` 访问时检查当前用户的绑定并解绑。（曾实现 WS 每 60 秒全表兜底扫描，因阻塞单 worker 事件循环已移除；超期绑定需双方中任一方再次访问接口时才解除）
- 解除后：绑定置 `status=0` + `unbind_time`；给**被离开方**（仍在线/最后活跃较晚的一方）的 `wallet.balance_paw` 补偿 `unbind_auto_compensation` 熊爪，并通过 WS 推送 `wallet` 实时更新余额；落库 `notification`（type=8）并推送 `match_notice`。
- 幂等：解绑 `UPDATE` 带 `AND status=1`，并发场景下仅首个事务生效。

---

## 11. 后台帖子 / 评论管理接口（admin）

后台帖子管理（`admin/Post`）提供帖子与评论的管理接口，均需管理员登录 + 密码二次验证：

| 接口 | 路径 | 说明 |
|------|------|------|
| 帖子列表 | `GET admin/Post/getList` | 分页 + 搜索 + 排序（含 `comment_count`） |
| 帖子预览（含评论树） | `GET admin/Post/detail` | 帖子详情 + 顶层/楼中楼评论 |
| 删除帖子 | `POST admin/Post/delete` | 软删帖子（密码二次验证），级联软删其所有评论 |
| 增加热度 | `POST admin/Post/addHeat` | 帖子热度 +N |
| **删除评论** | `POST admin/Post/deleteComment` | **软删评论/楼中楼（密码二次验证）**：删顶层级联删其楼中楼，并**自动扣减帖子 `comment_count`** |

> `admin/Post/deleteComment` 参数：`comment_id`、`admin_password`。删除后写入 `admin_log`（类型 `comment_delete`）。后台帖子预览弹框内每条正常评论右侧提供「删除」入口。

## 13. 后台社交 / 通知 / 表情包管理接口（admin）

> 覆盖已有功能的管理能力，入口在后台菜单「运营 → 社交关系 / 通知管理 / 表情包管理」。均**需管理员登录 + 操作密码二次验证**（`admin_password`）。

### 13.1 后台社交关系管理（Social，`app/admin/controller/Social.php`）

| 接口 | 路径 | 说明 |
|------|------|------|
| 管理页 | `GET /social/index` | 页面视图（寻找 / 绑定 / 黑名单 / 关注 四个标签页） |
| 寻找记录列表 | `GET /social/findList` | 分页 + 用户ID / 星球ID / 状态 搜索；`status` 0=寻找中 1=已配对 2=已停止 |
| 绑定关系列表 | `GET /social/bindList` | 分页 + 用户ID 搜索；`status` 1=绑定中 0=已解除 |
| 黑名单列表 | `GET /social/blockList` | 分页 + 用户ID 搜索 |
| 关注列表 | `GET /social/followList` | 分页 + 用户ID 搜索 |
| 停止寻找 | `POST /social/stopFind` | `id` + `admin_password`：寻找中任务置为已停止（status 0→2） |
| 删除寻找记录 | `POST /social/delFind` | `id` + `admin_password`：物理删除 |
| 解除绑定 | `POST /social/unbind` | `id` + `admin_password`：status 1→0 |
| 删除绑定 | `POST /social/delBind` | `id` + `admin_password`：物理删除 |
| 解除黑名单 | `POST /social/delBlock` | `id` + `admin_password`：删除关系 |
| 取消关注 | `POST /social/delFollow` | `id` + `admin_password`：删除关注关系 |

### 13.2 后台通知管理（Notification，`app/admin/controller/Notification.php`）

| 接口 | 路径 | 说明 |
|------|------|------|
| 管理页 | `GET /notification/index` | 页面视图 |
| 通知列表 | `GET /notification/getList` | 分页 + 收件用户ID / 发送者ID / 类型 / 已读 搜索 |
| 标记已读 | `POST /notification/read` | `id` + `admin_password` |
| 按用户全部已读 | `POST /notification/readAll` | `uid` + `admin_password` |
| 删除通知 | `POST /notification/del` | `id` + `admin_password`：物理删除 |

### 13.3 后台表情包管理（Sticker，`app/admin/controller/Sticker.php`）

| 接口 | 路径 | 说明 |
|------|------|------|
| 管理页 | `GET /sticker/index` | 页面视图（含图片预览） |
| 表情列表 | `GET /sticker/getList` | 分页 + 上传者ID / 状态 / 文件名 搜索 |
| 上传新增 | `POST /sticker/add` | `file`（图片，≤1MB）+ `admin_password`：存为平台表情（`user_id=0`） |
| 切换状态 | `POST /sticker/setStatus` | `id` / `status`（1上架 0下架）+ `admin_password` |
| 删除 | `POST /sticker/del` | `id` + `admin_password`：物理删除（保留磁盘文件） |
