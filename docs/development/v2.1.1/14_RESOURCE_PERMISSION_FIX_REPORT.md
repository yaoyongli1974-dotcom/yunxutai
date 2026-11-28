# Phase 5 — 资源级权限与隐私治理修复报告

基线：Phase 4 提交 `1ce606b`。分支 `feature/v2.1.1-production-hardening`，未 push、未动生产。

## 阶段名称
资源级权限与隐私治理专项（P0）：修复 Phase 0 确认的口述会话/片段 API 跨家族越权与公开家族转录导出越权。

## 修改文件
- `app/Api/Controllers/FamilyV2Controller.php`
- `tests/run.php`
- 本报告

## 修改函数
- 新增私有助手 `assertOralMember(?array $user, int $familyId)`：要求平台 admin 或当前用户在目标家族有 active 成员角色，否则 404，不泄露资源存在性。
- 新增私有助手 `assertOralExport(?array $user, int $familyId)`：要求 `Policy::can('data.export', ['family_id' => …, 'resource' => null])`，即家族 owner/admin，否则 404。
- 新增私有助手 `oralSessionForMember(?array $user, int $sessionId)`：按 ID 读取会话后复核目标家族成员权限，null 或无权统一 404。
- `oralSessions()`：先按路径 `family_id` 做成员校验，再返回会话列表。
- `oralSession()` / `sessionSegments()`：改走 `oralSessionForMember()`，不再只凭会话 ID 裸读。
- `exportSession()`：先解析会话归属家族，再执行 `assertOralExport()`；public 家族不再自动允许任意认证用户导出。

## 数据库迁移
无新增迁移。

## API 变化
仅收紧权限，URL、请求方法、响应结构不变：跨家族读取会话列表/详情/片段、非 owner/admin 导出会话转录均返回 404。

## 前端变化
无；小程序继续调用同一 API，未授权场景收到统一 404。

## 安全影响
- 消除口述历史转录的横向越权（用户 A 读取用户 B 家族的受访录音文字）。
- 消除 public 家族下凭会话 ID 导出他人转录的风险。
- 权限判断统一在服务端，前端隐藏按钮不作为安全边界；拒绝时不区分“不存在/无权”。

## 测试
新增 2 项 P5 回归测试：
1. 跨家族用户读取 `/api/v1/families/{id}/ai/sessions`、`/api/v1/ai/sessions/{id}`、`/api/v1/ai/sessions/{id}/segments` 均 404。
2. public 家族下非成员导出转录 404，owner 200。

`bash bin/test.sh` rc=0，**162 通过 / 0 失败**（Phase 4 的 160 + 2），日志 `/tmp/opencode/v2.1.1/phase5/integration.log`。
门禁：PHP lint、node --check、secret-scan、PHPStan Level 5 `[OK] No errors`、迁移 36/36 applied + 重复执行、doctor 0 阻断、`git diff --check` 全部 rc=0。

## 未解决问题
- 口述会话未单独建模 `oral_history.view` 能力；当前复用有效成员身份。若未来 guest 需只读访问，应新增显式能力并写矩阵。
- API 仍无 `api-admin` 路由；审核/数据请求等管理流程仅 Web 可用（Phase 0 已记录，非本阶段 P0）。
- 分享链接未纳入本阶段范围。

## 下一阶段
Phase 6：订单、AI 任务、通知与删除队列的幂等/事务/真实并发一致性。
