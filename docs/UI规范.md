# ACGO 后台 UI 设计规范

> 整体风格为**正式、极简、扁平**的白色系设计语言：白色/深色双主题、无装饰卡片、系统主题蓝、克制的动效。
> 所有颜色基于 **CSS 变量**（白天/黑夜两套），新增页面 / 组件必须复用变量，**禁止硬编码颜色**。
> 保证后台界面统一、现代、清爽，且完整适配暗色模式。

---

## 1. 主题体系

### 1.1 主题模式
后台支持三种外观，用户在**个人资料页**切换，偏好存 `localStorage`（key: `acgo_theme`）：

| 模式 | 说明 |
|------|------|
| `light` 白天 | 默认，白底深字 |
| `dark` 黑夜 | 深底浅字 |
| `system` 跟随系统 | 用 `prefers-color-scheme` 实时跟随 |

**切换机制**：
- `public/static/common/js/theme.js` 管理：`getPreference()` / `setPreference(mode)` / `apply()`，切换通过 **BroadcastChannel** 跨 iframe/标签页实时同步
- `<html>` 设 `data-theme="light"|"dark"`，CSS 变量据此切换
- **登录页**：本地有偏好则应用，没有则写入默认白天；登录页继承公共 layout，同样适配黑白
- **防闪烁**：`layout.html` `<head>` 顶部有同步内联脚本，CSS 前立即设 `data-theme`

### 1.2 CSS 变量（唯一颜色来源）
定义在 `layout.html`，所有颜色必须用变量，禁止写死颜色。

| 变量 | 白天 | 黑夜 | 用途 |
|------|------|------|------|
| `--primary` | `#0A84FF` | `#0A84FF` | 主题蓝 |
| `--primary-hover` | `#0071e3` | `#0071e3` | 主按钮悬停 |
| `--primary-soft` | `rgba(10,132,255,.1)` | `rgba(10,132,255,.22)` | 主题蓝浅底/选中 |
| `--primary-faint` | `rgba(10,132,255,.05)` | `rgba(10,132,255,.12)` | 更淡主题底 |
| `--success` | `#34C759` | `#34C759` | 成功 |
| `--warning` | `#FF9500` | `#FF9500` | 警告 |
| `--danger` | `#FF3B30` | `#FF3B30` | 危险 |
| `--info` | `#32ADE6` | `#32ADE6` | 信息 |
| `--neutral` | `#AEAEB2` | `#AEAEB2` | 禁用/次要 |
| `--bg` | `#ffffff` | `#1c1c1e` | 页面/卡片背景 |
| `--bg-soft` | `#f7f9fc` | `#2c2c2e` | 次要背景(表头/弹框内容) |
| `--bg-hover` | `#f3f4f6` | `#3a3a3c` | 悬停背景/输入框背景 |
| `--bg-muted` | `#f5f5f5` | `#2c2c2e` | 侧边菜单悬停 |
| `--text` | `#111827` | `#f5f5f7` | 主文字 |
| `--text-body` | `#374151` | `#e5e5ea` | 正文 |
| `--text-secondary` | `#4b5563` | `#d1d1d6` | 次要文字 |
| `--text-muted` | `#6b7280` | `#98989d` | 弱文字 |
| `--text-faint` | `#9ca3af` | `#636366` | 最弱/占位 |
| `--border` | `rgba(0,0,0,.08)` | `rgba(0,0,0,.2)` | 边框/分隔线 |
| `--border-light` | `rgba(0,0,0,.05)` | `rgba(0,0,0,.12)` | 更淡边框 |
| `--border-strong` | `#e8e8e8` | `#3a3a3c` | 强边框 |

> 黑夜下 `--border` 用**半透明黑** `rgba(0,0,0,.2)`（非白色），让弹框边框更暗、更含蓄。

---

## 2. 色彩规范

