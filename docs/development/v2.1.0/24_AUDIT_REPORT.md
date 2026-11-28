# 云叙台 v2.1.0 — 业务审计与可观测性报告（Phase C）

## 1. 请求追踪（R-24）

| 层 | 改动 | 证据 |
|---|---|---|
| 入口 | `app/bootstrap.php`：非 CLI 请求读取 `X-Request-Id`，仅接受 `/^[A-Za-z0-9_-]{1,32}$/`，否则生成 32 hex；写入 `$GLOBALS['request_id']` 并发送响应头 | `C4` 测试：Web 响应含 `X-Request-Id` |
| 响应 | `app/Core/Response.php::send()` 补 `X-Request-Id`（已有同头不重复） | 同上 |
| 审计 | `app/Core/Audit.php::log()` 写 `audit_logs.request_id`；失败日志带 request_id | `C4` 测试：family.create 审计行 request_id 非空 |
| API 错误 | `app/Api/Api.php::error()` 顶层增加 `request_id`；`guard()` 日志带 ID | `C4` 测试：/api/v1/me 401 响应含顶层 request_id |
| 中间件 | `app/Core/Router.php` 的 api-auth/api-admin 401/403 改用 `Api::error()`，补齐 request_id | 全量 138/0 |
| 全局异常 | `app/error-handler.php` 日志与 API payload 带 request_id，非 debug 5xx 仍脱敏 | 代码复核 |
| 迁移 | `20261128_004_audit_request_id.php` 加列+索引；`schema.sql` 不重复写该列（由迁移单源维护） | 31 版本全 applied |

## 2. 审计事件与敏感信息（R-26）

当前 `Audit::log()` 已有 133 处调用，字段包含 actor、action、target、meta、ip_hash、request_id。本阶段**未新增脱敏**，以下为已识别且未关闭的残余风险：

- API Token 签发路径（`app/Api/Controllers/AuthController.php`、`app/Api/WechatLogin.php`）仍未写审计，Bearer 操作 actor 可能为空。
- meta 中仍有完整邮箱（`Admin/UserController.php`）、人物姓名（`GenealogyService.php`）、异常原文（`AiWorkflowService.php`）、原始设置负载（`MemorialService.php`），并在 `Admin/LogController.php` 展示。
- 这些改动涉及日志/隐私规则，需人工确认脱敏口径后实施，列 Phase D/E，不在本阶段擅自改写。

## 3. 异常处理

- API 统一信封为 `{"error":{code,message[,fields]},"request_id":…}`；内部异常不返回堆栈/SQL/绝对路径。
- `X-Request-Id` 允许外部传入合法值，便于用户反馈与日志关联；非法值被替换为服务端生成值。
- CLI（Worker/迁移）不生成 request_id，相关审计行该字段为 null，兼容旧数据。

## 4. 验证证据

- PHP lint rc=0；PHPStan `[OK] No errors` rc=0；secret-scan rc=0；`git diff --check` rc=0。
- `bash bin/test.sh` → 138 通过 / 0 失败，其中 `C4：Web/API 响应携带 request_id，审计记录可关联` PASS。
- 迁移状态 31/31 applied，重复执行 rc=0；Doctor rc=0（0 阻断）。

## 5. 开放项（OPEN）

1. API Token 签发/撤销的审计覆盖与显式 actorId。
2. 审计 meta 脱敏规则（邮箱/姓名/异常文本/配置负载）与后台展示策略。
3. Nginx 边缘 CSP 缺失（R-30）属 Phase D。
4. request_id 跨 Nginx/FPM/Worker 日志的端到端关联尚未做故障注入验证，列 E。
