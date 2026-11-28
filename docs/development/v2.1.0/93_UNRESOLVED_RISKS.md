# Phase E — 未解决风险与待验证清单

标注：**OPEN**（未修复）/ **PARTIAL**（部分修复）/ **NEEDS_VERIFICATION**（需环境或人工确认）。

## 1. 任务书内确认问题残余

| ID | 级别 | 状态 | 说明 / 下一步 |
|---|---|---|---|
| R-10 | P1 | OPEN | AI 步骤状态仍批量置位，不反映真实执行进度；需逐步推进并保留旧步骤兼容 |
| R-11 | P1 | PARTIAL | 错误分类与不可重试语义已修；`process()` 内部总超时/截止时间未实现；provider HTTP timeout 仍为配置项 |
| R-16 | P1 | PARTIAL | 本轮已把迁移 checksum 断言扩展到全部文件；细粒度“每条迁移独立断言与预期表结构”仍未完成 |
| R-18 | P1 | CLOSED | `catch (Throwable)` → `catch (\Throwable)`，过期 PHPStan baseline 2 条已清理 |
| R-20 | P1 | PARTIAL | UI/文案改为“入库文件 SHA-256”；哈希仍是 JPEG 重编码后的副本，未保留原始字节 |
| R-21 | P1 | OPEN | `20261027_verification.sql` 不在版本化 runner 且非幂等；需人工决定纳入独立迁移或标注一次性脚本 |
| R-23 | P1 | CLOSED | AI 完成后发一条站内通知，按 link 去重，失败只写错误日志 |
| R-30 | P2 | PARTIAL | Nginx 模板加入注释示例 CSP；生产边缘启用需运维盘点静态资源后执行，避免阻断业务 |
| N-07 | 残余 | OPEN | 媒体签名绑定可见性 + TTL 300s + no-store，但无 per-user 绑定；成员级撤销依赖下次签名生成或 Policy 复核（有身份时） |
| R-22 | P1 | CLOSED | `TEST_DB` 前缀限制与服务器探测失败即退出 |

## 2. 验证环境限制

- **远程四矩阵 CI**：本轮先完成本地复验；远程结果以 PR checks 为准，未取得前不声称全绿。
- **真机/微信开发者工具**：6 个新页面已通过 `node --check` 与页面注册断言，但未做真机布局、触控、网络异常走查。
- **大规模性能数据**：未生成 1000 人物 / 10000 关系 / 5000 档案数据集；索引仅通过小数据 `EXPLAIN` 验证。
- **订单并发确认**：`confirm()` 有事务 + FOR UPDATE，但 10 路 HTTP 并发确认需管理员会话/CSRF，未自动化。
- **故障注入**：`RateLimiter::blocked()` fail-closed 与 2FA UA/IP 绑定已改代码，未做存储停机/跨 UA 自动化。
- **生产操作**：未执行生产迁移、未部署、未改生产配置、未发正式 Release。

## 3. 运维/合规残余

- 备份产物未内置加密与异地存储；文档已明确要求运维侧处理。
- 恢复演练为完整性校验 + 隔离库导入 + doctor + 行数核对，不是全业务恢复验收。
- `APP_DEBUG` 默认 false；生产需显式配置 APP_ENV/APP_KEY/APP_TRUSTED_PROXY，否则 fail-closed。

## 4. 进入正式发布前的门槛

1. PR 四矩阵 CI 4/4 全绿，无隐藏失败/跳过。
2. 上述 OPEN/PARTIAL 项由项目负责人书面接受或排期修复。
3. 生产迁移窗口与回滚方案获批，按 `33_DEPLOYMENT_CHECKLIST.md` 执行。
4. 真机小程序走查与边缘 CSP 启用完成（或接受残余风险）。