### 2.1 页面背景
| 用途 | 变量 | 白天 | 黑夜 |
|------|------|------|------|
| 页面/内容区背景 | `var(--bg)` | `#fff` | `#1c1c1e` |
| 侧边栏背景 | `var(--bg)` | `#fff` | `#1c1c1e` |
| 顶部栏背景 | `var(--bg)` | `#fff` | `#1c1c1e` |

> 设计原则：卡片**不设圆角、不设阴影、不设边框**，与背景融为一体，靠间距与留白区分层次。

### 2.2 文字层级
| 层级 | 变量 | 用途 |
|------|------|------|
| 主文字 | `var(--text)` | 标题、强调、侧边菜单项、弹框标题 |
| 正文 | `var(--text-body)` | 正文、表格文字 |
| 次要文字 | `var(--text-secondary)` | 侧边栏分组标题、次要信息 |
| 弱文字 | `var(--text-muted)` | 描述、提示 |
| 禁用/占位 | `var(--text-faint)` | placeholder、图标 |

---

## 3. 字体规范

| 层级 | 字号 | 字重 | 用途 |
|------|------|------|------|
| `.text-title` | 16px | 600 | 页面标题 |
| `.text-body` | 14px | 400 | 正文 |
| `.text-secondary` | 13px | 400 | 次要信息 |
| `.text-caption` | 12px | 400 | 辅助说明 |

**统一字体栈**：
```css
font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "PingFang SC",
             "Helvetica Neue", "Microsoft YaHei", sans-serif;
```
- 行高：正文 `1.5`，按钮 `1`，表格 `1.4`

---

## 4. 按钮规范

按钮分为 **表单内按钮** 与 **表单外按钮** 两大类。

### 4.1 尺寸体系（iOS 风格）
```css
.layui-btn { border-radius: 8px; border: none; font-weight: 500; overflow: hidden; }
.layui-btn::after { /* 顶部高光渐变 */ }
.layui-btn:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
```

| 档位 | 类名 | 高度 | 内边距 | 字号 | 圆角 |
|------|------|------|--------|------|------|
| 大 | `.lg` | 48px | `0 28px` | 16px | 12px |
| 中 | `.xl` | 44px | `0 24px` | 15px | 11px |
| 标准 | 默认/`.md` | 38px | `0 20px` | 14px | 10px |
| 小 | `.sm` | 32px | `0 14px` | 13px | 8px |
| 最小 | `.xs` | 26px | `0 11px` | 12px | 7px |

**语义色**：`.layui-btn`/`.normal`(primary)、`.green`(success)、`.warm`(warning)、`.danger`(danger)、`.cyan`(info)、`.dark`、`.gray`(neutral)。`.layui-btn-primary` 用 `var(--bg-hover)` 底 + `var(--text-body)` 字 + `var(--border)` 边框。

### 4.2 表单外按钮（页面/表格工具栏按钮）
**统一 `.page-btn`：主题蓝背景 + 白色文字 + 蓝色边框**（新增/刷新/人工充值等）。
```css
.page-btn { background: var(--primary) !important; color: #fff !important; border: 1px solid var(--primary) !important; }
.page-btn:hover { background: var(--primary-hover) !important; }
```
- 表单外按钮**默认不显示图标**

### 4.3 搜索按钮（特殊）
只保留搜索图标、不含文字，固定方块与输入框对齐：
```html
<button class="layui-btn search-btn" lay-submit lay-filter="search" title="搜索"><i class="fas fa-search"></i></button>
```
```css
.search-btn { width: 38px; padding: 0 !important; display: inline-flex; align-items: center; justify-content: center; }
```

---

## 5. 容器 / 卡片规范

