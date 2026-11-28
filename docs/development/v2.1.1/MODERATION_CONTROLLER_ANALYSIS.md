# v2.1.1 Phase 0 — ModerationController 结构分析

基线：`e723d07`。只读分析，未改代码。

## 1. 文件事实（CONFIRMED）

- `app/Controllers/Admin/ModerationController.php`：**13,022 字节 / 347 行 / 1 个 final 类 / 10 个方法**。
- 历史提交 `e723d07` 仅修改该文件（19+/19-），清理了约 **1,754KB 的行首空白污染**；`git diff -w` 唯一非空白变化为注释 `上Arthur` → `上限`。**污染前后方法数均为 10，结构未变**。
- 方法长度：

| 方法 | 行 | 长度 |
|---|---:|---:|
| `index` | 21-25 | 5 |
| `messageStatus` | 27-52 | 26 |
| `articleStatus` | 54-94 | 41 |
| `mediaStatus` | 96-129 | 34 |
| `messageBatch` | 132-172 | 41 |
| `articleBatch` | 175-229 | 55（最长） |
| `mediaBatch` | 232-284 | 53 |
| `parseIds`（private） | 287-304 | 18 |
| `renderIndex`（private） | 306-337 | 32 |
| `guard`（private） | 339-346 | 8 |

平均方法长度 31.3 行；7 个公开 action 共 255 行，平均 36.4 行。**当前文件不再“超大”**，但存在明显重复代码。

## 2. 职责与依赖（CONFIRMED）

- 职责：后台审核面板数据装配、单条审核（消息/文章/媒体）、批量审核、ID 解析与去重上限 100、输入校验（枚举、备注 ≤500 字）、审计、flash 文案、视图渲染、授权。
- 依赖：`Audit`、`Auth`、`Csrf`、`Db`、`Flash`、`HttpException`、`Request`、`Response`、`View`；模型 `Article`、`Media`、`Message`；内联 `\App\Core\Db::fetchAll`。
- 无内嵌 HTML/JSON/`echo`/`?>`；HTML 全部在 `app/Views/admin/moderation.php`。视图数据 9 个键（`:326-336`）：`title/noindex/active/pendingMessages/pendingArticles/hiddenMedia/recentMedia/errors/old`。
- 路由 `app/routes.php:234-240` 映射 7 个公开方法，均带 `['admin']` 中间件；`guard()`（`:339-346`）要求 `Auth::user()` 且 `role==='admin'`，否则 403。**不使用 `Policy::canViewResource`——审核为全局管理员权限，非家族范围**（与任务书示例不同，属于实际设计）。

## 3. 重复代码（CONFIRMED）

- 三处近乎相同的批量预加载 SQL：`id IN (` . `implode(',', array_fill(...))` 于 `:150-152`（消息）、`:198-200`（文章）、`:250-252`（媒体）；随后相同的 `$byId` 构建循环于 `:153-154/:201-202/:253-254`。
- 三处批量循环骨架（≈20-25 行重复）：`:155-168`、`:203-224`、`:255-279`。**语义差异必须保留**：消息/文章跳过非 `pending`（`:157`、`:205`）；媒体 `show` 跳过非 `hidden`（`:261`），`hide` 仅跳过缺失行（`:257-260`）；文章备注透传（`:216`）。
- `renderIndex` 两条 join 查询仅差 `status='hidden' LIMIT 50` vs `status='active' LIMIT 20`（`:308-315`、`:317-324`）。
- 其他重复：备注长度校验 `mb_strlen($note)>500`（`:72`、`:186`）；枚举校验与 422 `renderIndex`（多处）；6 个 POST action 的 `Csrf::verify` + `guard`；6 处 `Response::redirect('/admin/moderation')`；约 10 处 `Audit::log(..., ['memorial_id'=>…])`。

## 4. 最小拆分建议（INFERENCE，Phase 8 执行）

