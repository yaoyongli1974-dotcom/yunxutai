# 安全设计

## 威胁模型

重点防护：账号接管、越权访问私密纪念馆与媒体、存储型 XSS、恶意上传、批量骚扰/刷祭奠、活人被祭奠与隐私泄露、后台误操作、数据丢失。

## 身份与会话

- 密码：≥10 位，必须含字母与数字，bcrypt（`password_hash`），登录成功按需 rehash。
- 登录：IP 与邮箱双维度限流（5 次 / 15 分钟），失败信息不区分账号是否存在；`password_verify` 对不存在账号也执行一次哈希，降低时序差异。
- 会话：HttpOnly、SameSite=Lax、HTTPS 下 Secure、`use_strict_mode`；登录后 `session_regenerate_id(true)` 并轮换 CSRF token。
- 退出：POST + CSRF，清空会话与 cookie。
- 管理员：当前为单一 `role=admin`；生产建议增加独立后台域名/ IP 白名单，并强制开启二次验证（见下节）。

## 管理端二次验证

- 算法：RFC 6238 TOTP，SHA1 / 6 位 / 30 秒；密钥 20 随机字节，以 RFC 4648 Base32（无填充，32 字符）存入 `users.totp_secret`，`users.totp_enabled` 标记状态。实现见 `app/Core/Totp.php`，无外部依赖。
- 启用：账号页「二次验证」生成待确认密钥，暂存会话并展示 Base32 密钥与 `otpauth://` 地址，供身份验证器手动输入；必须输入当前动态码后才写入数据库，并记审计 `totp.enable`。当前不生成二维码图片，后续可接本地 QR 库。
- 登录闸门：密码校验通过且 `totp_enabled=1` 时**不建立登录态**，只写入 10 分钟有效的 `pending_2fa`（用户 id + 站内 `next`）；`/2fa` 校验成功后才 `Auth::login()` 并轮换会话与 CSRF。普通用户与未启用 2FA 的账号流程不变。
- 限流：`/2fa` 按 `2fa:{ip}:{uid}` 使用与密码登录相同的 5 次 / 15 分钟参数；错误码统一提示，不区分具体偏差时间片。
- 停用：需在已登录状态输入当前动态码；成功后清空密钥与标记，记审计 `totp.disable`。
- 管理员逃生通道：`POST /admin/users/{id}/totp/reset`（`admin` 中间件 + CSRF）清空目标用户的两个字段，记审计 `totp.reset`。用于手机丢失或更换设备，不修改密码。
- 密钥边界：`totp_secret` 不写入页面（启用后仅显示状态）、审计、错误日志或 API 响应；审计只记录动作、目标与邮箱。
- 信任模型：2FA 只保护交互式密码登录。**API Bearer Token 不受 2FA 影响**——持有有效 token 即可调用对应接口，token 本身即凭据，泄露时应通过 token 管理接口吊销。白名单边界还包括注册后自动登录、微信小程序登录与 `POST /api/v1/auth/token`（邮箱密码换 token）：这些通道均不经过 `/2fa`，管理员开启 2FA 也**不能**阻止仅凭密码换取 API token。若管理端 API 需要同等强度，应改为两步签发或禁止管理员使用该通道。管理员重置端点同样是高权限操作：能取得 `admin` 会话或直接改库的人可以绕过 2FA，因此仍需独立后台域名 / IP 白名单、强管理员密码、最小人数授权与操作审计；重置后应通知用户尽快重新绑定。若攻击者已控制管理员会话，2FA 不能阻止其重置他人或自己的二次验证。

## 授权

- 所有纪念馆数据访问先经 `Policy`：
  - 可见性：成员/管理员，或 `active` 且 `public|unlisted`。
  - 编辑：`owner|editor|admin`；管理：`owner|admin`。
  - 媒体：先可看馆，再按 `visitors|members` 判定。
- 所有按 id 操作的对象（留言、祭奠、媒体、文章、成员）都校验 `memorial_id` 归属，防止 IDOR。
- 私密/草稿/暂停馆对无权限者统一 404，避免泄露存在性。
- 前端隐藏按钮只是体验，服务端每个写操作独立鉴权。

## 输入与输出

- PDO 预处理，`EMULATE_PREPARES=false`；表名/排序字段不使用用户输入拼接。
- 所有模板输出 `e()`（`htmlspecialchars` UTF-8）；富文本当前按纯文本/段落处理，避免 HTML 注入。
- 请求体限制由 PHP/Nginx 控制；表单字段做长度与枚举校验。
- 重定向 `next` 仅允许站内相对路径。

## CSRF 与限流

- 所有 POST 校验 `_token`（`hash_equals`），失败 419。
- 限流键使用 SHA-256，含 IP/用户/动作维度：登录、注册、祭奠、留言、举报、数据请求、服务咨询、申领。
- 验证码为会话内算术题，一次性、10 分钟过期；用于注册与匿名举报。

## 文件上传

