# v2.1.1 Phase 9 列 E — 小程序流程清单与真机测试交接报告

分支：`feature/v2.1.1-production-hardening`　基线：`e723d07` / Phase 0 `942a6e1`　日期：2026-11-28
范围：**仅文档**。本文件不修改任何代码、配置、测试，也不提交/推送。

标注约定：
- **已执行（本 lane）**：本报告编写时实际跑过的只读命令。
- **已记录（前序阶段）**：Phase 0–8 报告中记录的测试结果；本 lane **未重跑**，仅核对源码/报告存在。
- **未执行**：需要 Android / iOS 真机或微信开发者工具，本分支没有执行，结果表一律写「未执行」。
- **OPEN**：代码中确认不存在或尚未实现，不是"通过"。

---

## 0. 结论摘要（诚实口径）

| 项目 | 结论 |
|---|---|
| 页面清单 | `miniprogram/app.json` 实际注册 **18 个页面**，无子包、无额外页面目录；本文件按真实清单列出（§1.1）。 |
| 「21 页」口径 | Phase 0 报告（`00_BASELINE_REPORT.md` §2、`01_CURRENT_ARCHITECTURE.md` §1）写「21 个页面四件套」，与仓库事实不符：实测 18 页 × 4 文件 = 72 个页面文件，加 13 个支撑文件正好 85 个文件。差额 3 个按 §15 流程目标单列（§1.2），**不是已存在的页面**。 |
| 本地静态检查 | 本 lane 实跑 `node --check` 覆盖全部 24 个 miniprogram JS 文件，rc=0；解析 `app.json` 得到 18 页，rc=0（§3）。 |
| API 级测试 | `tests/run.php` 中存在登录/401/过期 token/小程序页面注册/Phase 1–7 等 API 级用例（§4）；Phase 8 报告记录 `bash bin/test.sh` 166/0，本 lane **未重跑**。 |
| 真机/开发者工具测试 | **未执行**。本文只提供可操作的验收清单与空白结果表（§5、§6）。 |
| 代码中已确认缺口 | 小程序无上传 UI、无档案详情页、无任务取消按钮、无 `onShareAppMessage`、无网络状态监听、无离线队列（§7）。 |

---

## 1. 页面清单

### 1.1 `app.json` 实际注册的 18 个页面（CONFIRMED，本 lane 只读核对）

| # | 路径 | 主要 API / 职责 | §15 相关流程 |
|---:|---|---|---|
| 1 | `pages/login/login` | `POST /auth/token`、`POST /auth/wechat`（经 `auth.js`） | 登录、微信授权、游客模式、401 回退落地页 |
| 2 | `pages/memorials/list` | `GET /memorials`、`GET /manage/memorials`；签名封面 | 登录后落地、未登录浏览、下拉刷新、网络恢复重试 |
| 3 | `pages/memorial/detail` | `GET /memorials/{slug}`、tribute、messages、media | 纪念馆详情、签名媒体刷新、祭奠/留言、404、角色判定 |
| 4 | `pages/article/view` | `GET /memorials/{slug}/articles/{id}` | 文章阅读、`navigateBack` 返回 |
| 5 | `pages/manage/create` | `POST /manage/memorials` | 创建草稿、`redirectTo`/`navigateBack` |
| 6 | `pages/manage/members` | `GET/POST /manage/memorials/{id}/members…` | 403 仅馆主、邀请撤销、返回栈 |
| 7 | `pages/report/report` | `POST /reports` | 举报提交、429/422、`navigateBack` |
| 8 | `pages/account/account` | `GET /me`、`POST /auth/logout` | 登录态校验、退出登录 |
| 9 | `pages/families/list` | `GET /families` | 家族列表、`onShow` 重载 |
| 10 | `pages/families/detail` | `GET /families/{id}`、persons、events、genealogy | 家族详情、谱系弹窗、AI 工坊入口、权限可见性 |
| 11 | `pages/persons/list` | `GET /families/{id}/persons` | 人物列表、`onShow` 重载 |
| 12 | `pages/genealogy/index` | `GET /families/{id}/genealogy` | 谱系图、触控/横向溢出（真机必测） |
| 13 | `pages/timeline/list` | `GET /families/{id}/events` | 时间线、`onShow` 重载 |
| 14 | `pages/exhibitions/list` | `GET /families/{id}/exhibitions`、`POST /exhibitions/{id}/publish` | 展览列表/发布、权限 |
| 15 | `pages/ai/workflow` | `POST /families/{id}/ai/workflows`、`GET /ai/workflows/{id}`、`GET /ai/sessions` | AI 创建/轮询/停止、事实来源标签、口述会话 404 |
| 16 | `pages/invitations/accept` | `POST /invitations/accept` | 分享/邀请链接入参 token、`reLaunch` |
| 17 | `pages/archives/list` | `GET/POST /families/{id}/archives` | 档案列表/创建、下拉刷新（**无详情页/无上传**） |
| 18 | `pages/notifications/list` | `GET /notifications`、`POST …/read` | 通知列表、`onShow` 重载 |

