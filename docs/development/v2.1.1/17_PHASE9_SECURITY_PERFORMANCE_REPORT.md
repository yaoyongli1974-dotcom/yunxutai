# Phase 9 — 安全策略、数据治理、真机/性能与可观测性报告

基线：Phase 8 提交 `1a6bbf8`；分支 `feature/v2.1.1-production-hardening`。本阶段不 push、不动生产。

## 阶段名称
Phase 9：CSP 与 headers、用户数据生命周期治理、监控日志字段、性能容量、小程序真机清单与安全总报告。

## 修改文件
- `deploy/nginx.conf`：新增注释版 `Content-Security-Policy-Report-Only` 与 `Report-To` 示例（全部为注释，零运行时变更）。
- `docs/security.md`：新增「CSP 路线图」，列出现行 Enforce 指令、提议 Report-Only 策略、十项指令评估、收敛计划；明确本地未采集真实违规遥测。
- `docs/deployment.md`：新增边缘 Report-Only 回归清单，强调不重复下发 Enforce CSP。
- `docs/compliance.md`：新增 §9 数据治理收口（生命周期分层、导出保护、用户数据操作 implemented/OPEN、备份删除例外）。
- `app/Services/AiWorkflowService.php`：timeout / lease_expired / failed 审计补 `workflow_id/worker_id/error_code`；AI 完成通知 catch 的 error_log 加 `worker/error_code` 并以 sha256 替代原始异常文本。
- `bin/seed-performance.php`：新增隔离性能数据 seeder，仅允许 `*_perf` 数据库，带 clamps 与 marker 清理。
- `docs/development/v2.1.1/MINIPROGRAM_DEVICE_TEST_REPORT.md`：18 个真实注册页面、27 项真机清单全部标注「未执行」。
- `docs/development/v2.1.1/SECURITY_FINAL_REPORT.md`：P0 关闭与 OPEN/PARTIAL 风险台账。
- `docs/development/v2.1.1/PERFORMANCE_TEST_REPORT.md`：全规模合成数据、EXPLAIN、计时与未执行项。

## 安全影响
- 边缘 CSP 仍为建议示例，不产生第二次 Enforce header；避免与应用层 CSP 取交集导致业务白屏。
- 审计与日志增加可关联字段且对敏感异常文本做哈希，不新增敏感值落盘。
- 数据治理文档诚实区分已实现与 OPEN（短期导出链接、完整账户注销、分享撤销自动化等）。

## 测试与门禁
- `bash bin/test.sh` rc=0，**166 通过 / 0 失败**（无新增测试），日志 `/tmp/opencode/v2.1.1/phase9/integration.log`。
- PHP lint rc=0；composer validate --strict rc=0；secret-scan rc=0；node --check rc=0；`migrate-v2.php --status` rc=0（38 版本全 applied）；doctor rc=0（0 阻断，4 警告）；`git diff --check` rc=0。
- PHPStan：全量检查曾报 12 错，均为 `bin/seed-performance.php` 的 PHPDoc 形状错误（`limits()` 实际返回按选项名分组的数组）。仅修正 PHPDoc 为 `@return array<string,array{min:int,max:int}>`，未加 baseline、未降 level、未改逻辑；重跑 `[OK] No errors` rc=0。

## 性能容量（隔离合成数据）
`bin/seed-performance.php` 在隔离库 `jidian_perf` 达到：families=100、members=100、persons=1000、relations=10000、archives=1000、notifications=2000、workflows=200；seeder 0.621s 且重跑幂等。30 次平均计时：family list 0.534ms、person list 0.188ms（使用 `idx_person_family_deleted_id`）、genealogy 0.977ms（使用 `idx_rel_family_id`）、archives 0.302ms、notifications 0.300ms、workflow claim 0.105ms。防写保护确认 `jidian`/`jidian_test` 拒绝执行（rc=1），隔离库已删除。
披露：一次初始基准 heredoc 漏设 `DB_DATABASE`，只读访问了默认 `.env` 的 `jidian` 库（仅 SELECT/EXPLAIN，无写入）；已立即用 `jidian_perf` 重跑，所有报告数字来自修正后的运行。

## 未执行 / 需外部条件（不得写为已通过）
- 真机 Android / iOS / 微信开发者工具走查：全部 27 项「未执行」，仅有本地 `node --check` 与静态清单。
- 生产监控告警接入、CSP 违规遥测采集、Report-Only → Enforce 切换：未执行。
- 异地备份上传与 RPO/RTO 实测：未执行（Phase 7 仅隔离恢复演练，RPO/RTO 为估算）。
- 完整 HTTP 路径的大并发性能与真实外部 AI Provider：未执行。
- 小程序真机清单中的档案详情、图片/视频上传、分享：无对应页面，列为 OPEN。

## 下一阶段
Phase 10：最终质量门禁、CI 四矩阵、完整恢复演练复验、发布准备与 v2.1.1 是否可发布的结论。