- 以 `finfo` 检测真实 MIME，白名单：JPEG/PNG/WebP/GIF/MP4/MP3/M4A/PDF。
- 照片 20MB、视频 50MB 上限；按 `kind` 校验 MIME 类别。
- 随机文件名 + 日期目录；保留原始文件名仅用于下载展示。
- 存储目录在 Web 根之外，文件权限 0640；输出经 `/media/{id}` 鉴权，响应带 `X-Content-Type-Options: nosniff` 与私有缓存策略。
- 不执行上传目录中的任何文件；Nginx 禁止该目录脚本执行（双保险）。

## 身份核验材料

- 新馆默认私密；只有 `verification_status=verified` 才允许 public/unlisted、发布与进入 sitemap。公开查询与 `Policy::canView` 双重过滤，防止直接改库绕过前端。
- 材料单独存放于 `storage/verifications`，不进入媒体库、无公开 URL；仅 `/admin/verifications/{id}/document` 在管理员鉴权后流式输出，`private, no-store` + `nosniff`，每次下载写审计。
- 类型限制 JPG/PNG/WebP/PDF，单文件 ≤10MB；随机文件名、按年月分目录、权限 0640。
- 驳回必须填写理由；通过/驳回均记录审核人、时间与说明。材料删除后核验标记失效，公开范围应回退私密。
- 备份包含核验材料时属于敏感个人信息，必须独立加密、异地保存、访问审批。
- 人工核验仍是薄弱环节：需建立双人复核/抽查、材料真伪核验与留存期限制度；系统不自动识别伪造证明。

## 举报分级

- `living_person` / `fake_person` → `urgent`；`privacy` / `insult` / `illegal` → `high`；其余 `normal`。
- 后台按状态→优先级→时间排序；紧急举报应 24 小时内核实并可先行暂停纪念馆。
- 举报表单只展示举报者有权看到的目标，避免通过举报入口探测私密媒体、待审留言或未发布文章。

## 内容治理

- 祭奠附言、留言、文章可配置先审后发；文章默认平台复审。
- 举报覆盖纪念馆/留言/祭奠/媒体/文章；匿名举报需验证码。
- 活人被祭奠：高优先级举报原因，支持暂停纪念馆并保留申诉/核验记录。
- 审计日志记录审核、暂停、成员变更、数据请求处理等敏感动作，不记录密码/完整证件信息。

## AI 生成与第三方服务

- AI 工作室仅馆主/管理员可用；任务必须校验 `memorial_id` 归属，任务详情仅创建者、馆主与管理员可看。
- 输入照片必须属于该纪念馆、状态正常、类型为图片；一次视频最多 8 张，避免资源耗尽。
- 输出统一写入 `storage/uploads/ai`，经 `/media/{id}` 鉴权输出；默认 `members` 可见，文章默认 `draft`，不自动公开。
- AI 生成内容按不可信输入处理：模板输出全部 `e()` 转义；家族档案为自包含 HTML，不执行用户脚本，下载响应带 `nosniff`。
- 远程调用只发送完成任务所需的照片与文本；**禁止发送身份核验材料、身份证号、联系方式、举报/申领材料**。
- API Key 只从环境变量读取，不写入页面、日志或数据库；错误信息对用户做脱敏，详细原因写服务端日志。
- `ffmpeg` 使用参数数组调用，不经 shell 拼接；超时与每日任务上限防止滥用。
- 第三方 AI 的数据留存、跨境与训练条款需在隐私政策披露；用户拒绝第三方处理时仍可使用本地 `demo` 模式的核心功能。
- AI 输出可能失真或虚构，必须由家属核对；平台不以 AI 输出替代身份核验或人工审核。

## 隐私

- 默认最小化：仅收集提供服务必需的信息；公开页不展示用户邮箱、IP。
- IP 仅存 HMAC（`APP_KEY` 派生），用于风控与审计，不存明文。
- 私密馆不进入列表、sitemap；公开馆可由馆主关闭搜索收录。
- 数据导出/删除/撤回授权需人工核验，防止他人冒用。

## CSP 路线图

### 当前生效策略（Enforce）

应用层在 `app/bootstrap.php:97` 对所有 PHP 响应发送一条 enforcing 的 `Content-Security-Policy`，指令如下（即当前唯一生效的策略）：

| 指令 | 当前值 |
| --- | --- |
| `default-src` | `'self'` |
| `img-src` | `'self' data: blob:` |
| `media-src` | `'self'` |
| `style-src` | `'self' 'unsafe-inline'` |
| `script-src` | `'self'` |
| `frame-ancestors` | `'self'` |
| `base-uri` | `'self'` |
| `form-action` | `'self'` |

未显式列出的 `connect-src` / `frame-src` / `object-src` 等回退到 `default-src 'self'`（注意 `object-src` 当前**不是** `'none'`）。边缘层 `deploy/nginx.conf` 默认不额外下发 enforcing CSP；只有需要为绕过 PHP 的静态响应/错误页补发时才使用与应用完全一致的同一条值。

### 提议的 Report-Only 策略

仅上报、不阻断，示例见 `deploy/nginx.conf` 的注释（占位端点须替换为真实收集地址）：

```
default-src 'self';
img-src 'self' data: blob:;
media-src 'self' blob:;
style-src 'self';
script-src 'self';
connect-src 'self';
frame-src 'self';
object-src 'none';
frame-ancestors 'self';
base-uri 'self';
form-action 'self';
report-uri /csp-report;
report-to csp
```