页面四件套（js/json/wxml/wxss）齐全；`tests/run.php:3480` 的 D 项断言只覆盖其中 6 个新增页面，其余由本 lane 的 `find` 只读核对。

### 1.2 与「21」差额的 3 个 §15 流程目标（OPEN，非页面）

| 目标 | 仓库事实 | 标注 |
|---|---|---|
| 档案详情 | `api.getArchive()` 已定义（`utils/api.js`），但**无任何页面调用**；`pages/archives/list` 只有列表与创建 | OPEN |
| 图片/视频上传 | 全量 grep `chooseMedia/chooseImage/uploadFile/上传` 在 `pages/**/*.js|wxml` **0 命中**；服务端上传在 Web `ArchiveController::upload`（30 次/小时限流、Phase 4 双哈希），小程序仅展示签名媒体 | OPEN / 小程序不适用 |
| 分享处理 | 全量 grep `onShareAppMessage` **0 命中**；只有 `pages/invitations/accept` 接收 `token` 查询参数 | OPEN |

> 这三项目标必须在真机清单中显式标注为 N/A 或人工确认缺口，不能按"已测通过"填写。

---

## 2. §15 运行时流程与代码证据（静态核对，非运行时验证）

| 流程 | 代码路径与行为 | 真机必要性 |
|---|---|---|
| 登录 | `login.js:40,76` → `auth.js:69,76` → `request.js:105`；token/user 写 storage，成功 `reLaunch('/pages/memorials/list')`；错误走 `{code,message}` toast | 是（wx.login 只能在微信环境） |
| 401 刷新 | `request.js:38,42,137`：401 清 token 并触发唯一回调；`app.js:15-44`：单飞 `recovering` 标志，`WECHAT_LOGIN_ENABLED=true` 时 `auth.ensureSession()`（`auth.js:96`）静默重登，失败 toast + `reLaunch`；当前页已是登录页则直接返回 | 是（并发 401 单飞只能在真机触发） |
| Token 过期 | 服务端过期/撤销/无效 token 均 401（`tests/run.php:2794`）；客户端只在收到 401 后被动处理，`jidian_token_expires_at` 只写不读（无主动续期） | 是（storage 注入 + 真机微信会话） |
| 网络丢失/恢复 | `request.js:142-150` `fail` → `code:'network_error'`；页面各自 toast/错误占位；**无** `wx.onNetworkStatusChange`、无离线队列、无自动重放 | 是（飞行模式/弱网） |
| 图片/视频上传 | 小程序无上传入口；`utils/media.absolute()` 只补 origin、不缓存；`memorial/detail.js:465-510` 图片 404 时重拉详情换新签名，单次停留最多 2 次 | 小程序 N/A；展示链路需真机 |
| 档案详情 | `api.getArchive` 无页面调用；`archives/list.js` 仅 `onLoad/onShow/onPullDownRefresh/create` | N/A / OPEN |
| 纪念馆详情 | `memorial/detail.js:113-150,180-222`：一次性取详情/消息、分页加载、角色解析、`onPullDownRefresh`/`onReachBottom`；私密/暂停统一 404 文案 | 是（分页、签名过期、角色） |
| AI 工作流 | `pages/ai/workflow.js:33-43,91-121,133-210`：确定性幂等键（同文本复用）、每 2s 轮询最多 30 次、终态停轮询、`onHide/onUnload` 清定时器；状态标签含 `awaiting_review/approved/rejected/cancelled`；输出标注「AI 候选（待人工确认）」 | 是（定时器与前后台切换） |
| 取消 | 服务端支持 `cancelWorkflow`（`utils/api.js` 已映射），但**页面无取消按钮、无调用点**；只能展示他端取消后的 `已取消` | 是（确认无 UI 且状态可刷新） |
| 数据权限 | 404 统一文案「无权限访问或内容不存在」（`request.js:81-99`）；成员管理 403 提示；Phase 5 后跨家族口述会话 404；小程序无数据导出/删除请求页（Web-only） | 是（跨家族账号切换） |
| 分享 | 无 `onShareAppMessage`；`invitations/accept.js:14-24` 接受 `token` 入参；签名媒体 URL 不得写入分享参数 | 是（分享卡片、token 链接） |
| 返回导航 | `article/view.js:60`、`manage/create.js:135-139`、`report/report.js:112`、`manage/members.js:375` 用 `navigateBack`；登录/邀请用 `reLaunch`（清栈） | 是（栈深度与返回目标） |
| 页面生命周期 | `onLoad/onShow/onHide/onUnload/onPullDownRefresh/onReachBottom` 分布于列表页与 AI 页；列表页 `onShow` 重载，AI 页 `onShow` 恢复未终态轮询 | 是 |
| 弱网络 | 默认 `REQUEST_TIMEOUT=10000`（`config.js`），可被 `opts.timeout` 覆盖；AI 轮询遇错即停；提交按钮 `loading` 防连点 + 幂等键 | 是（限速/丢包） |
| 后台 | `app.js:10` 仅 `onLaunch` 设置 `globalData.user`；无后台音频/定位；`AI onHide` 停轮询，`onShow` 重启 | 是（切后台/锁屏/回来） |

