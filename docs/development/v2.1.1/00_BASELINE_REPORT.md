# 云叙台 v2.1.1 — Phase 0 基线报告

任务书基线：`ff23bc5c59c44283f768b3fadeba1c8fda76c356`（v2.1.0 合并提交）
实际有效基线：`e723d07`（main HEAD，现工作分支起点）
审计分支：`feature/v2.1.1-production-hardening`
审计日期：2026-11-28
执行方式：只读审查 + 5 路并行探索（架构/API、AI 工作流、资源权限、数据库事务与备份、测试与控制器结构），交叉验证后汇总。**本阶段未修改任何业务代码、配置、测试、Migration 或数据库。**

标注：**CONFIRMED**（代码/命令证据）/ **NEEDS_VERIFICATION**（需真实环境验证）/ **PROPOSED**（本轮计划）。

---

## 1. Git 基线与工作区

| 项目 | 结果 | 标注 |
|---|---|---|
| 任务书基线 | `ff23bc5` | CONFIRMED |
| 实际 main HEAD | `e723d07` | CONFIRMED |
| 两者差异 | 仅 `app/Controllers/Admin/ModerationController.php` 19+/19-；忽略空白后唯一非注释变化为 `列表；上Arthur` → `列表；上限` | CONFIRMED |
| 行为影响 | 注释修正，无行为变更，故以 `e723d07` 作为 v2.1.1 有效基线 | CONFIRMED |
| 工作区 | `git status --short` 为空 | CONFIRMED |
| 最新 tag | `v2.1.0`（指向 v2.1.0 合并提交） | CONFIRMED |
| 新分支 | `feature/v2.1.1-production-hardening`，未向 main 提交 | CONFIRMED |

## 2. 目录与规模

| 目录 | 文件数 | 说明 |
|---|---|---|
| `app/` | 209 | Core 31、Controllers 37（Web 23/Admin 14/Manage 7）、Api 15、Services 16、Models 19、Views 86 |
| `bin/` | 12 | 含 `ai-workflow-worker.php`、`archive-cleanup.php`、`backup.php`、`restore-check.php`、`doctor.php`、`test.sh` |
| `database/` | 34 | `schema.sql` 56 表 + 33 个 Migration（20261127_001–027、20261128_001–005） |
| `miniprogram/` | 85 | 21 个页面四件套 + 4 个 utils |
| `public/` | 9 | 单入口 `index.php` + CSS/JS |
| `tests/` | 4 | `run.php`、`concurrency/{rate,claim-once}.php`、`Support/MockRespServer.php` |

## 3. 架构与调用链（摘要，详见 01_CURRENT_ARCHITECTURE.md）

- Web 184 路由（79 GET/105 POST；auth 119、admin 39、guest 6、public 20）；API 57 路由（24 GET/33 POST；api-auth 50、public 7）。`api-admin` 已定义但未被路由使用。
- Web/API 共用 `public/index.php` 与 `app/Core/Router.php`；`api-auth` 仅接受 Bearer，无 Web Session 回退。
- AI 存在两套栈：v2 `ai_workflows` + `AiWorkflowService` + `bin/ai-workflow-worker.php`，以及 legacy `ai_jobs` + `AiStudioService` + `bin/ai-worker.php`。
- 归档删除采用“审批入队 + `archive-cleanup.php` 异步幂等清理”；审计写入 `request_id`；部署为单 FPM + AI worker（systemd unit）+ 归档清理 cron。

## 4. 基线测试（详见 05_TEST_BASELINE.md）

- `bash bin/test.sh`：rc=0，**145 通过 / 0 失败**，49 秒。
- PHP lint / composer install / composer validate --strict / PHPStan Level 5 `[OK] No errors` / gitleaks 本地脚本 / Node 24 `node --check` / migration status（32 版本全 applied）/ doctor（0 阻断、4 警告）：全部 rc=0。
- 本地数据库为 MariaDB 11.8.9；MySQL 8.4 与四矩阵由 CI 覆盖，本阶段未声称本地已跑 MySQL。

## 5. 已确认问题与待验证风险

- 已确认问题、数据库事务、权限、测试覆盖、控制器结构等见 `02_AI_WORKFLOW_ANALYSIS.md`、`03_SECURITY_AND_PERMISSION_AUDIT.md`、`04_DATABASE_AND_TRANSACTION_AUDIT.md`、`05_TEST_BASELINE.md`、`MODERATION_CONTROLLER_ANALYSIS.md`（后续文档）。
- 无证据的项一律标为 NEEDS_VERIFICATION，Phase 0 不写“已确认漏洞”。
- 本轮不处理的问题将在 `06_REFACTORING_PLAN.md` 中明确列出。

## 6. 结论

基线一致、工作区干净、全部门禁通过。满足任务书 Phase 0 进入 Phase 1 的条件；本阶段未改任何文件，等待按 Phase 1–10 顺序推进。
