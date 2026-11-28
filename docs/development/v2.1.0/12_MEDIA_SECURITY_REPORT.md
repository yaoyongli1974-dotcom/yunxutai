# 云叙台 v2.1.0 — Phase B 媒体安全报告

分支：`feature/v2.1.0-production-hardening`  
范围：签名 URL、媒体授权、缓存策略、邀请附件边界。归档文件物理清理（R-04）按人工决策留到 Phase C。

---

## 1. Phase 0 已核实事实（未改变）

- 存储根目录位于 Web 根之外：`storage/uploads/`、`storage/verifications/`（`config/config.php:89-93`；`deploy/nginx.conf:15,31-38,58`）。
- 上传使用 PHP 提供的文件大小、MIME 白名单、白名单扩展名、随机文件名、0640 权限（`app/Core/Storage.php:19-75,83-141`）。
- Web 媒体下载经 `Policy::canViewMedia()`（`app/Controllers/MediaController.php:17-45`），响应 `nosniff`。
- 归档下载经 `ArchiveService::getFileVisible()` 和路径校验（`app/Services/ArchiveService.php:235-268`），写审计并使用 `attachment`。
- 已批准归档删除只改状态，不清理物理文件（`app/Services/ArchiveService.php:467-493`）——**Phase C 处理**。

## 2. Phase B 修复

| 项 | 修复前 | 修复后 | 证据 |
|---|---|---|---|
| HMAC 密钥 | `APP_KEY` 缺失时静默使用 `dev-only-key-change-before-production` | 非 local/testing（含 APP_ENV 未设置）直接 fail-closed | `config/config.php:4-15`；测试 `B-R01` |
| 签名载荷 | `media:{id}:{exp}`，TTL 900s | `media:{id}:{exp}:{visibility}`，TTL 300s；可见性下调旧签名失效 | `app/Core/MediaSign.php`；`app/Api/Presenter.php`；测试 `B-B1/B5` |
| 签名端点授权 | 只检查 `status='active'` | 有用户身份时复核 `Policy::canViewMedia()`；无身份客户端仍以签名为短时凭证 | `app/Api/Controllers/MediaController.php:36-49` |
| 缓存 | `private, max-age=300` | `no-store, must-revalidate` | 两个 MediaController；测试断言响应头 |
| Cookie Secure | 信任原始 `X-Forwarded-Proto` | 仅在 `APP_TRUSTED_PROXY=true` 时信任该头 | `config/config.php`、`app/bootstrap.php` |
| 邀请附件 | 纪念馆邀请链接无邮箱绑定 | 接受时必须匹配受邀邮箱；新建邀请必须有有效邮箱 | `B-R02` 测试 |

## 3. 验证结果

- 签名 URL：有效 200、篡改 404、过期 404（原有测试）全部通过。
- 新增：可见性下调后旧签名 404、私有媒体响应含 `no-store`（`B-B1/B5`）通过。
- 邀请越权：非受邀用户接受 404、受邀本人 200、空邮箱邀请 422（`B-R02`）通过。
- `node --check` 全部小程序 JS 通过；`secret-scan` 通过；`git diff --check` 通过。
- 全量集成测试：133/0。

## 4. 遗留与后续阶段

| 风险 | 状态 | 阶段 |
|---|---|---|
| R-04 归档删除后物理文件与 `archive_files` 未清理 | 未修复，需人工确认删除时机与保留义务 | C |
| R-18 `ArchiveService::addFile()` 的 `catch (Throwable)` 命名空间错误导致补偿死代码 | 未修复 | C |
| R-20 JPEG 重编码后计算 SHA-256，完整性表述失真 | 未修复 | C |
| N-06 polyglot / 文件名头注入 / storage_key 编码绕过 | 待验证 | C |
| 已签发签名在成员被移除后仍可用（无用户身份绑定） | 部分缓解：TTL 300s + 可见性绑定 + no-store；无 per-user 绑定仍为残余风险 | C/E 设计决策 |

## 5. 结论

Phase B 媒体安全的 P0 项（密钥回退、签名授权、缓存撤销延迟）已修复并通过回归；剩余项为数据治理与纵深防御，按计划进入 Phase C。

