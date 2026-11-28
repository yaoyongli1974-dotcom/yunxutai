# Phase D — 部署与运维检查清单

基线：`cbc4042`；分支：`feature/v2.1.0-production-hardening`。
本清单只描述**待运维执行/确认**的事项；本轮未修改生产服务器、生产数据库、域名、DNS 或线上配置。

## 1. 环境与密钥

- [ ] `APP_ENV=production`（不可省略；配置层对 production + debug 已 fail-closed）。
- [ ] `APP_DEBUG=false`（Phase D 已把默认值改为 `false`；CI/测试通过显式 `APP_DEBUG=true` 覆盖）。
- [ ] `APP_KEY` 为强随机值且不等于 `dev-only-key-change-before-production`（Phase B 起非 local/testing 会直接拒绝启动）。
- [ ] HTTPS 与 `APP_URL` 一致；反向代理终止 TLS 时，如代理会正确覆盖 `X-Forwarded-Proto`，设置 `APP_TRUSTED_PROXY=true` 以下发 `Secure` Cookie；否则保持 `false` 并确认 PHP 侧能识别 HTTPS。
- [ ] `deploy/nginx.conf` 中安全响应头一致；静态资源绕过 PHP 时启用 CSP 示例（Phase D 以注释形式加入，避免与应用层 CSP 重复冲突），并验证 `X-Content-Type-Options`、`Referrer-Policy`、HSTS。

## 2. 代码与数据库发布

- [ ] 合并 `feature/v2.1.0-production-hardening` 到发布分支前完成 Phase E 验收（当前分支**未 push main、未发版**）。
- [ ] 部署代码后执行 `php bin/migrate-v2.php --status`，确认 32 个版本全部 `applied`（含 `20261128_001`~`005`）。
- [ ] 执行 `php bin/doctor.php`，要求 0 阻断；确认必需表检查覆盖 21 张表且无 `status <> 'applied'` 的迁移。
- [ ] 配置 `bin/archive-cleanup.php` 定时任务（如 cron，每 5–10 分钟 `--once`）；首次上线后观察 `archive_cleanup_queue` 中 `pending/processing/done/failed` 分布与 `audit_logs` 的 `archive.file_cleanup` 事件。
- [ ] 配置 `bin/ai-workflow-worker.php` 与（如使用旧链路）`bin/ai-worker.php` 的常驻进程/心跳监控；确认 AI Provider 凭据仅在服务端环境变量/后台设置中。

## 3. 备份与恢复

- [ ] 上线前 `php bin/backup.php` 生成备份，运行 `php bin/restore-check.php <backup-dir>` 并确认 rc=0（manifest 三产物 sha256/bytes 一致，SQL 含 CREATE TABLE 与 INSERT INTO）。
- [ ] 在非生产隔离库完成一次恢复演练：导入 → 行数核对 → `bin/doctor.php` → 关键业务访问 → 清理演练库；参照 `05_BACKUP_RECOVERY_REPORT.md`。
- [ ] 确认备份目录不在 Web 根、权限受限；`restore-check` **不是**完整恢复证明，演练结果需人工记录。

## 4. 安全与可观测性

- [ ] 确认审计日志脱敏生效（`Audit::sanitizeMeta()`）：邮箱、证件号、password/token/secret 类键、长文本均不出现明文。
- [ ] 确认错误响应不含堆栈/SQL/绝对路径；`X-Request-Id` 头与 API 错误顶层 `request_id` 可用于关联 Nginx/FPM/审计日志。
- [ ] 私有媒体响应为 `Cache-Control: no-store, must-revalidate`；签名 URL TTL 300s 且随可见性变化失效。
- [ ] 小程序 `config.js` 的 `BASE_URL` 指向已备案 HTTPS 域名；无 AppSecret/密钥硬编码。

## 5. 本轮未执行（需明确授权）

- 生产数据库迁移、生产文件清理、域名/DNS/Nginx 生产配置变更、关闭线上服务、创建正式 Release。
- 微信开发者工具/真机逐页走查与大规模性能数据集压测（见 `31_MINIPROGRAM_REPORT.md`、`32_PERFORMANCE_REPORT.md`）。