保持 `ModerationController` 为薄门面，**7 个公开方法名/签名/路由/响应/flash 字符串/权限语义完全不变**，只抽离私有内部：

1. `Moderation\BatchProcessor`：承载共享的预加载 → 去重 → 跳过 → 应用 → 审计 → flash 骨架（`:142-172`、`:195-228`、`:247-283`），以表/枚举/回调/审计动作/flash 模板参数化；**保留每类内容的跳过规则差异**。
2. `Moderation\ModerationQueries`：承载 `renderIndex` 的两条 join（`:308-324`）及 `Message::pendingAll`/`Article::pending`（`:330-331`）；控制器仍以相同 9 个键调用 `View::render('admin/moderation', …)`。
3. `Moderation\IdsParser`：`parseIds()`（`:286-304`：仅数字、去重、上限 100、空/超限返回 null）与共享备注长度常量（`:72`、`:186`）。

不引入额外抽象层；不拆分 7 个公开 action，以避免路由与权限语义漂移。

## 5. 拆分前后对比项（Phase 8 验收）

路由表、API 响应、管理页面、审核结果、权限拒绝、审计日志、异常响应、SQL 数量（`parseIds` 预加载后每批 1 条 `IN` 查询）、性能（`bin/test.sh` 耗时与 145 项结果）必须一致。

## 6. 测试模块化分析与计划（CONFIRMED 事实 + INFERENCE 方案）

- `tests/run.php`：**205,149 字节 / 3,785 行**；`^test(` **145 项**（`grep -c 'test('` 为 146 是含函数定义），与基线 145/0 一致。
- 顶层helper 15 个：`test/check/check_contains/check_not_contains/api/api_token_of/http/effective_path/csrf_token/reset_session/login/logout/db_count/expect_http_error/make_family_user`。
- 27 个分节标题 + 1 个注释分节；顺序注册即顺序执行；共享 cookie jar 与单一 `jidian_test` 库；无逐测试事务回滚（靠 `install.php --fresh --demo` 重建）；25 处 `finally` 清理；末尾 `exit(1)/exit(0)` 正确。
- 真实并发：`rate.php` 经 `shell_exec` + `xargs -P 25`（100 进程）；`claim-once.php` 经 `proc_open` 10 进程；`bin/ai-workflow-worker.php --once` 真实子进程。其余为顺序调用。
- 明确缺口：步骤状态断言仅“步骤数=3”，无每步状态；无工作流 deadline / 步骤超时 / 退避配置测试；取消仅顺序，无 cancel-vs-process 竞态；`original_hash`/`processed_hash` 全库 0 命中；IDOR 覆盖为抽样；订单确认无并发/幂等键测试；通知去重重放未测；`tests/` 无任何 backup 引用。

**增量模块化方案（保持 `bash bin/test.sh` 与 145 项不变）**：
1. 将 15 个 helper 移至 `tests/Support/Harness.php`，与 `MockRespServer.php` 一并 require；globals 保留在 `run.php`，函数内已用 `global`。
2. 按 27 个既有分节把 `test(...)` 注册切到 `tests/suites/*.php`，保持注册顺序与 `echo` 标题逐字不变；`run.php` 按原顺序 require，末尾结果/退出逻辑不动。
3. 共享状态夹具（`$cleanupTotp` 闭包）保留在 harness 或与其消费者同文件。
4. **迁移前必须先修路径**：`:3039` 用 `__DIR__.'/concurrency/rate.php'`、`:3746` 用 `BASE_PATH.'/tests/concurrency/claim-once.php'`；切到 suites 后 `__DIR__` 改变，统一改用 `BASE_PATH`。
5. 每步门禁：`run.php` + suites 的 `grep -c '^test('` 总和恒为 145；`bash bin/test.sh` rc=0 且输出 `通过：145，失败：0`；`✓/✗` 行与 `integration.log` diff 为空。