---

## 3. 本 lane 实际执行的本地检查（已执行）

| 命令 | 结果 | 说明 |
|---|---|---|
| `find miniprogram -name '*.js' -print0 \| xargs -0 -n1 node --check` | rc=0，24/24 文件 | Node v24.15.0；只做语法检查，**不验证微信 API 行为** |
| `node -e "const c=require('./miniprogram/app.json'); …"` | rc=0，输出 18 个路径 | 页面注册清单与计数 |
| `find miniprogram/pages -name '*.js' / '*.json' / '*.wxml' / '*.wxss'` | 各 18 个 | 四件套齐全；18×4=72，加 13 支撑文件=85 |
| `grep -rn 'chooseMedia\|chooseImage\|uploadFile\|onShareAppMessage\|onNetworkStatusChange' miniprogram/pages` | 0 命中 | §1.2 / §7 缺口依据 |
| `git diff --check`（本 lane 结束前执行） | 见 §8 | 仅校验本文件改动不引入空白错误 |

**未运行**：`bash bin/test.sh`、PHP lint/PHPStan、迁移、doctor、真实 HTTP 请求（本 lane 只写文档，不重复前序阶段门禁）。

---

## 4. `tests/run.php` 中现存的相关 API 级用例（已记录 / 本 lane 未重跑）

| 行号 | 用例 | 对应 §15 流程 |
|---:|---|---|
| 977 | `API 登录与 401 错误包络` | 登录、401、错误归一 |
| 1001–1011 | 微信登录假 code / 未配置分支 | 微信登录 |
| 2660, 2683–2685, 2784–2806 | 未登录/无效/撤销/过期 token 均 401；Web Session 不能替代 Bearer | Token 过期 |
| 3480 | `D：小程序新增页面已注册且四件套齐全` | 页面注册 |
| 3570–3660 | P1 状态机/审核/取消/状态 API | AI 工作流 |
| 3683–3760 | P2 截止时间、租约、超时停止 | AI 超时/取消 |
| 3764–3840 | P3 事实来源与审核联动 | AI 结果可信度 |
| 3844–3928 | P4 双哈希、多态文件拒绝、清理 | 上传安全（服务端） |
| 3931–3968 | P5 口述会话/导出越权 404 | 数据权限 |
| 3970–4044 | P6 订单/通知幂等（10 进程并发） | 并发一致性 |
| 4045+ | P7 加密备份核验 | 备份（非小程序流程） |

来源：Phase 0–8 报告记录 `bash bin/test.sh` 最终 **166 通过 / 0 失败**（最后一次记录在 `16_MODERATION_AND_TEST_MODULARIZATION_REPORT.md`）。本 lane 未重新执行，不把它们当作本次真机验收结果。

---

## 5. 真机 / 微信开发者工具验收清单（交给设备负责人）

环境要求：Android 与 iOS 各一台真机 + 微信开发者工具；`config.js` 的 `BASE_URL` 指向已备案 HTTPS 域名；开发者工具本地调试可勾选「不校验合法域名」。每条都需在**弱网/飞行模式切换**下额外重复一次（D07–D09）。

