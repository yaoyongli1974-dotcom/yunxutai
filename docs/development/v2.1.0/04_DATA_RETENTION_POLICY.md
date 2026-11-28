# 云叙台 v2.1.0 — 数据保留与删除策略（现状说明）

> 本文件只描述**当前代码实际行为**，不擅自设定法律/业务保留期限。需人工确认的策略项明确标为 OPEN。

## 1. 数据分类与当前处理

| 数据类型 | 当前存储 | 当前删除/清理行为 | 保留依据 |
|---|---|---|---|
| 用户账号 | `users`，`status` 可置 `disabled` | 无账号注销物理删除流程；禁用后身份在下一次请求失效（`Auth.php:23`、`ApiAuth.php:56`） | OPEN：需定义注销与保留例外 |
| 纪念馆 | `memorials`，含 `visibility/status/verification` | 管理端可停用/恢复；无自动物理删除 | OPEN |
| 家族/成员 | `families`、`family_members`、`family_invitations` | 成员可 `left/banned`；退出/移除后授权依赖角色查询失效 | OPEN |
| 家族人物 | `family_persons.deleted_at` | 软删除字段已存在，尚无统一物理清理任务 | OPEN |
| 档案 | `archives.status`：active/archived/delete_requested/deleted，`deleted_at` | 审批通过后进入 `deleted`；文件与 `archive_files` 行进入 `archive_cleanup_queue`，由 `bin/archive-cleanup.php` 幂等清理（Phase C） | 队列状态可追踪；完成删除后仅保留审计 |
| 媒体文件 | `storage/uploads`、`media`、`archive_files` | 媒体删除为硬删除（`Media::delete()`）；归档文件由清理 worker 删除 | 私有文件不进入 Web 根目录 |
| AI 工作流/生成日志 | `ai_workflows`、`ai_workflow_steps`、`ai_generation_logs` | 状态终态 completed/failed/cancelled；无定期清理 | OPEN |
| 口述历史/同意 | `oral_history_sessions.consent_status` | 撤销同意后新任务被拒绝，在途任务取消；已生成内容保留访问限制 | OPEN |
| 导出文件 | 由 `ArchiveService::export` 生成 | 当前无统一过期清理器 | OPEN |
| 审计日志 | `audit_logs`，含 `request_id`（Phase C） | 只追加，不做物理删除 | 最小化审计保留期需人工确认 |
| 备份 | `storage/backups/<时间>/`，默认保留 14 份 | 按目录名排序淘汰最旧备份（`bin/backup.php:104-118`） | 当前实现值，非法律结论 |

## 2. 归档删除流程（Phase C 已实现）

```text
requestDelete → delete_requested
approveDelete(true) → status=deleted + deleted_at
                    → 事务内 INSERT IGNORE 每个 archive_files.(file_id, storage_key) 入队
                    → 不删除物理文件/记录（可回滚）
bin/archive-cleanup.php --once
                    → 领取 pending（FOR UPDATE SKIP LOCKED，attempts+1）
                    → 复核 archives.status='deleted'
                    → 共享 storage_key 则只标 done，不删
                    → 否则 Storage::delete + 删除 archive_files 行 + 标 done
                    → 失败按 attempts 回 pending / failed，写审计
approveDelete(false) → 删除该档案非 done 队列项，可重新发起删除
```

幂等性：同一 `storage_key` 重复入队由 UNIQUE 约束去重；worker 重复运行不会二次删除或报错；缺失物理文件视为成功。

## 3. 备份与恢复保留

- `bin/backup.php` 生成 `manifest.json`（含每个产物 sha256/bytes）并保留最近 14 份。
- `bin/restore-check.php` 只做只读完整性校验：校验 manifest 中全部产物的 sha256/字节数，并统计 SQL 中的 CREATE TABLE / INSERT INTO；它**不是**完整恢复证明。
- 完整恢复演练必须在隔离的非生产库执行，步骤见 `05_BACKUP_RECOVERY_REPORT.md` 与 `docs/deployment.md`。

## 4. 待人工确认（OPEN）

1. 用户注销、账号数据最小化删除与法律保留例外。
2. 人物/纪念馆软删除后的物理清理时机与保留期。
3. AI 中间文件、导出文件、审计日志的保留期限。
4. 备份异地加密存储与保留策略（当前仅本地目录、无内置加密，见 `docs/security.md`）。

本阶段未删除任何生产数据，也未修改生产数据库。
