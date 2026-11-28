# 云叙台 v2.1.0 — AI 工作流可靠性报告（Phase C）

## 1. 范围与结论

对应 Phase 0 风险：R-03（同意实时生效，Phase B 已修）、R-06/R-07（越权写入与租约）、R-08（创建幂等）、R-09（订单并发）、R-11（重试分类）、C10（事实来源追踪）。

本阶段只修改现有函数与新增迁移，不改 API 形状；未降低 PHPStan level，未扩大 baseline，未删测试。

## 2. 已实现改动

| 项 | 文件/位置 | 改动 | 验证 |
|---|---|---|---|
| R-06/R-07 fencing | `app/Services/AiWorkflowService.php`：`owns()` 增加 `(lease_until IS NULL OR lease_until > NOW())`；`process()` 在未传 token 时领取后回读 `lock_version`；成功/失败 UPDATE 一律带 `status='processing' AND locked_by=? AND lock_version=?` | Web 入口与 CLI 走同一围栏；取消后旧 token 无法写回 | `C2：取消工作流后旧 fencing token 无法写回结果` PASS |
| R-08 创建幂等 | `create()` 在事务内捕获 `PDOException` SQLSTATE 23000，按 `(family_id, workflow_type, idempotency_key)` 回查返回既有 id | 并发同 key 返回单一 id，不产生 500 | 全量 138/0；串行幂等测试保留 |
| R-09 订单并发 | `app/Controllers/Admin/AiOrderController.php::confirm()` 整体事务 + `SELECT status ... FOR UPDATE`，锁定后校验 `pending`，再建 job/同步处理/更新 paid/审计 | 重复确认不再产生多个 job | 全量测试 PASS；并发专项见 §4 |
| R-11 重试分类 | `process()` catch：`HttpException→input_error`（不重试）、`ProcessingException→$e->category`（`retryable=false` 不重试）、其他→`provider_error` | 永久错误不再耗尽重试 | 全量 138/0 |
| 事实来源 | 新增 `database/migrations/20261128_002_ai_fact_provenance.php`：`memory_facts` 增加 `source_archive_id/source_version_id/source_excerpt/subject_type/subject_id` + 2 KEY；`addFactCandidate()` 追加可选参数并写字段，主体缺省按 person/family 派生 | AI 事实可追溯，默认 `pending` 不自动确认 | `C2：AI 事实候选记录完整来源与主体字段` PASS |
| R-03 同意（延续 B） | `process()` 执行前 `assertRunAuthorized()`；撤销覆盖 `queued/processing/failed`；`recoverExpired()` 不复活已撤销任务 | 撤销后不再向 Provider 发数据 | Phase B `B-R03` + 全量 PASS |

## 3. 质量证据

- PHP lint：`find app bin database tests config public -name '*.php' … php -l` rc=0（255 文件）。
- PHPStan：`vendor/bin/phpstan analyse --no-progress` → `[OK] No errors` rc=0（Level 5 未变，未新增 baseline）。
- 集成测试：`bash bin/test.sh` → **138 通过 / 0 失败**（原 133 + Phase C 5 项）。
- 迁移：`bin/migrate-v2.php --status` 31 个版本全部 applied；重复执行 rc=0。
- 本阶段本地数据库：MariaDB 11.8.9；MySQL 8.4 + PHP 8.4/8.5 矩阵由 Phase E 远程 CI 验证。

## 4. 未关闭项（OPEN，进入 E 或后续专项）

| ID | 说明 | 原因/下一步 |
|---|---|---|
| R-10 | 步骤状态仍未逐步置 `processing`；成功/失败批量改写步骤 | 需要逐步执行模型，改动面较大；不在本次最小修复内，列 E 前评估 |
| R-11（部分） | Provider 总截止时间/内部超时仍未实现；仅修错误分类 | 需配置化 deadline，列 E |
| R-23 | `ai_workflow_completed` 通知类型已声明但工作流代码未 `emit()`，测试手动补发 | 需通知去重设计，列 E |
| C10（部分） | 自由文本 `text` 未设上限；公开家族 AI 草稿可见性未按策略收紧 | 需业务确认公开可见性预期，列 E/人工决策 |
| 并发专项 | 10 Worker 同领取、并发同 key 创建、10 并发订单确认未做真实并发压力测试 | 当前为代码证据 + 串行测试；列 E 并发专项 |

本阶段未修改生产配置、未调用真实付费 AI Provider、未触碰生产数据。
