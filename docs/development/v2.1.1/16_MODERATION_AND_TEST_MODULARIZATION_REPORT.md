# Phase 8 — ModerationController 结构治理与测试模块化报告

基线：Phase 7 提交 `c92e97c`，分支 `feature/v2.1.1-production-hardening`。标注：**CONFIRMED**（代码/测试证据）/ **OPEN**（尚未完成）。

## 1. 阶段名称
超大 ModerationController 结构治理 + `tests/run.php` 测试体系模块化（第一步）。

## 2. 修改文件
- `app/Controllers/Admin/ModerationController.php`（薄 façade）
- `app/Services/Moderation/IdsParser.php`（新增）
- `app/Services/Moderation/ModerationQueries.php`（新增）
- `app/Services/Moderation/BatchProcessor.php`（新增）
- `tests/Support/Harness.php`（新增）
- `tests/run.php`（移除 13 个 helper 定义，改为 require Harness）

未改：`app/routes.php`、`app/Views/admin/moderation.php`、`app/Models/*`、其他 Service、任何测试注册与顺序。

## 3. 控制器结构治理（CONFIRMED）

### 拆分前后对比
| 项目 | 拆分前 | 拆分后 | 结论 |
|---|---|---|---|
| 文件字节 / 行数 | 13,022 / 347 | 9,129 / 238 | 减少 124 行 |
| 公开 action | 7（`index/messageStatus/articleStatus/mediaStatus/messageBatch/articleBatch/mediaBatch`） | 7（同名同签名） | 路由与前端零改动 |
| 私有方法 | 3 | 2（`renderIndex/guard`） | `parseIds` 改为委托 |
| 单条审核逻辑 | 原样 | 原样 | 未动 |
| 批量预加载/循环骨架 | 3 份近似重复 | 收拢到 `BatchProcessor::run()` | 每类型 skip 规则与审计字段保留 |
| 仪表盘两个 JOIN 查询 | 控制器内 | `ModerationQueries::dashboardData()` | 查询数量与顺序不变，`View::render` 9 个键不变 |
| ids 解析 | 控制器私有 | `IdsParser::parse()` | 数字过滤、去重、上限 100、空返 null 一致 |
| 权限 | `guard()` = admin 且 CSRF | 未改 | 无重复鉴权 |
| 审计事件/字段 | 原样 | 原样（含 `batch => true`） | 无变化 |
| Flash / 重定向 / HTTP 状态 | 原样 | 原样 | 用户可见行为不变 |

### 关键设计约束
- 不做机械式“每个 action 一个控制器”，避免路由/响应/权限碎片化；只抽离真正的重复内部实现。
- `BatchProcessor` 的三种规则分别保留：留言/文章仅处理 `pending`；媒体 `show` 仅处理 `hidden`，`hide` 仅跳过缺失行；文章驳回保留统一审核说明。
- 为保持既有 `phpstan-baseline.neon` 中绑定 `ModerationController.php` 的 `arrayValues.list` 条目仍然匹配，`parseIds` 委托保留了无操作意义的 `array_values($ids)`；未新增任何 ignore/baseline。列为 OPEN 技术债（后续可在移除对应 baseline 条目时一并清理）。

## 4. 测试模块化（CONFIRMED / 第一步）
- 新增 `tests/Support/Harness.php`（218 行），逐字移入 13 个 helper：`test/check/check_contains/check_not_contains/api/api_token_of/http/effective_path/csrf_token/reset_session/login/logout/db_count`。
- `tests/run.php` 删除原定义，并在 `MockRespServer.php` 之后 `require Harness.php`；`$baseUrl/$cookieJar/$passed/$failed/$failures` 与结果/退出逻辑仍在 `run.php`。
- 保留 `expect_http_error`、`make_family_user` 于 `run.php`（与测试闭包共享上下文）。
- `grep -c '^test('` 拆分前后均为 **166**；注册顺序、输出、退出码语义未变。`bin/test.sh` 命令与 CI 门禁不变。
- **未迁移** 166 项既有测试到 suites 目录（按任务书“逐步拆分”原则，后续阶段再做）。OPEN。

## 5. 测试与门禁（全部实跑）
- `bash bin/test.sh` rc=0，**166 通过 / 0 失败**，日志 `/tmp/opencode/v2.1.1/phase8/integration.log`。
- PHP lint（全量 PHP）rc=0；PHPStan Level 5 `[OK] No errors` rc=0；`bash bin/secret-scan.sh` rc=0；`node --check` 全量 rc=0。
- `DB_DATABASE=jidian_test php bin/migrate-v2.php --status` rc=0，38 版本全部 applied；重复执行 rc=0；doctor rc=0（0 阻断，4 条环境警告）；`git diff --check` rc=0。
- 拆分前后通过同一套 166 项集成测试，覆盖审核单条/批量、权限拒绝、审计、异常与页面渲染，未出现行为差异。

## 6. 安全影响
无新增攻击面。权限判断仍集中在 `guard()` 与路由 `['admin']`，批量逻辑复用同一审计通道，未扩大管理员能力、未绕过 CSRF。

## 7. 未解决问题
- OPEN：既有测试尚未迁移到 `tests/suites/*`；仅完成 helper 外置与安全入口。
- OPEN：`ModerationController::parseIds` 中的 `array_values()` 为兼容旧 baseline 的无操作调用，待后续清理对应 baseline 条目时移除。
- OPEN：未做大规模数据量下的管理审核页性能压测（Phase 9 性能专项范围）。

## 8. 下一阶段
Phase 9：安全与性能专项（CSP Report-Only 策略、用户数据生命周期、小程序真机验证、性能容量与监控告警）。
