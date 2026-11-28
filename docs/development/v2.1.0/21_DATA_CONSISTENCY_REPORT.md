# 云叙台 v2.1.0 — 数据一致性报告（Phase C）

## 1. 本阶段范围

Phase 0 未将人物合并、关系循环、谱系结构等列为 CONFIRMED 缺陷（多为 NEEDS_VERIFICATION），本阶段不擅自重写业务规则。实际修改集中在**事务边界、安装/迁移一致性、归档文件关联一致性**。

## 2. 事务与一致性改动

| 场景 | 证据 | 改动 |
|---|---|---|
| 管理员确认 AI 订单 | `app/Controllers/Admin/AiOrderController.php::confirm()` | 整体包在 `Db::transaction`；`SELECT status ... FOR UPDATE` 锁定订单后校验 `pending`，再创建 `ai_jobs`、同步处理、更新 `paid`、写审计；避免“订单已支付但任务未创建”的半状态 |
| 归档删除审批 | `app/Services/ArchiveService.php::approveDelete()` | 状态变更与清理队列入队在**同一事务**；审批通过后先入队而非直接删文件，保证数据库与物理文件状态可追踪、可回滚 |
| 归档拒绝删除 | `approveDelete(false)` | 同事务删除该档案非 `done` 队列项，允许后续重新发起删除；不误删已完成清理的记录 |
| 安装与迁移一致性 | `bin/install.php`、`database/schema.sql`、迁移 001–004 | `install.php` 执行完 schema.sql 后自动调用 `migrate-v2.php`；`schema.sql` **不重复**写由迁移添加的 `ai_workflows.lock_version/heartbeat_at/last_error_at`、`audit_logs.request_id`、`memory_facts` 来源字段，避免全新安装 + 迁移出现重复列（实测 027 曾因此失败，已修正） |
| 清理任务一致性 | `bin/archive-cleanup.php` | 领取任务时原子条件 UPDATE；处理前复核 `archives.status='deleted'`；共享 `storage_key` 仅标 `done` 不删；文件行与物理文件在同一逻辑步骤清理 |

## 3. 验证证据

- 全新安装：`bash bin/test.sh` 先 `php bin/install.php --fresh --demo`（自动迁移，31 个版本全 applied），再重复执行迁移 rc=0。
- 一致性测试：`C1：迁移列与元数据表结构完整`、`C4：归档审批入队 + worker 幂等清理 + 物理文件删除 + 二次运行稳定` 均 PASS。
- 全量集成：138 通过 / 0 失败。
- PHP lint rc=0；PHPStan `[OK] No errors` rc=0；`git diff --check` rc=0。

## 4. 未覆盖/待验证（OPEN）

| 项 | 状态 | 说明 |
|---|---|---|
| 人物合并关联完整性、重复合并阻止 | 未修改，NEEDS_VERIFICATION | Phase 0 未提供 CONFIRMED 证据；需专项测试后再改 |
| 人物关系重复/反向关系/循环约束 | 未修改 | 同上；不得为“看起来完整”而重写规则 |
| 跨家族人物/档案关联拒绝 | 已有测试覆盖部分路径 | 全量测试通过，未新增场景 |
| 事务中途异常回滚（订单/归档） | 代码使用 `Db::transaction` | 未做故障注入测试，列 E |
| 并发修改覆盖 | 未做真实并发 | 列 E 并发专项 |

本阶段未修改任何已发布 Migration 的历史语义；新增迁移编号为 20261128_002/003/004，与 Phase B 的 001 连续。
