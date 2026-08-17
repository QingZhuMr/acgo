# ACGO 社区后端

类小红书风格的社区平台**后端服务**，为「耶熊」PC 客户端（Flutter）提供 REST API 与实时推送能力，同时内置完整后台管理系统。

- **技术栈**：ThinkPHP 8 · PHP 8.5 · MySQL 8 · Swoole WebSocket
- **客户端**：[acgopc](https://github.com/QingZhuMr/acgopc)（Flutter Windows/macOS 桌面端）
- **实时推送**：独立 Swoole WebSocket Server（不依赖框架）

---

## 功能特性

### 前台 API（`app/api`，供客户端调用）

| 模块 | 能力 |
|------|------|
| 用户 | 注册（图形验证码防机器人）、登录、个人资料、上传头像（自动压缩）、修改密码（强制下线）、单设备在线 |
| 广场帖子 | 热度权重随机推荐、关键词搜索、分页加载、发帖（多图压缩）、删帖 |
| 互动 | 点赞、收藏/取消收藏、我的收藏、我的动态、评论/楼中楼（聊天气泡展示）、删除评论、举报 |
| 通知 | 评论/回复实时通知（应用内 + 系统双通道）、未读数、已读/一键已读 |
| 表情包 | 上传/删除/列表（个人专属表情包） |
| 系统 | 版本更新检查、域名/WS 地址下发 |

### 后台管理（`app/admin`）

- 仪表盘：注册趋势、充值统计
- 用户管理：搜索、封禁/解封、删除、手工充值（熊爪）
- 帖子管理：列表、详情、删除帖子/评论、热度调节
- 举报处理：举报列表、审核、处理
- 系统配置：敏感词词典（在线更新）、充值配置
- 运营：管理员账号管理、操作日志、版本发布管理

### 实时推送（`ws/`）

- 基于 **Swoole WebSocket Server** 的独立常驻进程，与框架解耦，仅依赖 Swoole + PDO
- `Swoole\Table` 维护 `user_id → fd` 连接映射（多 worker 共享），支持多进程横向扩展
- 推送协议：`auth / ping / bye` + `auth_result / force_logout / update / wallet / maintenance / reply_notice`
- 离线消息兜底：业务侧写 `push_queue` 表，服务端定时轮询推送，客户端断线重连后可补齐

---

## 架构

```
┌─────────────────────────────────────────────────────────┐
│  客户端（Flutter 桌面 / H5 / 移动端）                     │
│  REST API（签名 + 设备密钥）  +  WebSocket 长连接(2346)   │
└───────┬──────────────────────────────────┬──────────────┘
        │                                  │
        ▼                                  ▼
┌───────────────────┐          ┌──────────────────────────┐
│  ThinkPHP 8 API   │          │  Swoole WebSocket Server │
│  (app/api)        │          │  (ws/server.php 独立进程) │
│  + 后台管理        │  写表    │  Swoole\Table 连接映射    │
│  (app/admin)      │ ───────► │  push_queue 轮询推送     │
└─────────┬─────────┘          └────────────┬─────────────┘
          │                                 │
          ▼                                 ▼
      ┌──────────────────────┐
      │   MySQL（acgo 库）    │
      │  user / post /       │
      │  notification /      │
      │  push_queue ...      │
      └──────────────────────┘
```

**分层设计**：

- `app/api` —— 前台 REST API（移动/桌面客户端）
- `app/admin` —— 后台管理（浏览器）
- `app/common` —— 公共服务：内容审核、图片压缩、IP 定位、消息推送、密码哈希
- `ws/` —— Swoole WebSocket 实时推送服务（独立进程）
- `sql/` —— 全量建表脚本与种子数据

---

## 安全机制

- **双层鉴权**：所有接口先验 HMAC-SHA256 签名（`API_SECRET` + 时间戳防重放 ±300s），业务接口再验设备级密钥 `X-Device-Key`
- **单设备在线**：登录绑定设备密钥，异地登录强制旧设备下线（WS `force_logout`）
- **内容安全**：发帖/评论过敏感词过滤；举报 + 后台审核闭环
- **图片安全**：上传按场景压缩（头像方形小图、帖子最大边 1080px、评论 800px），限制体积防带宽滥用
- **日志审计**：管理员操作日志 + 用户关键操作日志，可追溯

---

## 目录结构

```
acgo/
├── app/
│   ├── api/          # 前台 REST API（User/Post/Captcha/Notification/Sticker/Update）
│   ├── admin/        # 后台管理（登录/用户/帖子/举报/充值/系统/日志/版本）
│   ├── common/       # 公共服务（ContentCheck/ImageProcessor/IpLocation/Pusher/Password）
│   ├── middleware/   # 全局中间件（CORS、签名+设备鉴权 ApiAuth）
│   └── BaseController.php
├── config/           # 框架配置（数据库/路由/日志/缓存…）
├── docs/             # API 文档、安全规范、业务规范、搭建教程
├── route/            # 路由（默认控制器自动分发）
├── sql/              # 建表脚本（init.sql 及各业务表）
├── ws/               # Swoole WebSocket 服务 + 部署脚本
├── public/           # 入口 + 静态资源（上传图等）
└── extend/           # 第三方扩展
```

---

## 快速开始

### 本地开发

```bash
# 1. 安装依赖
composer install

# 2. 配置环境（数据库、API_SECRET、域名等）
cp .env.example .env   # 按需修改

# 3. 初始化数据库
mysql -u root -p < sql/init.sql   # 执行全部建表脚本

# 4. 启动 API（ThinkPHP 内置服务）
php think run

# 5. 启动 WebSocket（Windows 本地用 swoole-cli 免安装二进制）
cd ws && php server.php start
```

### 生产部署

服务器采用宝塔面板 + git 工作流：

```bash
# 服务器上拉取最新代码并生效（PHP-FPM 平滑重载 + 清缓存）
cd /www/wwwroot/acgo && bash ws/deploy.sh

# WebSocket 守护（start.sh 已注册宝塔计划任务每分钟兜底）
bash ws/start.sh
```

> 完整教程见 [docs/后端搭建教程.md](docs/后端搭建教程.md)。

---

## 相关文档

| 文档 | 说明 |
|------|------|
| [API接口文档.md](docs/API接口文档.md) | 全部前台接口定义（路径/鉴权/参数/返回） |
| [安全规范.md](docs/安全规范.md) | 签名、设备密钥、密码、内容安全约定 |
| [业务规范.md](docs/业务规范.md) | 业务逻辑约定（评论计数、收藏、充值等） |
| [后端搭建教程.md](docs/后端搭建教程.md) | 环境搭建与部署步骤 |
| [UI规范.md](docs/UI规范.md) | 客户端 UI 设计规范（双端主题/组件/滚动） |

---

## 客户端

PC 客户端仓库：[QingZhuMr/acgopc](https://github.com/QingZhuMr/acgopc)（Flutter，Windows/macOS，应用名「耶熊」）。

## License

详见 [LICENSE.txt](LICENSE.txt)。
