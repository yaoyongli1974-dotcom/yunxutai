# Phase D — 用户体验报告

基线：`cbc4042`（Phase C 提交）；审查/实施分支：`feature/v2.1.0-production-hardening`。
标注：**CONFIRMED**（代码/测试证据）/ **NEEDS_VERIFICATION**（需真机/运行环境验证）/ **OPEN**。

## 1. 新用户引导

- **CONFIRMED**：`app/Views/account/dashboard.php:13-45` 增加非阻断五步清单（创建纪念馆 → 添加人物 → 上传资料 → AI 整理（可跳过）→ 邀请家人）。仅在 `$memorials === []` 时显示；`public/assets/js/app.js` 使用 per-user `localStorage` 键 `yxt-onboarding-dismissed-{uid}` 支持跳过与恢复，并对隐私模式做 try/catch。
- **CONFIRMED**：`public/assets/css/app.css` 新增清单/进度样式，包含 ≤560px 响应式规则与 ≥40px 触控目标。
- **NEEDS_VERIFICATION**：Dashboard 控制器未向视图传递人物/档案数量，因此服务端精确进度未实现（当前按“无纪念馆”门控）。若需要按真实完成度隐藏引导，需扩展 `DashboardController` 传参（列为后续小改）。

## 2. 批量上传与进度

- **CONFIRMED**：`app/Views/manage/media.php:84-106,192`、`app/Views/archive/show.php:80-84,167` 增加渐进增强批量面板（默认隐藏、JS 展开），`multiple` 文件输入，原有单文件上传表单保留为 fallback。
- **CONFIRMED**：新增 `public/assets/js/batch-upload.js`：顺序 FIFO 队列复用现有单文件端点，逐文件 `xhr.upload.onprogress` 进度、成功/失败状态与失败重试按钮；不新增端点、不改变契约、不模拟断点续传。结果通过既有 PRG 响应中的 flash/form-error 标记判定。
- **CONFIRMED**：`node --check public/assets/js/app.js` 与 `batch-upload.js` 均 rc=0。
- **NEEDS_VERIFICATION**：归档上传端点存在 30 次/小时限流；大批量失败项会进入重试列表。真实移动网络下的进度体验与微信 WebView 兼容性需真机验证。

## 3. 空状态与移动端

- **CONFIRMED**：共享空状态组件 `app/Views/partials/empty-state.php:11-17` 与既有 24 处使用点在 Phase D 未删除；`app.css` 已有 1280/960/768/560 等断点，Phase D 新增样式沿用同一体系并限制 `min-width:0`、`overflow-wrap:anywhere`、`max-width:100%` 以避免横向溢出。
- **CONFIRMED**：谱系前端 `public/assets/js/genealogy.js:32` 保持 200 节点渲染上限，Phase D 未改渲染契约。
- **NEEDS_VERIFICATION**：未在真实手机/微信开发者工具逐页走查触控与弹窗布局。

## 4. 回归证据

- `bash bin/test.sh`：143 通过 / 0 失败（Phase C 138 + Phase D 新增 5 项：`D：审计元信息脱敏…`、`D：API 令牌签发写入带 actor 的审计`、`D：生产环境开启 APP_DEBUG 时配置 fail-closed`、`D：小程序新增页面已注册且四件套齐全`、`D：性能索引迁移已应用`）。
- PHP lint / node --check / composer validate --strict / PHPStan / secret-scan / 迁移状态+重复 / doctor / `git diff --check`：均 rc=0。
- 本阶段未删除测试、未降低门禁、未改业务数据模型。

## 5. 结论与剩余项

**CONFIRMED**：新用户引导、批量上传进度/失败重试、移动端样式补齐均已落地且有自动化证据。
**OPEN / NEEDS_VERIFICATION**：服务端精确引导进度、真机布局走查、大批量上传限流下的运营策略。详见 `33_DEPLOYMENT_CHECKLIST.md` 与 `93_UNRESOLVED_RISKS.md`（Phase E 汇总）。
