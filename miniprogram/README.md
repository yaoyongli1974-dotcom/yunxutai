# 静思园小程序（开发参考骨架）

这是 `/api/v1` 的最小客户端示例：登录页 + 请求/鉴权/接口封装。**不是成品小程序**，用于说明如何对接服务端，业务页面（纪念馆列表、详情、留言等）请在此基础上继续开发。

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
  pages/login/                     // 可用的登录示例页（邮箱密码 + 微信登录按钮）
```

## 必须遵守的约束

- **合法域名**：`wx.request` 只能访问微信公众平台「开发管理 → 服务器域名」中配置过的 **HTTPS** 域名。`http://127.0.0.1:8099` 仅在开发者工具「不校验合法域名」时可用，真机与上线必须换成 HTTPS 域名。
- **code2session 只在服务端**：小程序只把 `wx.login` 返回的 `code` 发给 `/api/v1/auth/wechat` 换 token，**客户端不得保存或传输 AppSecret**。服务端未配置 `WX_APPID`/`WX_SECRET` 时该接口返回 `503 wechat_not_configured`。
- **token 续期**：token 过期后接口返回 `401`，`request.js` 会清 token 并触发 `app.js` 注册的回调跳回登录页；开启微信登录后可先用 `wx.checkSession` 判断微信会话，再 `wx.login` 静默重换 token（`auth.ensureSession()` 已封装）。
- **404 即无权限**：私密/暂停纪念馆、未发布内容、越权资源在服务端统一返回 404，客户端不要再区分「不存在」与「无权限」，统一提示「无权限访问或内容不存在」。
- **错误结构**：所有失败响应为 `{ error: { code, message } }`，`request.js` 已归一为 `{ code, message }` 供页面直接展示。
- **媒体链接**：`/memorials/{slug}` 返回的 `media_url` 是 900 秒有效的签名地址，不要长期缓存，展示前重新拉取详情。

## 说明

- `app.json` 目前只注册登录页；新增页面需同步加入 `pages` 数组。
- 登录成功后骨架只弹 toast，接入业务页后替换为 `wx.reLaunch` 跳转。
- 小程序不需要 CORS；在浏览器里直接调 `/api/v1` 出现 CORS 报错属正常现象（服务端只对 JSON API 放开必要来源，或根本未配置 `Access-Control-Allow-Origin`）。
