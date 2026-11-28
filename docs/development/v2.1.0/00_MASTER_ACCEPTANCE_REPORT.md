# v2.1.0 生产级专项 — 总验收报告

分支：`feature/v2.1.0-production-hardening`；目标版本：**v2.1.0**；基线 `49fc14e94adeadcf4476873f8e19413ac16a30f9`；交付阶段：Phase 0 → E 全部完成。
标注约定：远程 CI 结果以 PR checks 为准；未取得前不宣称远程四矩阵全绿。

## 对任务书「最终总结报告」17 项的逐条回答

1. **本轮一共审查多少文件**：Phase 0 基线共 **377 个受控文件**（`app/` 209、`bin/` 11、`database/` 29、`miniprogram/` 61、`public/` 8、`tests/` 3、`.github/` 1 及其余配置/文档）；逐文件清单见 `00_BASELINE_AUDIT.md` 与 `01_ARCHITECTURE_MAP.md`。

2. **一共发现多少个问题**：**30 个确认问题**（P0 4 / P1 16 / P2 10），另有 **17 项待验证风险**（N-01…N-09 等）；Phase D 性能专项追加 6 项（P-01~P-06），均纳入风险登记册。

3. **P0 问题多少个**：**4**（R-01 APP_KEY 回退导致签名可伪造且无授权复核；R-02 纪念馆邀请无邮箱绑定；R-03 AI 同意未实时生效；R-04 归档删除同步清理不可回滚）。

4. **P1 问题多少个**：**16**（R-05~R-20 等，详见 `02_RISK_REGISTER.md`）。

5. **P2 问题多少个**：**10**（R-21~R-30，另含性能补充 P-01~P-06）。

6. **实际修复多少个**：30 个确认问题中，**完全关闭 24 项**，**部分修复 4 项**（R-11、R-16、R-20、R-30），**未修复 2 项**（R-10、R-21）。阶段分布：Phase B 6、Phase C 10、Phase D 5、Phase E 3（R-18/R-22/R-23）。

7. **哪些问题没有修复**：R-10（AI 步骤状态仍批量置位，不反映真实执行进度）、R-21（`20261027_verification.sql` 不在版本化 runner 且非幂等）。部分修复但未完成：R-11 内部总超时、R-16 细粒度迁移断言、R-20 原始字节哈希、R-30 边缘 CSP 实际启用。

8. **哪些问题需要人工确认**：生产迁移窗口与部署授权；残余风险书面接受（尤其 N-07 per-user 签名撤销、R-10/R-11/R-21/R-30）；生产 APP_KEY/APP_DEBUG/APP_TRUSTED_PROXY 配置；边缘 CSP 启用前的静态资源盘点；备份加密与异地存储；真机小程序走查。

9. **新增多少个数据库迁移**：**5 个**：`20261128_001_invitation_token_hash.php`、`002_ai_fact_provenance.php`、`003_archive_cleanup_queue.php`、`004_audit_request_id.php`、`005_performance_indexes.php`。全部追加型，未修改已发布迁移语义；32 个版本均 applied 且重复执行 rc=0。

10. **新增多少个测试用例**：基线 128 → 最终 **145**，净增 **17 项**（Phase B 5、Phase C 5、Phase D 5、Phase E 2）；另新增并发辅助脚本 `tests/concurrency/claim-once.php`，用于 10 Worker 真实并发领取断言。无测试删除/跳过/降级。

11. **哪些 API 发生变化**：无破坏性契约变更。行为/结构变化：签名媒体 TTL 900→300 且载荷绑定 visibility；API 401/403/未知错误信封顶层新增 `request_id`；邀请创建拒绝空邮箱；私有/成员媒体带身份时复核 Policy；人物列表响应形状保持不变（`is_deceased` 仅内部筛选后 unset）；小程序 `utils/api.js` 新增 `listOralSessions` / `publishExhibition` 方法。

12. **哪些页面发生变化**：Web：Dashboard 五步新用户引导、纪念馆媒体与档案详情的批量上传面板、归档文件 SHA 文案。小程序：新增 6 个页面（人物、谱系、时间线、展览、AI 工作流、邀请接受），家族详情/列表增加入口，401 静默续登接入。所有页面均保留原路径。

13. **哪些数据结构发生变化**：追加列/表/索引：`invitations.token_hash`、`memory_facts` 来源/主体 5 列、新表 `archive_cleanup_queue`、`audit_logs.request_id`、索引 `idx_person_family_deleted_id` 与 `idx_rel_family_id`；`schema.sql` 补齐 027 的 `lock_version/heartbeat_at/last_error_at` 以提高全新安装一致性。无删除列、无改字段语义。

14. **四矩阵 CI 是否全部通过**：本地已按 CI 顺序全量复验（PHP 8.4 本地 + MariaDB 11.8.9，145/0，所有门禁 rc=0）；远程四矩阵（8.4/8.5 × MySQL 8.4/MariaDB 11）最终 run `46120192213`（SHA `87105eb`）completed/success，4/4 job success，全部 step success，check run 注解 0，PR #1 四项 checks 全部 pass。详见 `91_RELEASE_READINESS_REPORT.md` §6。

15. **备份恢复是否完成真实演练**：完成**非生产环境**完整链路：备份生成 → 3 产物 sha256/bytes 校验（tables=56、inserts=19）→ 隔离库导入 → doctor rc=0 → 关键表行数核对 → 演练库删除。日志与目录见 `90_FINAL_TEST_REPORT.md` §5；生产库未触碰。

16. **是否存在遗留安全风险**：存在且已登记：N-07（签名无 per-user 撤销，仅可见性绑定 + 300s TTL + no-store）、R-10、R-11（内部超时）、R-21、R-30（边缘 CSP 未启用）、备份未内置加密/异地、真机与大规模性能未验证。详见 `93_UNRESOLVED_RISKS.md`。

17. **是否可以进入 v2.1.0 RC 阶段**：**可以，附条件**：远程 PR 四矩阵 CI 4/4 全绿、生产迁移与部署获得人工授权、上述 OPEN/PARTIAL 风险被书面接受。正式 Release 不在本阶段自动创建。

## 交付物索引

- Phase 0：`00_BASELINE_AUDIT.md`、`01_ARCHITECTURE_MAP.md`、`02_RISK_REGISTER.md`、`03_IMPLEMENTATION_PLAN.md`
- Phase B：`10_SECURITY_FIX_REPORT.md`、`11_AUTHORIZATION_TEST_REPORT.md`、`12_MEDIA_SECURITY_REPORT.md`
- Phase C：`04_DATA_RETENTION_POLICY.md`、`05_BACKUP_RECOVERY_REPORT.md`、`20_AI_WORKFLOW_REPORT.md`~`24_AUDIT_REPORT.md`
- Phase D：`30_USER_EXPERIENCE_REPORT.md`~`33_DEPLOYMENT_CHECKLIST.md`
- Phase E：`90_FINAL_TEST_REPORT.md`、`91_RELEASE_READINESS_REPORT.md`、`92_ROLLBACK_PLAN.md`、`93_UNRESOLVED_RISKS.md`、本文件

**最终原则复核**：未删测试、未降 PHPStan Level 5、未扩大忽略规则（仅清理过期条目）、未缩矩阵、未吞异常、未改测试预期适配错误代码、未用虚假成功、未触碰生产、未自动推送 main、未自动创建 Release。
