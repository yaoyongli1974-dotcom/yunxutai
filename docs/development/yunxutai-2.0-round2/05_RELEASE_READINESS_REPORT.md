# Round2 Phase 5：发布就绪报告

## 修改/新增文件
- 修改：`app/Services/ArchiveService.php`（J-01/J-02/J-03）、`bin/doctor.php`（L-02 健康指标）、`.github/workflows/ci.yml`（K-01 矩阵）、`tests/run.php`。
- 新增：`bin/restore-check.php`（L-03 备份完整性核验）、本报告。

## 关键函数
- `ArchiveService::update()`：`SELECT ... FOR UPDATE` + 事务内版本计算/快照/更新/审计，版本唯一约束由 `(archive_id, version)` 保证。
- `ArchiveService::addFile()`：数据库写入与审计在同一事务；失败时补偿删除已落盘文件（文件系统与 MySQL 无法共享事务，明确为补偿策略而非分布式事务）。
- `requestDelete()/approveDelete()`：条件 UPDATE 状态机（active/archived → delete_requested → deleted/active），重复申请 409、重复审批 404，审计含状态流转。
- `doctor.php`：新增上传目录可写、磁盘剩余空间、AI 队列积压、备份目录、Worker 日志时间等健康指标，不打印敏感配置。
- CI：PHP 8.4/8.5 × MySQL 8.4/MariaDB 11 四组合，迁移重复执行、secret 扫描。

## 测试与证据
- `bash bin/test.sh`：**126 通过 / 0 失败**（Phase 0~4 的 125 项全保留，新增 1 项 J 事务/状态机）。
- `php -l` 全量通过；`node --check` 全量通过。
- 备份恢复核验：对生产迁移前备份执行 `php bin/restore-check.php`，结果 `OK sha256=92004de1…010e bytes=40578 tables=24 inserts=56`，证明 gz 可读、结构与数据完整。
- doctor：0 阻断项（环境警告仍为 APP_DEBUG/APP_ENV/上传限制）。

## 未决（不得宣称已验收）
- 真实 GitHub Actions 矩阵运行结果尚未取得（本次仅提交配置）。
- 完整数据库恢复演练（导入隔离库 + 应用启动 + 数据核对）只完成备份完整性核验，未完成全量恢复。
- 并发 Worker/限流的真实多进程 CI 任务尚未加入 Actions。
- 生产部署与迁移需用户授权，本轮未执行。

## 门禁
本地实现与测试已完成，但运维验收项未全部闭环；继续补齐 06/07/08 文档后做最终汇总。