| ID | 流程 | 操作步骤 | 预期结果 |
|---|---|---|---|
| D01 | 邮箱登录 | 登录页输入 demo 账号/错误密码分别提交 | 成功写入 token/user 并 `reLaunch` 列表；失败仅 toast 错误文案、不跳页 |
| D02 | 微信登录 | 点「微信登录」，允许/拒绝授权各一次 | 允许后静默换 token 并进列表；拒绝显示「微信登录失败」，`WECHAT_LOGIN_ENABLED=true` 时无「暂未配置」 |
| D03 | 游客浏览 | 点「暂不登录」 | 进入纪念馆列表，仅公开馆可见，右上角可再进登录 |
| D04 | 401 静默续期 | 后台使 token 失效后，在两个页面同时触发请求 | 只出现一次续期动作；成功则原页面数据可重新加载，失败 toast「登录已过期，请重新登录」并 `reLaunch` 登录页；登录页收到 401 不循环跳页 |
| D05 | Token 过期 | 手动改 storage 中 token 为无效值后打开任意私有页 | 统一 404/401 文案，不出现原始错误 JSON；最终回到登录页或完成静默重登 |
| D06 | 退出登录 | 个人中心点退出（含服务端失败模拟） | 本地 token/user/过期时间均清除，回到登录页 |
| D07 | 网络丢失 | 列表页进入后开飞行模式，下拉刷新 | toast「网络连接失败…」，无崩溃、无白屏；页面保留已有数据或错误占位 |
| D08 | 网络恢复 | 关闭飞行模式，再次下拉/返回重进 | 列表重新加载成功；签名封面重新获取，不复用过期 URL |
| D09 | 弱网/超时 | 代理限速或高延迟到 >10s | 请求按 10s 超时失败并 toast；重复提交按钮保持 loading 防连点 |
| D10 | 纪念馆详情 | 打开公开/私密/暂停馆各一 | 公开馆正常；私密/无权限统一「无权限访问或内容不存在」；不暴露存在性差异 |
| D11 | 签名媒体刷新 | 详情页停留至签名过期或手动破坏 URL | 自动重拉详情换新签名，单次停留最多 2 次，之后显示占位；图片/视频不写入 storage |
| D12 | 视频/音频展示 | 在含视频/音频的馆详情页播放 | 可播放；失效时同 D11 刷新逻辑；无签名 URL 被分享/缓存 |
| D13 | 图片/视频上传 | 在小程序内寻找上传入口 | **预期：无入口（OPEN）**；上传是 Web-only，勿记为通过。若需验收上传安全请走 Web 清单（Phase 4 双哈希） |
| D14 | 档案列表/创建 | 家族详情进入档案页，创建一条档案 | 列表与创建成功；下拉刷新重载；无详情页入口 |
| D15 | 档案详情 | 尝试打开档案详情 | **预期：无页面（OPEN）**；`api.getArchive` 无调用点，勿记为通过 |
| D16 | AI 创建幂等 | 同一段文字连点两次提交 | 只产生一个任务；idle 按钮 loading 生效；幂等键由 `mp-{familyId}-{hash}` 复用 |
| D17 | AI 轮询/生命周期 | 提交后切后台、锁屏、返回，下拉刷新 | `onHide/onUnload` 停轮询；返回后未终态任务恢复；2s/次、最多 30 次，超限提示下拉刷新 |
| D18 | AI 状态展示 | 观察 queued→processing→awaiting_review/approved/rejected/cancelled | 标签正确；输出显示「AI 候选结果（AI 推测，待人工确认）」；无自动确认为事实 |
| D19 | AI 取消 | 查找取消按钮；在 Web 端取消后回小程序刷新 | **预期：小程序无取消按钮（OPEN）**；刷新后显示「已取消」，不再轮询 |
| D20 | 数据权限 | 用 A 用户打开 B 家族/纪念馆/成员管理 | 统一 404；馆内非馆主成员管理 403「仅馆主可用」 |
| D21 | 口述会话授权 | 跨家族用户进入 AI 页展开口述会话 | 会话列表/片段均 404（Phase 5 IDOR 修复）；授权状态标签正确 |
| D22 | 分享/邀请 | 通过分享链接打开 `/pages/invitations/accept?token=…` | 接受邀请后 `reLaunch` 家族列表；分享卡片走微信默认（无自定义 handler，OPEN）；签名媒体 URL 不在参数中 |
| D23 | 返回导航 | 文章页/建馆页/举报页/成员页点系统返回 | 回到正确上级；登录/邀请后的 `reLaunch` 清空历史，不返回旧栈 |
| D24 | 页面生命周期 | 在家族/人物/时间线/通知页之间反复切换 | `onShow` 每次重载，数据与服务端一致；无重复定时器 |
| D25 | 后台/前台 | AI 页提交后切后台 30s 再回前台 | 后台无轮询；回前台按 `onShow` 恢复；无重复创建/重复通知 |
| D26 | 谱系触控 | 双指缩放、横向拖动谱系图 | 当前无双指缩放（`N-16`），横向溢出需记录；不声称已支持 |
| D27 | 留言待审 | 在纪念馆详情留言 | 服务端 202 时提示「留言待审核，不会立即显示」，列表不出现该条 |

