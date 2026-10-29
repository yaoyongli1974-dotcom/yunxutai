# 静思园小程序 JSON API（`/api/v1`）

面向微信小程序（及任何 JSON 客户端）的只读/轻交互接口层。它复用 web 端同一套 `users` / `memorials` / `tributes` / `messages` 数据与 `Policy` 权限策略，不引入第二套业务规则。

> 状态：接口层与本文档并行开发。文中的请求/响应字段是联调契约示例；若服务端实现有差异，**以实际返回为准**，并在合并前同步更新本文与 `miniprogram/utils/api.js`。

## 目录

- [1. 概览](#1-概览)
- [2. 鉴权](#2-鉴权)
- [3. 错误码](#3-错误码)
- [4. 通用约定](#4-通用约定)
- [5. 鉴权与账号](#5-鉴权与账号)
- [6. 纪念馆（访客/成员）](#6-纪念馆访客成员)
- [7. 祭奠、留言与申领](#7-祭奠留言与申领)
- [8. 馆内管理](#8-馆内管理)
- [9. 频率限制](#9-频率限制)
- [10. CORS 说明](#10-cors-说明)
- [11. 客户端骨架](#11-客户端骨架)
- [12. 上线检查清单](#12-上线检查清单)
- [13. 当前限制与后续扩展](#13-当前限制与后续扩展)

## 1. 概览

| 项 | 值 |
|---|---|
| Base URL | `https://<你的域名>/api/v1` |
| 本地调试 | `http://127.0.0.1:8099/api/v1`（`php -S 127.0.0.1:8099 -t public`） |
| 编码 | UTF-8 JSON，请求体 `Content-Type: application/json` |
| 时间 | 服务端按 `Asia/Shanghai` 输出日期时间字符串，客户端直接展示 |
| 认证 | `Authorization: Bearer <token>`（除公开端点外） |
| 版本 | 路径内版本号 `v1`；破坏性变更另开 `/api/v2`，不在 v1 内改字段语义 |

### 响应包络

成功（HTTP 2xx）：

```json
{
  "data": {
    "id": 12
  }
}
```

失败（HTTP 4xx/5xx）：

```json
{
  "error": {
    "code": "validation_failed",
    "message": "留言需在 2–1000 字之间"
  }
}
```

约定：

- 客户端只读 `data`；分页、列表等结构都包在 `data` 内。
- 错误 `message` 是可直接展示给用户的中文文案；`code` 用于程序分支，**不要用文案做判断**。
- 不返回 HTML、不返回 PHP 异常堆栈。`APP_DEBUG=true` 时也不应把内部路径/ SQL 暴露到 API。

请求示例：

```bash
curl -s http://127.0.0.1:8099/api/v1/memorials?page=1&per_page=10
```

## 2. 鉴权

### 2.1 Token 获取

两种方式，返回同一结构：

1. **邮箱密码**：`POST /auth/token`，适合 demo、测试与「邮箱密码模式」账号。
2. **微信登录**：`POST /auth/wechat`，用 `wx.login` 的 `code` 换 token；**需服务端配置 `WX_APPID` / `WX_SECRET`**，未配置时返回 `503 wechat_not_configured`。

```json
{
  "data": {
    "token": "eyJ...（示例，实际为不透明随机串）",
    "expires_at": "2026-11-28 10:00:00",
    "user": {
      "id": 2,
      "nickname": "张明远家属",
      "email": "demo@example.com"
    }
  }
}
```

- `token`：后续放进 `Authorization: Bearer <token>`。客户端存 storage（骨架用 `jidian_token`）。
- `expires_at`：服务端给出的过期时间；客户端不必解析，统一以 `401` 为准。
- `user.nickname` 对应 web 端 `users.display_name`，不是微信昵称。

### 2.2 携带方式

```http
GET /api/v1/me HTTP/1.1
Host: example.com
Authorization: Bearer <token>
```

- token 与 web 端 PHP session **相互独立**：浏览器登录不会自动产生 token，反之亦然；两者指向同一 `users` 记录。
- token 失效、被撤销、账号被禁用统一返回 `401 unauthorized`。

### 2.3 401 处理（客户端必须实现）

1. 清除本地 token 与用户缓存；
2. 跳转登录页（骨架 `app.js` 通过 `request.onUnauthorized` 注册）；
3. 若已配置微信登录，可先 `wx.checkSession` 判断微信会话，再 `wx.login` 静默换新 token；否则让用户手动登录。

### 2.4 登录态校验与注销

- `GET /me` 校验 token 并返回用户与成员身份；
- `POST /auth/logout` 注销当前 token；
- `wx.checkSession` 只反映**微信会话**状态，不代表本平台 token 有效，二者要分开处理。

### 2.5 安全约束

- **AppSecret 只能放在服务端**（`WX_SECRET` 环境变量）。客户端只接触 `code` 与 `token`。
- `code` 一次性、约 5 分钟有效，换 token 必须由服务端调用 `code2session` 完成。
- 不要把 token 写进 URL、日志、埋点或分享参数。

## 3. 错误码

| HTTP | `code` | 含义 | 客户端处理建议 |
|---|---|---|---|
| 401 | `unauthorized` | 未登录、token 失效/被撤销、邮箱或密码错误 | 清 token，跳登录页；登录接口本身则提示「邮箱或密码错误」 |
| 403 | `forbidden` | 已登录但无权限（如非成员祭奠、留言簿关闭、重复申领） | 提示文案，不重试 |
| 404 | `not_found` | 资源不存在，**或无权查看**（私密/暂停/未核验纪念馆、未发布文章） | 统一提示「无权限访问或内容不存在」，不区分原因 |
| 422 | `validation_failed` | 参数校验失败（必填缺失、长度越界、枚举非法） | 直接展示 `message`，按字段提示 |
| 429 | `rate_limited` | 触发频率限制 | 提示稍后再试，禁用按钮一段时间 |
| 500 | `internal_error` | 服务端异常 | 通用失败提示 + 允许重试 |
| 503 | `wechat_not_configured` | 微信登录未配置（仅 `/auth/wechat`） | 提示「暂未配置」，不自动重试 |

客户端本地错误（服务端不会返回）：

| `code` | 场景 |
|---|---|
| `network_error` | `wx.request` 失败、超时、DNS/证书错误 |

> **权限铁律**：私密、暂停、未通过身份核验的纪念馆，对无权限者一律 `404`，不得用 `403` 暴露「存在但不可见」。客户端对 404 统一按「无权限访问」处理。

## 4. 通用约定

- **分页**：`page` 从 1 开始，`per_page` 默认 20。列表 `data` 形如：

  ```json
  {
    "data": {
      "items": [],
      "page": 1,
      "per_page": 20,
      "total": 0,
      "total_pages": 0
    }
  }
  ```

- **空列表**返回 `items: []` 与 HTTP 200，不用 404。
- **媒体地址**：`media_url` 为签名 URL，**有效期 900 秒**；过期后重新请求详情页获取，不要落库、不要长期缓存。
- **匿名**：祭奠支持 `anonymous`，服务端对访客隐藏提交者身份；留言不支持匿名（与 web 端一致）。
- **审核**：留言/附言可能进入 `pending`，响应中会带 `status`，客户端提示「待馆主确认后展示」而不是当作失败。
- **幂等**：`POST /auth/logout`、`POST /memorials/{slug}/claim` 重复调用不应产生重复记录；`POST` 祭奠/留言均为一次性行为，不做幂等键。
- **字段命名**：全部 `snake_case`，与数据库/ web 表单保持一致。

## 5. 鉴权与账号

### 5.1 `POST /auth/token` — 邮箱密码登录

- 鉴权：无需 token
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 登录邮箱，trim 后比对 |
| `password` | string | 是 | 明文仅用于本次校验，不落库、不写日志 |

请求：

```json
{ "email": "demo@example.com", "password": "Demo@123456" }
```

响应 `200`：

```json
{
  "data": {
    "token": "t_9f2c...",
    "expires_at": "2026-11-28 10:00:00",
    "user": { "id": 2, "nickname": "张明远家属", "email": "demo@example.com" }
  }
}
```

错误：

- `401 unauthorized`：邮箱或密码错误（不区分「账号不存在」，防枚举）；
- `422 validation_failed`：字段缺失；
- `429 rate_limited`：失败次数过多。

权限：公开端点。
来源：web `POST /login`（`AuthController`），沿用同一密码策略与禁用账号判断。demo 账号见根 `README.md`。

### 5.2 `POST /auth/wechat` — 微信 code 换 token

- 鉴权：无需 token
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `code` | string | 是 | `wx.login` 返回的临时 code，一次性 |

响应：同 [`POST /auth/token`](#51-post-authtoken--邮箱密码登录)。

错误：

- `503 wechat_not_configured`：服务端未配置 `WX_APPID` / `WX_SECRET`（**当前默认状态**）；
- `401 unauthorized`：code 无效/已使用/过期，或 `code2session` 返回错误；
- `429 rate_limited`：换取过于频繁。

权限：公开端点。
来源：新增能力，web 端无对应页面；服务端 `code2session` → `openid` → 绑定/创建 `users` 记录。

> **现状：code2session 待配置。** 未设置 `WX_APPID`/`WX_SECRET` 时该端点固定返回 503。小程序骨架通过 `config.js` 的 `WECHAT_LOGIN_ENABLED=false` 让登录页按钮提示「暂未配置」，避免无意义请求。上线前必须完成微信开放平台配置与真机联调。

### 5.3 `POST /auth/logout` — 注销

- 鉴权：需要
- 参数：无
- 响应 `200`：

```json
{ "data": { "ok": true } }
```

- `401`：token 已无效（客户端仍应清空本地会话）。
- 权限：当前 token 本人。
- 来源：web `POST /logout`。

### 5.4 `GET /me` — 当前用户与成员身份

- 鉴权：需要
- 响应：

```json
{
  "data": {
    "user": { "id": 2, "nickname": "张明远家属", "email": "demo@example.com" },
    "memberships": [
      {
        "memorial_id": 1,
        "slug": "zhang-mingyuan",
        "title": "张明远纪念馆",
        "role": "owner",
        "status": "active"
      }
    ]
  }
}
```

- `role`：`owner` / `editor` / `viewer`；只返回 `status=active` 的成员关系。
- `401`：token 失效（客户端据此重新登录）。
- 权限：本人。
- 来源：web 导航栏用户菜单 + `Policy::role()`。

## 6. 纪念馆（访客/成员）

### 6.1 `GET /memorials` — 公开纪念馆列表

- 鉴权：可选（带 token 不改变结果）
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `page` | int | 否 | 默认 1 |
| `per_page` | int | 否 | 默认 20，上限以服务端为准 |

响应：

```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "slug": "zhang-mingyuan",
        "title": "张明远纪念馆",
        "summary": "一位普通的中学语文教师。",
        "cover_url": "https://example.com/media/12?expires=...&sig=...",
        "published_at": "2026-10-01 09:00:00"
      }
    ],
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  }
}
```

- 只返回 `visibility=public`、`status=active`、`verification_status=verified` 的纪念馆。
- `unlisted` / `private` / `suspended` 不进入列表，也不进入 sitemap（与 web 首页、`/sitemap.xml` 规则一致）。
- 字段以实际返回为准，客户端至少依赖 `id` / `slug` / `title`。
- 来源：web 首页 `GET /`（`HomeController`）。

### 6.2 `GET /memorials/{slug}` — 纪念馆详情

- 鉴权：可选；私密馆需成员/管理员 token
- 参数：路径 `slug`
- 响应 `data` 固定包含五块：`summary`、`profile`、`life`、`articles`、`media`：

```json
{
  "data": {
    "summary": {
      "slug": "zhang-mingyuan",
      "title": "张明远纪念馆",
      "summary": "一位普通的中学语文教师。",
      "visibility": "public",
      "allow_guestbook": true,
      "verified": true,
      "cover_url": "https://example.com/media/12?expires=...&sig=..."
    },
    "profile": {
      "full_name": "张明远",
      "gender": "male",
      "birth_date": "1948-03-02",
      "death_date": "2024-11-18",
      "birth_place": "江苏苏州",
      "death_place": "江苏苏州",
      "bio": "……"
    },
    "life": [
      { "id": 3, "event_date": "1968-09-01", "year_label": "1968", "title": "下乡插队", "description": "……", "location": "江苏盐城" }
    ],
    "articles": [
      { "id": 7, "title": "父亲教书的那几年", "author_name": "张小雨", "published_at": "2026-09-10 20:00:00" }
    ],
    "media": [
      {
        "id": 12,
        "kind": "photo",
        "caption": "1985 年，学校操场",
        "mime": "image/jpeg",
        "media_url": "https://example.com/media/12?expires=1760000000&sig=...",
        "expires_in": 900
      }
    ]
  }
}
```

- `media_url` 为**签名地址，有效期 900 秒**；`kind`：`photo` / `video` / `audio` / `document`。
- `articles` 只含已发布文章；`life` 按时间/排序返回。
- `media` 只含当前身份可见的媒体：`visibility=members` 的资源需要成员/管理员 token，访客请求会被过滤（不是 403）。
- 权限：
  - `public` / `unlisted`：匿名可读（`unlisted` 仅凭链接访问，不进列表）；
  - `private` / `suspended` / 未核验：**404**（成员、管理员除外）；
  - 成员与管理员可读取私密馆及成员可见媒体。
- 来源：web `GET /m/{slug}`（`MemorialController@show`）+ `/media/{id}` 鉴权输出。

### 6.3 `GET /memorials/{slug}/articles/{id}` — 文章详情

- 鉴权：可选
- 参数：路径 `slug`、`id`
- 响应：

```json
{
  "data": {
    "id": 7,
    "title": "父亲教书的那几年",
    "content": "正文（纯文本或受限 HTML，按服务端输出处理）",
    "author_name": "张小雨",
    "published_at": "2026-09-10 20:00:00"
  }
}
```

- 仅 `status=published` 对访客可见；`draft` / `pending` / `rejected` / `hidden` 对访客与普通成员一律 **404**（馆主/协作者在 web 后台预览，API 是否开放以实现为准）。
- 权限：同纪念馆可见性；私密馆文章同样对外 404。
- 来源：web `GET /m/{slug}/articles/{id}`（`ArticleController`）。

### 6.4 `GET /media/{id}?exp=&sig=` — 签名媒体文件

- 鉴权：**签名 URL**（无需 `Authorization` 头——小程序 `<image>` / `<video>` 标签无法自定义请求头）
- 签名获取：详情/媒体列表响应中的 `media_url` 字段即是现成的本端点地址（`/api/v1/media/{id}?exp=...&sig=...`），直接使用；**有效期 900 秒**，过期后重新请求详情获取新 `media_url`。
- 校验：`sig = hash_hmac('sha256', "media:{id}:{exp}", APP_KEY)`，`exp` 须为未来时间戳；任一不满足、媒体不存在或非 `active` 一律 **404**，不区分原因。
- 安全要点：签名 URL 在有效期内等效于临时访问凭证，**不要落库、不要外发**；`media_url` 只对成员可见媒体应由成员授权后生成。
- 来源：web `GET /media/{id}`（`MediaController::show`）+ `App\Core\MediaSign` 签名层。

## 7. 祭奠、留言与申领

### 7.1 `POST /memorials/{slug}/tribute` — 祭奠

- 鉴权：需要（与 web 端「祭奠/留言需登录」一致）
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `type` | string | 是 | `flower`（献花）/ `candle`（点烛）/ `incense`（上香）/ `bow`（鞠躬） |
| `appendix` | string | 否 | 附言，≤200 字；对应 web 表单的 `note` / 数据表 `tributes.note` |
| `anonymous` | bool | 否 | 默认 `false`；为 `true` 时对访客隐藏提交者 |

请求：

```json
{ "type": "flower", "appendix": "爸，今年桂花又开了。", "anonymous": false }
```

响应 `200`：

```json
{ "data": { "id": 301, "type": "flower", "status": "visible" } }
```

- `status`：`visible`（直接展示）或 `pending`（附言非空且该馆开启留言审核时，待馆主确认后展示）。
- 错误：`401`（未登录）、`403`（无权在该馆祭奠）、`404`（馆不可见）、`422`（`type` 非法或附言超长）、`429`（过于频繁）。
- 权限：`Policy::canPost()`：公开/不公开馆的登录用户可祭奠；私密馆仅成员；暂停馆不可。
- 来源：web `POST /m/{slug}/tribute`（`TributeController@store`），写 `tributes` 表。

### 7.2 `GET /memorials/{slug}/messages` — 留言列表

- 鉴权：可选（发表留言需登录）
- 参数：`page`、`per_page`
- 响应：

```json
{
  "data": {
    "items": [
      { "id": 88, "author_name": "李老师", "content": "永远记得您的板书。", "created_at": "2026-10-20 21:10:00" }
    ],
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  }
}
```

- 只返回 `status=visible` 的留言；`pending` / `hidden` 不返回（审核在 web 馆内管理完成）。
- 留言簿关闭（`allow_guestbook=0`）时返回空列表或 `403`（以服务端实现为准）。
- 权限：同纪念馆可见性；私密馆仅成员可读。
- 来源：web `GET /m/{slug}#messages`。

### 7.3 `POST /memorials/{slug}/message` — 发表留言

- 鉴权：需要
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `body` | string | 是 | 2–1000 字（`content` 作为兼容别名同样接受） |
| `anonymous` | bool | 否 | 默认 `false` |

请求：

```json
{ "body": "师恩难忘，愿您安息。", "anonymous": false }
```

响应 `200`（直接可见）或 `202`（待审）：

```json
{ "data": { "message_id": 89, "status": "pending" } }
```

- `status=pending` 表示已提交待馆主确认，不是失败；`visible` 表示立即可见。
- 错误：`401`、`403`（留言簿关闭或无发言权限）、`404`、`422`（长度不符）、`429`。
- 来源：web `POST /m/{slug}/messages`（`TributeController::message`），写 `messages` 表。

### 7.4 `POST /memorials/{slug}/claim` — 申领纪念馆

- 鉴权：需要
- 参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `relationship` | string | 是 | 与逝者关系，如「女儿」「学生」 |
| `reason` | string | 是 | 申领理由与可核验线索；落库为 `claims.statement` |

请求：

```json
{
  "relationship": "女儿",
  "reason": "我是逝者的女儿，可提供户口本与死亡证明，希望接手维护纪念馆。"
}
```

响应 `200`：

```json
{ "data": { "id": 5, "status": "open" } }
```

- 平台线下核验；`status`：`open` / `approved` / `rejected`。MVP 通过后授予协作者权限，不直接转移馆主（与 web 端一致）。
- 错误：`401`、`403`（已是成员/馆主、重复申领）、`404`（馆不可见）、`422`（必填缺失）、`429`（每日次数限制）。
- 来源：web `GET|POST /m/{slug}/claim`（`MemorialController@claimStore`）。

## 8. 馆内管理

### 8.1 `GET /manage/memorials` — 我的纪念馆

- 鉴权：需要
- 参数：`page`、`per_page`
- 响应：

```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "slug": "zhang-mingyuan",
        "title": "张明远纪念馆",
        "role": "owner",
        "visibility": "public",
        "status": "active",
        "verification_status": "verified",
        "updated_at": "2026-10-20 18:00:00"
      }
    ],
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  }
}
```

- 返回当前用户创建或作为 `active` 成员的纪念馆，含 `owner` / `editor` / `viewer` 全部角色（与 web `/manage/memorials` 一致）。
- 权限：登录用户；不返回他人私密馆。
- 来源：web `GET /manage/memorials`。

### 8.2 `POST /manage/memorials` — 创建纪念馆

- 鉴权：需要
- 参数（与 web 建馆表单同名校验）：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `full_name` | string | 是 | 逝者姓名，≤120 |
| `gender` | string | 是 | `male` / `female` / `unknown` |
| `declaration` | bool/int | 是 | 创建声明确认；缺失返回 422 |
| `birth_date` / `death_date` | date | 否 | `YYYY-MM-DD`，逝世不得早于出生 |
| `birth_place` / `death_place` | string | 否 | ≤190 |
| `bio` | string | 否 | 生平简介，≤5000 |
| `title` | string | 否 | 纪念馆名称，≤190 |
| `summary` | string | 否 | 引言，≤500 |
| `slug` | string | 否 | 小写字母/数字/连字符，3–60；留空由服务端生成 |
| `visibility` | string | 是 | 表单需提交；**新馆实际强制为 `private` 草稿**，核验通过后才可公开 |

响应 `201`（或 `200`）：

```json
{ "data": { "id": 1, "slug": "zhang-mingyuan", "status": "draft", "visibility": "private", "verification_status": "none" } }
```

- 必须走身份核验才能公开：这是产品硬约束，客户端不要在 UI 上提供「创建即公开」。
- 错误：`401`、`422 validation_failed`（字段错误，`message` 指出原因）、`429`。
- 来源：web `GET|POST /manage/memorials/create`（`Manage\MemorialController@store`、`MemorialService::validate`）。

## 9. 频率限制

同名端点的阈值与 web 端保持一致（服务端实现如调整，以实际返回的 `429` 为准）：

| 端点 | 限制 | 范围 |
|---|---|---|
| `POST /auth/token` | 失败次数限流（按 IP / 邮箱） | 阈值以服务端为准 |
| `POST /auth/wechat` | 按 IP / code 限流 | 阈值以服务端为准 |
| `POST /memorials/{slug}/tribute` | 12 次 / 10 分钟 | 用户 + 纪念馆 |
| `POST /memorials/{slug}/messages` | 6 次 / 10 分钟 | 用户 + 纪念馆 |
| `POST /memorials/{slug}/claim` | 3 次 / 24 小时 | 用户 |
| `GET` 类端点 | 一般不限流 | 如遇滥用由网关限制 |

被限流返回 `429 rate_limited`，客户端应提示「操作过于频繁，请稍后再试」并短暂禁用按钮，不要立即自动重试。

## 10. CORS 说明

- 小程序 `wx.request` **不受浏览器同源策略约束**，调用 API 不需要 CORS 头。
- 在浏览器里直接用 `fetch` / XHR 调 `/api/v1` 时报 CORS 错误是**正常现象**：服务端未对 JSON API 放开 `Access-Control-Allow-Origin`。
- 浏览器调试建议用 `curl`、Postman 或微信开发者工具；不要在线上为图方便配置 `Access-Control-Allow-Origin: *`（尤其带 `Authorization` 时）。
- 若确实需要浏览器端调试，可在受控环境按需放开固定来源，并同时处理 `OPTIONS` 预检与 `Access-Control-Allow-Headers: Authorization, Content-Type`。

## 11. 客户端骨架

`miniprogram/` 提供可直接运行的最小客户端：

| 文件 | 作用 |
|---|---|
| `config.js` | `BASE_URL`（默认 `http://127.0.0.1:8099`）、token storage key、微信登录开关 |
| `utils/request.js` | `wx.request` Promise 封装；自动带 Bearer；非 2xx/网络错误归一为 `{code,message}`；401 清 token 并回调；10s 超时 |
| `utils/auth.js` | `loginDemo` / `loginWechat` / `ensureSession` / `logout` |
| `utils/api.js` | 本文端点的一一映射 |
| `pages/login/` | 邮箱密码 + 微信登录示例页 |

使用说明见 [`miniprogram/README.md`](../miniprogram/README.md)。

## 12. 上线检查清单

- [ ] `BASE_URL` 改为 HTTPS 域名；在微信公众平台「开发管理 → 服务器域名」配置 `request` 合法域名（不能带路径/端口，必须 HTTPS 且证书有效）。
- [ ] 服务端配置 `WX_APPID` / `WX_SECRET`，`code2session` 联调通过；secret 只存在服务端环境变量，不写日志、不进仓库。
- [ ] 真机验证登录、token 过期（401）与重新登录、`wx.checkSession` 续期路径。
- [ ] 私密/暂停/未核验纪念馆的 404 行为在真机验证；确认没有通过错误码或响应差异泄露馆是否存在。
- [ ] 签名 `media_url` 900 秒有效期可接受；页面停留超时后重新拉详情，不缓存过期 URL。
- [ ] 频率限制生效并有 `429` 文案；客户端不会自动重试造成放大。
- [ ] 审计日志覆盖登录、祭奠、留言、申领、建馆等敏感动作；不记录密码、token、AppSecret、code。
- [ ] 隐私与合规：隐私政策说明微信 `openid` 的收集与用途；未成年人保护；活人被祭奠的举报入口仍可用（web 端）。
- [ ] `APP_DEBUG=false`；`500` 不返回堆栈；生产环境关闭开发者工具的「不校验合法域名」。
- [ ] 监控与告警：401/429/500 比例、`code2session` 失败率、接口耗时。

## 13. 当前限制与后续扩展

- **code2session 待配置**：未设置 `WX_APPID`/`WX_SECRET` 时 `POST /auth/wechat` 返回 `503 wechat_not_configured`；小程序登录页按钮提示「暂未配置」。
- **未覆盖的 web 能力**：媒体上传、馆内编辑/审核、举报、AI 工作室、身份核验材料提交仍只在 web 端；如需小程序化，按同一 `Policy` 规则扩展，不新增平行权限逻辑。
- **分页/字段微调**：列表信封与详情字段以服务端实现为准，联调后同步本文与 `miniprogram/utils/api.js`。
- **契约差异提示**：祭奠附言在 API 使用 `appendix`，web 表单与数据表使用 `note`；申领理由 API 使用 `reason`，数据表为 `claims.statement`。服务端负责映射，客户端只按本文传参。
