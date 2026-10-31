# 云叙台小程序（开发参考骨架）

这是 `/api/v1` 的最小客户端：登录、纪念馆列表、纪念馆详情（祭奠/留言）与馆主成员管理。用于说明如何对接服务端，完整接口文档见 [../docs/api.md](../docs/api.md)。

完整接口文档见 [../docs/api.md](../docs/api.md)。

## 快速运行

1. 启动后端（仓库根目录）：
   ```bash
   php -S 127.0.0.1:8099 -t public
   ```
2. 复制配置并填入自己的 AppID：
   ```bash
   cp project.config.json.example project.config.json
   ```
   在 `appid` 中填入小程序 AppID；没有 AppID 时可用开发者工具的「测试号/游客模式」。
3. 用微信开发者工具导入本目录（`miniprogram/`），勾选「详情 → 本地设置 → 不校验合法域名…」。
4. 用演示账号登录：`demo@example.com` / `Demo@123456`（`php bin/install.php --demo` 生成）。

## 配置

| 位置 | 说明 |
|---|---|
| `config.js` → `BASE_URL` | API origin，默认 `http://127.0.0.1:8099`；请求层会自动拼 `/api/v1` |
| `config.js` → `WECHAT_LOGIN_ENABLED` | 服务端配好 `WX_APPID`/`WX_SECRET` 后改为 `true`，登录页与 `ensureSession()` 才会走微信登录 |
| `project.config.json` | 本地生成，不要提交真实 AppID；`urlCheck: false` 仅用于本地调试 |

## 结构

```
miniprogram/
  app.js / app.json / app.wxss     // 小程序入口与全局样式
  config.js                        // BASE_URL、token storage key、微信登录开关
  utils/request.js                 // wx.request Promise 封装：鉴权头、错误归一、401 回调、10s 超时
  utils/auth.js                    // loginDemo / loginWechat / ensureSession / logout
  utils/api.js                     // 按端点一一对应的方法
  utils/media.js                   // 签名相对路径补 BASE_URL（不缓存）
  pages/login/                     // 登录页（邮箱密码 + 微信登录）
  pages/memorials/list             // 纪念馆列表：公开馆 + 我管理的 + 新建入口（可未登录浏览）
  pages/memorial/detail            // 纪念馆详情：生平/影像/祭奠/留言簿/文章与举报入口
  pages/article/view               // 纪念文章阅读（rich-text 渲染服务端 content_html）
  pages/manage/create              // 新建纪念馆（私密草稿）
  pages/manage/members             // 成员与邀请管理（仅馆主）
  pages/report/report              // 投诉与举报（活人被祭拜等紧急场景）
  pages/account/account            // 个人中心：用户信息/成员馆/退出登录
```

## 页面说明

| 页面 | 路径 | 说明 |
|---|---|---|
| 登录 | `pages/login/login` | 成功后 `reLaunch` 到纪念馆列表；「暂不登录」可直接浏览 |
| 纪念馆列表 | `pages/memorials/list` | `GET /memorials` 公开馆分页；登录后显示 `GET /manage/memorials`（馆主直达成员管理）与「＋新建纪念馆」；右上角进个人中心 |
| 纪念馆详情 | `pages/memorial/detail` | `GET /memorials/{slug}`；祭奠 `POST …/tribute`；留言 `GET/POST …/messages`（202 视为待审）；文章点击进阅读页；页面底部「投诉与举报」入口；馆主右下角「成员管理」 |
| 纪念文章 | `pages/article/view` | `GET /memorials/{slug}/articles/{id}`，rich-text 渲染 `content_html`；404 显示占位 |
| 新建纪念馆 | `pages/manage/create` | `POST /manage/memorials`；姓名/性别/声明必填，创建后固定私密草稿，引导走身份核验后公开 |
| 成员与邀请 | `pages/manage/members` | `GET /manage/memorials/{id}/members` + invite / role / remove / revoke；403 提示仅馆主可用 |
| 投诉与举报 | `pages/report/report` | `POST /reports`；七类举报原因（活人被祭拜/虚构逝者自动升级 urgent），422 逐字段提示、429 提示稍后再试 |
| 个人中心 | `pages/account/account` | `GET /me` 展示打码邮箱与成员馆；「退出登录」调 `POST /auth/logout` 并清本地 |

签名媒体地址只用于当次展示：列表封面与详情图片都用 `BASE_URL + media_url`，详情图片加载失败会静默重拉详情换新签名，最多 2 次，不写入 storage。

## 必须遵守的约束

- **合法域名**：`wx.request` 只能访问微信公众平台「开发管理 → 服务器域名」中配置过的 **HTTPS** 域名。`http://127.0.0.1:8099` 仅在开发者工具「不校验合法域名」时可用，真机与上线必须换成 HTTPS 域名。
- **code2session 只在服务端**：小程序只把 `wx.login` 返回的 `code` 发给 `/api/v1/auth/wechat` 换 token，**客户端不得保存或传输 AppSecret**。服务端未配置 `WX_APPID`/`WX_SECRET` 时该接口返回 `503 wechat_not_configured`。
- **token 续期**：token 过期后接口返回 `401`，`request.js` 会清 token 并触发 `app.js` 注册的回调跳回登录页；开启微信登录后可先用 `wx.checkSession` 判断微信会话，再 `wx.login` 静默重换 token（`auth.ensureSession()` 已封装）。
- **404 即无权限**：私密/暂停纪念馆、未发布内容、越权资源在服务端统一返回 404，客户端不要再区分「不存在」与「无权限」，统一提示「无权限访问或内容不存在」。
- **错误结构**：所有失败响应为 `{ error: { code, message } }`，`request.js` 已归一为 `{ code, message }` 供页面直接展示。
- **媒体链接**：`/memorials/{slug}` 返回的 `media_url` 是 900 秒有效的签名地址，不要长期缓存，展示前重新拉取详情。

## 说明

- `app.json` 的 `pages` 第一位保持登录页；新增页面需同步加入该数组。
- 登录成功与「暂不登录」都会进入 `/pages/memorials/list`，未登录时列表只显示公开馆，右上角提供登录入口。
- 留言接口待审时服务端返回 `202`，`request.js` 按成功处理，页面依据 `data.status === 'pending'` 提示「留言待审核，不会立即显示」。
- 成员管理端点（`/api/v1/manage/memorials/{id}/members…`）使用 Bearer token；非馆主返回 `403`（提示「仅馆主可用」），非成员或馆不存在按契约返回 `404`。
- `utils/api.js` 只写资源路径，`/api/v1` 前缀由 `utils/request.js` 统一拼接（旧骨架两处都带了前缀，会请求到 `/api/v1/api/v1/…`，已在此次接入时校正，端点语义未变）。
- 小程序不需要 CORS；在浏览器里直接调 `/api/v1` 出现 CORS 报错属正常现象（服务端只对 JSON API 放开必要来源，或根本未配置 `Access-Control-Allow-Origin`）。