**核心原则：所有卡片容器不设圆角、不设阴影、不设边框，背景用 `var(--bg)`**。
```css
.page-search, .search-bar { background: var(--bg); padding: 15px; margin-bottom: 15px; border: none; border-radius: 0; box-shadow: none; }
.page-content { background: var(--bg); padding: 18px; border: none; border-radius: 0; box-shadow: none; }
```
- **层级**：`.page-search` z-index 20、`.page-content` z-index 10，确保搜索区下拉不被遮挡
- **类名统一**：列表页一律用 `.page-content`（废弃 `.content-box`/`.setting-card` 等私有类）
- `.page-title` 已废弃，不使用

---

## 6. 表格规范

### 6.1 表格样式（无圆角，固定行高 48px）
```css
.layui-table-view { border-radius: 0; border: 1px solid var(--border-light); }
.layui-table tr, .layui-table td { color: var(--text-body); background: var(--bg); }
.layui-table th { background: var(--bg-soft) !important; color: var(--text-body) !important; height: 48px; }
.layui-table-view .layui-table-cell { height: 48px; line-height: 48px; padding: 0 15px; } /* 表头+数据行 */
.layui-table[lay-even] tr:nth-child(even) { background: var(--bg-soft) !important; }
```
- **表格全局边框**：td/th/header/view/tool/page 等全部 `border-color: var(--border)`；`.layui-table-view:after { background-color: var(--bg) }`
- **固定列允许溢出**（`.layui-table-fixed` `overflow: visible`），避免操作列下拉被裁剪

### 6.2 表格工具栏（筛选/打印/导出）
**所有运营/业务列表必须启用**：
```js
table.render({ ..., toolbar: '#xxxToolbar', defaultToolbar: ['filter', 'print', 'exports'], ... });
```
- **`defaultToolbar` 必须配合 `toolbar` 一起配置**，否则不渲染
- 导出含 templet 列时需自定义导出（`LAY_TABLE_EXPORT` 事件，输出纯文本）
- 工具栏图标按钮：`color: var(--text-body)`、`border-color: var(--border)`、**圆角 5px**，hover 变主题蓝

### 6.3 表格排序
- 列加 `sort: true` + `autoSort: false` + `initSort`，点击表头把 `field`/`order` 传给后端，后端**白名单校验**
- **排序箭头**：未激活恢复 layui 默认（`#b2b2b2` 灰），**激活**主题蓝（`var(--primary)`）。layui 用 `border-color` 画箭头，覆盖 `border-bottom-color`（asc）/`border-top-color`（desc）

### 6.4 分页
- 当前页高亮主题蓝：`.layui-laypage-curr .layui-laypage-em { background: var(--primary) }`
- 跳转"确定"按钮 `.layui-laypage-btn`：`var(--bg-soft)` 底 + `var(--text-body)` + `var(--border)`，hover 主题蓝

### 6.5 表格操作列
操作列宽 **90px**，单省略按钮（`.op-btn`）。`.op-btn` 背景 `var(--bg-hover)`，**图标白色**（`.op-btn i { color: #fff }`），+ layer 操作弹窗。

### 6.6 状态徽章（胶囊，纯色）
```css
.badge { display:inline-flex; align-items:center; padding:3px 11px; border-radius:999px; font-size:12px; color:#fff; }
.badge-green { background: var(--success); }
.badge-red   { background: var(--danger); }
.badge-blue  { background: var(--primary); }
.badge-cyan  { background: var(--info); }
.badge-gray  { background: var(--neutral); }
```

---

## 7. 表单规范

```css
.layui-input, .layui-textarea, .layui-select, select.layui-input {
    background-color: var(--bg-hover) !important;  /* 输入框用 bg-hover */
    color: var(--text) !important;
    border-color: var(--border) !important;
    border-radius: 8px;
}
.layui-input:focus { border-color: var(--primary) !important; box-shadow: 0 0 0 3px var(--primary-soft); }
.layui-input::placeholder { color: var(--text-faint); }
```
- **layui 表单 select 组件**（`.layui-form-select .layui-select-title`/input）：`var(--bg-hover)` 背景 + **圆角 10px**；下拉 `dl` 用 `var(--bg-soft)`，`dd` hover 主题蓝、选中 `layui-this` 主题蓝
- **原生 `<select>`**：`appearance: none` + 自定义箭头（`linear-gradient` 三角形用 `var(--text-muted)`），背景 `var(--bg-hover)`
- **`.layui-layer-prompt` 输入框**：`var(--bg-hover)` 背景 + 10px 圆角 + `var(--border)` 边框

