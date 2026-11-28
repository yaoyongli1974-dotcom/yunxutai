# Phase D — 微信小程序报告

基线：`cbc4042`；分支：`feature/v2.1.0-production-hardening`。
标注：**CONFIRMED** / **NEEDS_VERIFICATION** / **OPEN**。

## 1. 登录状态与 401 处理

- **CONFIRMED**：`miniprogram/app.js:9-25` 注册全局 401 回调：清理 token、toast 后 `reLaunch` 到登录页；`utils/request.js:105-153` 在 401 时清 token 并触发回调，`:69-98` 统一错误归一（404→通用提示、429/503 映射）。
- **CONFIRMED**：Phase D 将此前未被调用的 `utils/auth.js::ensureSession()` 接入 401 回调（仅当 `config.WECHAT_LOGIN_ENABLED=true`）：用 `recovering` 标志合并并发 401，成功后恢复 `globalData.user`；失败仍保留原 toast + `reLaunch`。未配置微信登录时维持手动登录行为，不引入硬编码密钥。
- **CONFIRMED**：`node --check` 覆盖 `miniprogram/**` 全部 JS，rc=0。

## 2. 新增页面（用户决策：补页面）

`miniprogram/app.json:2-21` 注册 6 个新页面，均含 js/json/wxml/wxss 四件套：

| 页面 | 路径 | 主要能力 |
|---|---|---|
| 家族人物 | `pages/persons/list` | `listPersons/createPerson/getPerson`；创建字段与服务端一致，隐私默认 `private`（在世人物公开被服务端拒绝） |
| 谱系 | `pages/genealogy/index` | `getGenealogy`；成员与已确认关系的中文化列表、截断提示、空状态 |
| 家族时间线 | `pages/timeline/list` | `listEvents`；日期/标题/来源/地点、空状态 |
| 数字展览 | `pages/exhibitions/list` | `listExhibitions` + `publishExhibition`（二次确认后发布） |
| AI 工坊 | `pages/ai/workflow` | `createWorkflow` 幂等键、2s 轮询 `getWorkflow`（上限 30 次）、步骤/状态展示、人工审核声明、`listOralSessions` 面板 |
| 邀请接受 | `pages/invitations/accept` | `?token=` 或手工输入 → `acceptInvitation`，成功后回家族列表，错误内联展示 |

- **CONFIRMED**：`utils/api.js` 新增 `listOralSessions(id)`（`GET /families/{id}/ai/sessions`）与 `publishExhibition(id)`（`POST /exhibitions/{id}/publish`），复用既有 `request` 封装和 `idPath`，未新增服务端路由。
- **CONFIRMED**：`pages/families/detail.wxml/js` 改为 6 个 `onNav` 入口（人物/档案/谱系/时间线/展览/AI）；`pages/families/list.wxml/js` 增加「接受家人邀请」入口。
- **CONFIRMED**：修正 4 个旧页面错误的 require 路径（`../../../utils/` → `../../utils/`）：`families/list.js`、`families/detail.js`、`archives/list.js`、`notifications/list.js`。

## 3. 权限一致性与错误处理

- **CONFIRMED**：所有新页面只调用既有 `/api/v1` 端点，权限由服务端 `api-auth` + `Policy` 决定；无前端越权判断、无硬编码密钥。AppID 仍位于 gitignored `project.config.json`。
- **CONFIRMED**：签名媒体刷新上限沿用 `pages/memorial/detail.js:488-509` 的 2 次/停留逻辑；Phase D 未改签名算法（Phase B 已绑定可见性并将 TTL 降为 300s）。
- **NEEDS_VERIFICATION**：微信开发者工具/真机逐页走查（尤其 403、无网络、图片加载失败、页面重载后的状态丢失）尚未执行。

## 4. 回归与结论

- `node --check miniprogram/**/*.js` rc=0；`git diff --check` rc=0；集成测试 143/0（含 `D：小程序新增页面已注册且四件套齐全`）。
- **CONFIRMED**：小程序页面覆盖从 12 页扩展到 18 页，补齐任务书列出的人物、谱系、时间线、展览、AI 工作流与邀请接受流程。
- **OPEN**：真机 UI/交互验收、用户切换/网络异常场景的页面级自动化，留 Phase E 或上线前人工走查。