> D13/D15/D19/D22 为"确认缺口"类检查：预期是**没有功能**，不是验证功能可用。若设备负责人期望这些功能可用，应先开需求/开发项，不能在结果表写"通过"。

---

## 6. 结果表（全部未执行，待设备负责人回填）

| ID | 执行人 | 设备/环境 | 实际结果 | 证据（截图/日志） | 备注 |
|---|---|---|---|---|---|
| D01 | — | — | **未执行** | — | 需微信环境 |
| D02 | — | — | **未执行** | — | 需真机 wx.login |
| D03 | — | — | **未执行** | — | 需微信环境 |
| D04 | — | — | **未执行** | — | 需并发 401 场景 |
| D05 | — | — | **未执行** | — | 需改 storage + 微信会话 |
| D06 | — | — | **未执行** | — | 需微信环境 |
| D07 | — | — | **未执行** | — | 需飞行模式 |
| D08 | — | — | **未执行** | — | 需飞行模式 |
| D09 | — | — | **未执行** | — | 需限速/代理 |
| D10 | — | — | **未执行** | — | 需不同可见性数据 |
| D11 | — | — | **未执行** | — | 需签名过期场景 |
| D12 | — | — | **未执行** | — | 需媒体样例 |
| D13 | — | — | **未执行** | — | 缺口类：预期无入口 |
| D14 | — | — | **未执行** | — | 需家族数据 |
| D15 | — | — | **未执行** | — | 缺口类：预期无页面 |
| D16 | — | — | **未执行** | — | 需真机重复提交 |
| D17 | — | — | **未执行** | — | 需前后台切换 |
| D18 | — | — | **未执行** | — | 需服务端推进状态 |
| D19 | — | — | **未执行** | — | 缺口类：预期无按钮 |
| D20 | — | — | **未执行** | — | 需两个账号 |
| D21 | — | — | **未执行** | — | 需跨家族账号 |
| D22 | — | — | **未执行** | — | 缺口类：无自定义分享 |
| D23 | — | — | **未执行** | — | 需多页面跳转 |
| D24 | — | — | **未执行** | — | 需反复切换 |
| D25 | — | — | **未执行** | — | 需后台 30s |
| D26 | — | — | **未执行** | — | 已知无双指缩放 |
| D27 | — | — | **未执行** | — | 需审核链路配置 |

---

## 7. 代码层面已确认的缺口（OPEN，勿误判为通过）

1. **无上传链路**：`wx.chooseMedia/chooseImage/wx.uploadFile` 在小程序 pages 中 0 命中；服务端上传仅 Web。
2. **无档案详情页**：`api.getArchive` 未被任何页面调用。
3. **无取消按钮**：`api.cancelWorkflow` 未被任何页面调用；服务端取消已在 Phase 1/2 实现，但小程序不可触发。
4. **无分享处理**：`onShareAppMessage` 0 命中；只有邀请链接入参页面。
5. **无网络状态监听/离线队列**：`wx.onNetworkStatusChange` 0 命中；弱网下无自动重放。
6. **无主动 token 过期检查**：`jidian_token_expires_at` 只写不读，依赖 401 被动触发。
7. **页面计数不一致**：Phase 0 报告「21 页」与 `app.json` 18 页不符；本报告以 18 页 + 3 个缺失流程目标对齐 §15 的 21 个检查目标。
8. **文档口径不一致**：`utils/media.js` 注释写签名 900 秒，`miniprogram/README.md` 写 300 秒；以服务端实际签名为准，真机以"过期后自动刷新"行为验收。

---

## 8. 校验与交接

- 本 lane 仅写本文件；未改代码/测试/其他文档，未提交、未推送。
- `git diff --check` 的执行命令、退出码与文件尺寸记录在交付摘要中。
- 真机负责人回填 §6 后，应把证据（截图/录屏/日志路径）附在本文件后，并将 D13/D15/D19/D22 单独列为需求缺口，而不是"测试通过"。