---

## 8. 弹框规范（layer）

**弹框圆角统一 10px**，`.layui-layer` 用 `overflow: hidden` + `background-color: var(--bg)`：
```css
.layui-layer { border-radius: 10px; overflow: hidden; background-color: var(--bg); border: 1px solid var(--border); }
.layui-layer-title { background: var(--bg-soft); color: var(--text); font-weight: 600; border-radius: 10px; border-bottom: 1px solid var(--border); }
.layui-layer-page .layui-layer-content { border-radius: 10px; }
```
- **背景/文字**：`.layui-layer-content/.layui-layer-page` 用 `var(--bg-soft)` + `var(--text-body)`（与 `.layui-layer` 的 `var(--bg)` 区分）
- **弹框内表单元素**：`.layui-layer-page .layui-form .layui-input/.layui-textarea/.layui-select` 用 `var(--bg-hover)` + `var(--text)`
- **关闭按钮**：`.layui-layer-close` 用 `var(--text-faint)`，hover `var(--text)`；`.layui-layer-photos` 关闭按钮隐藏
- **底部按钮**：
  - `.layui-layer-btn` 背景 `var(--bg-soft)`，顶部分隔线 `var(--border)`
  - `.layui-layer-btn0`（确定）：`var(--primary)` 蓝底白字
  - `.layui-layer-btn1`（取消）：`var(--bg-soft)` 底 + `var(--text-body)`，hover 主题蓝
- **layer.msg 轻提示（toast）**：
  - 背景 `var(--bg-soft)`、边框 `var(--border)`、文字 `var(--text)`
  - 图标 `ico1/2/3/4` 分别用 success/danger/warning/primary 纯色
- 弹框宽度：表单类 480px、操作弹窗 280px、人工充值 600px
- 操作弹窗顶部显示 `.op-layer-target` 操作对象提示

---

## 9. 侧边菜单规范

白色极简，左侧导航固定宽 **220px**，带右侧分隔线 `var(--border-strong)`。

- **顶部 logo**：`.side-logo` 显示 `{$app_name}`（16px/700/`var(--text)`），仅 PC 显示（移动端隐藏，顶部栏已显示 logo 文字）
- **顶级分组**（有子菜单）：`.nav-group-title` 灰色小标题（13px/600/`var(--text-secondary)`，不可点击、不可折叠）
- **二级菜单**：平铺、带图标、缩进，高 36px、13px
- **三级及以上**：可折叠，带箭头（`layui-icon-right`，展开旋转 90°）
- **菜单项**：`.side-link` 文字 `var(--text)`、高 40px；hover 背景 `var(--bg-muted)`
- **选中态**：文字 `var(--primary)` + 背景 `var(--primary-soft)`，加粗

```css
.side-menu .side-link { color: var(--text); height: 40px; border-radius: 8px; }
.side-menu .side-link:hover { background: var(--bg-muted); }
.side-menu .side-item.active > .side-link,
.side-menu .side-sub li.active > .side-link { color: var(--primary); background: var(--primary-soft); font-weight: 600; }
```

### 侧边栏底部
- 个人资料卡片 `.profile-link`：图标头像 + 昵称 + 更多，点击进 `/profile/index`
- 退出登录 `.logout-link`：hover 变 `var(--danger)`

---

## 10. 顶部栏 / 移动端抽屉