与当前策略的差异：去掉 `style-src` 的 `'unsafe-inline'`；`media-src` 增加 `blob:`（本地预览/AI 短片可能用到）；显式声明 `connect-src`、`frame-src`、`object-src 'none'`；加入 `report-uri` / `report-to` 上报端点（`report-to` 需配套 `Report-To` 响应头）。

### 评估步骤（采集破坏性为零）

在预发布/生产边缘以 `Content-Security-Policy-Report-Only` 启用上面的策略，逐项审查上报的违规，再决定是否收紧：

- `script-src`：确认为 `'self'`，排查是否有内联脚本、动态 `eval`/`new Function` 或第三方脚本；任何新增来源必须用 nonce/hash 或明确列入白名单并登记理由。
- `style-src`：目标是移除 `'unsafe-inline'`；排查模板内联 `style=` 属性与 `<style>` 块，逐步改为外部样式或 nonce/hash。
- `img-src`：确认 `'self' data: blob:` 覆盖头像/纪念馆封面/核验缩略图与 data URL，**不放开通配符或 `https:`**。
- `media-src`：确认 `'self' blob:` 覆盖 `/media/{id}` 鉴权流与本地预览；裁剪不必要的来源。
- `connect-src`：显式为 `'self'`；核对 AJAX、上传、AI 异步任务与统计上报是否同源，第三方 API 只允许已披露的域名且不得携带敏感参数。
- `frame-src`：显式为 `'self'` 或 `'none'`，确认后台与公开页没有需要嵌入的第三方框架。
- `object-src`：设为 `'none'`（禁插件/嵌入对象），确认没有 PDF/Flash 类 `<object>`/`<embed>` 依赖；PDF 走下载而非内嵌。
- `base-uri`：保持 `'self'`，防止 `<base>` 劫持相对 URL。
- `form-action`：保持 `'self'`，防止表单被劫持到外部域名。
- `frame-ancestors`：保持 `'self'`，与 `X-Frame-Options: SAMEORIGIN` 对齐；若需第三方嵌入须单独评估并留审批记录。

### 约束与收敛计划

- **禁止 `'unsafe-eval'`**：`script-src` 不得加入 `'unsafe-eval'`，除非有书面理由（例如某个不可替代的依赖必须动态求值），并经评审登记后使用 nonce/hash 或 `strict-dynamic` 限定范围。
- **减少 `'unsafe-inline'`**：当前仅 `style-src` 使用；计划按「外部样式表 → nonce/hash → 彻底移除」推进，每步都以 Report-Only 违规量降到零为门槛。`script-src` 已不含 `'unsafe-inline'`，保持不回退。
- **诚实说明**：本地开发/验收环境**没有采集到任何真实 CSP 违规遥测数据**；以上策略与评估项是基于现有代码与响应头的静态推导，尚未经过生产流量验证，必须在带真实流量的 Report-Only 阶段收集数据后再定稿。

### 切换到 Enforce

1. 边缘开启 `Content-Security-Policy-Report-Only`（示例见 `deploy/nginx.conf`），端点指向真实收集服务。
2. 观察足够长周期（覆盖主要用户路径与后台操作），按上节逐指令把业务相关违规降到零。
3. 回归 Web、管理端、小程序 API、上传、媒体与 AI 流程，确认无阻断。
4. 将收敛后的策略写入 `app/bootstrap.php:97` 的 enforcing 头；边缘继续只保留（或不下发）一致的安全头，**不要新增第二条 enforcing CSP**。
5. 保留 Report-Only 运行一段时间作为回归防线，确认无新增违规后再移除，并把 CSP 纳入发布检查表。

## 已知边界（MVP）

- 无邮件/短信验证，邮箱真实性依赖后续运营核验。
- 无自动图片内容识别；依赖人工审核与举报。
- 无 WAF/风控设备；高对抗场景需接入专业风控。
- 单管理员角色，无后台操作二次确认/双人复核。
- 本地磁盘单点；异地存储需运维侧配置对象存储/多副本同步，备份脚本本身不自动上传。
- 备份脚本支持可选 AES-256-CBC 加密（`BACKUP_ENCRYPTION_KEY`）；密钥必须与备份分开存放且不写入 Git，未配置时产物为明文。

## 事件响应

1. 发现泄露/越权：立即暂停相关纪念馆或账号，保留审计日志与访问日志。
2. 评估范围：涉及哪些用户、逝者资料、媒体；是否含敏感个人信息。
3. 按属地法规与平台政策通知受影响用户与监管部门。
4. 修复后复盘：补充测试用例、收紧权限、轮换密钥/会话。
5. 若涉及支付/第三方服务，按合同与合规要求同步处置。

## 安全测试建议

- 每次发布运行 `./bin/test.sh`。
- 额外人工检查：水平/垂直越权、私密媒体直链、上传 polyglot 文件、CSRF、开放重定向、XSS、限流绕过、举报滥用。
- 上线前进行依赖与配置基线扫描；PHP 版本跟进安全更新。