- **PC 端**：顶部栏隐藏（`display:none`），侧边栏与内容区 `top:0`
- **移动端（≤768px）**：
  - 顶部栏恢复，仅 logo 文字（无图标）
  - 侧边栏变**抽屉式**：`transform: translateX(-100%)`，展开 `translateX(0)`，`transition: transform .3s ease` + `will-change`（GPU 加速）
  - 展开时显示**遮罩 `.side-mask`**（fixed、top 56px、半透明黑 `rgba(0,0,0,.45)`、z-index 998 低于侧边栏），点击遮罩折叠菜单
  - 移动端点击菜单跳转：先收起抽屉，`setTimeout(320ms)` 等动画完成后再加载 iframe

```css
.side-mask { display:none; position:fixed; top:56px; left:0; right:0; bottom:0; background:rgba(0,0,0,.45); z-index:998; }
.side-mask.show { display:block; }
```

---

## 11. iframe 页面切换动画

切换标签/打开页面时：
1. 显示加载遮罩 `.frame-loading`（转圈，主题蓝）
2. iframe `onload`（内容**加载完成**）后：隐藏遮罩 + 触发**淡入**动画（`frameFadeIn`：`opacity 0→1`，`.4s ease`）

```css
@keyframes frameFadeIn { from { opacity: 0; } to { opacity: 1; } }
.frame-wrap iframe.slide-in { animation: frameFadeIn .4s ease; }
```

---

## 12. 登录页规范

登录页**继承公共 layout**（`{extend name="layout"}`），复用主题变量与黑白适配：

| 元素 | 白天 | 黑夜（`[data-theme="dark"]`） |
|------|------|------|
| 背景渐变 | 彩色 `#667eea→#f093fb` | 深色 `#1c1c1e→#3a3a3c` |
| 背景光斑 | `opacity .6` | `opacity .15` |
| 玻璃卡片 | 白色半透明 blur | 深色半透明 `rgba(28,28,30,.72)` |
| 输入框 | 白色毛玻璃 | 半透明白 + 主题蓝聚焦边框 |
| 登录按钮 | 白色毛玻璃 | 主题蓝（hover 加深） |

- **title 用 `{$app_name}`**（控制器从 config 表读取），而非写死文字
- 主题初始化：无偏好则写入默认白天

---

## 13. 图标规范

- 统一 **Font Awesome**：`<i class="fas fa-xxx"></i>`
- 尺寸：按钮 12-16px，列表 14px，侧边菜单 14-15px
- **表单外按钮默认不显示图标**（搜索按钮除外，仅搜索图标）

---

## 14. 动效规范

| 场景 | 动效 |
|------|------|
| 按钮 hover/按下 | `transition: all .2s ease` |
| 侧边菜单 hover | `transition: all .15s ease` |
| 箭头旋转 | `transform: rotate(90deg) .2s ease` |
| 抽屉/遮罩 | `transition: transform .3s ease` |
| iframe 切换 | 淡入 `.4s ease` |

原则：时长 `0.15 ~ 0.4s`，用 `ease`，克制动效。

---

## 15. 文件结构

| 文件 | 职责 |
|------|------|
| `app/admin/view/layout.html` | 主题变量 + 全局规范样式，子页面/登录页继承 |
| `app/admin/view/index/index.html` | 主框架（侧边栏/顶部/标签页/iframe/移动端抽屉+遮罩） |
| `public/static/common/js/theme.js` | 主题管理（localStorage + BroadcastChannel 同步） |
| `app/admin/view/profile/index.html` | 个人资料（主题三选 + 重置密码） |
| `app/admin/view/login/index.html` | 登录页（继承 layout，黑白适配） |
| `app/admin/controller/*.php` | 业务控制器（列表排序白名单） |

> **新增页面时**：`{extend name="layout" /}` → `{block name="title"}` → 搜索区 `.page-search` + 内容区 `.page-content`（统一类名）→ 操作列折叠按钮 + layer 弹窗 → 表单外按钮 `.page-btn` → 列表启用排序 + 筛选/打印/导出工具栏 → **所有颜色用 CSS 变量，禁硬编码**。
