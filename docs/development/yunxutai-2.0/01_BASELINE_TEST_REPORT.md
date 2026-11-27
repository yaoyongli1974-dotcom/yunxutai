# 云叙台 2.0 · 基线测试报告（阶段 A）

日期：2026-11-27（本地环境）
代码版本：`4e7365238c6478defb494f16265abc85e08dc69f`（main，工作树干净）
原则：基线与 2.0 开发使用同一代码快照；任何后续回归都必须与本报告对照。

## 1. 汇总

| 项目 | 命令 | 结果 |
|---|---|---|
| 完整集成测试 | `bash bin/test.sh` | **71 通过 / 0 失败 / 0 跳过**（退出码 0） |
| PHP 语法检查 | `find app bin config public database -name '*.php' -exec php -l` | 全部通过，0 错误 |
| 上线自检 | `php bin/doctor.php` | 21 项通过，4 项警告，0 阻断；退出正常 |
| 数据库结构检查 | `bin/doctor.php` 的“核心数据表完整” | 通过（本地 `jidian` 库） |
| API 测试 | 包含在 `tests/run.php` 第 47–55 项 | 9 项通过 |
| 权限 / 越权测试 | 包含在 `tests/run.php` | 通过（见第 3 节） |
| 上传与媒体访问测试 | 包含在 `tests/run.php` | 通过（见第 3 节） |
| AI 任务测试 | 包含在 `tests/run.php`（含 AI 收费订单） | 通过（见第 3 节） |
| 管理后台测试 | 包含在 `tests/run.php`（2FA/核验/AI 设置等） | 通过 |

**结论：基线无失败项，符合“基线测试全部有明确结果”的验收条件，允许进入阶段 B。**

## 2. 测试方法与环境

- `bin/test.sh` 自动创建全新测试库 `jidian_test`（`php bin/install.php --fresh --demo`），避免污染开发库。
- 启动 PHP 内置服务器 `http://127.0.0.1:8110`，强制 `APP_ENV=testing`、`AI_ENABLED=true`、`AI_PROVIDER=demo`、`AI_SYNC=true`。
- 测试运行器：`php tests/run.php`（过程式 `test()` 注册，无 PHPUnit）；测试结束自动关闭服务器。
- 本地 PHP 8.4.28 / MariaDB 11.8.9；生产 PHP 8.5.x（1Panel 运行时），生产配置不在本基线范围内。
- 测试日志：`storage/logs/test-server.log`；邮件驱动为 log（SMTP 不可达时写日志并返回 false，属预期行为，不是失败）。

## 3. 测试项覆盖清单（71 项，节选与分类）

### 3.1 核心与账号（含认证/2FA）
- TOTP 算法自洽与 RFC 6238 向量；未启用 2FA 的管理员登录行为不变；2FA 登录闸门、限流与停用流程；管理员重置他人 2FA 与权限边界。
- 账号注册/登录/会话、密码与资料修改等基础流程（详见 `tests/run.php` 前段）。

### 3.2 纪念馆权限与越权（CONFIRMED 覆盖）
- 私密馆对无权限请求 404；成员管理越权 403 / 馆不存在 404 / 未登录 401；馆主查看/邀请/角色/移除/作废；claim 提交与越权拒绝。
- 核验门槛、邀请有效期、角色边界相关用例均在 71 项中通过。

### 3.3 API（9 项，CONFIRMED）
- API 列表与公开详情；私密馆 404；祭奠写入与留言待审；签名媒体有效 200 / 篡改 404；举报与目标可见性；manage 创建一律私密草稿；成员管理三组用例；claim 越权。

### 3.4 上传与媒体
- 上传文件类型/大小校验、媒体可见性、签名 URL 有效性、篡改签名拒绝。

### 3.5 AI 与通知
- AI 收费订单（收款确认制）；Notify log 驱动邮件可解析、非法目标与 none 驱动不写日志、日志目录缺失安全失败、API 邀请邮件日志不重复、SMTP 不可达安全失败。

### 3.6 基础设施与 SEO
- RESP 客户端异常、RedisCounter / RedisSessionHandler / RateLimiter（Redis 与 MySQL 驱动）、无 REDIS_URL 回退。
- sitemap 静态页、robots.txt、canonical、私有页 noindex、www 301、首页标题与 OG 元信息。

## 4. `bin/doctor.php` 明细

通过 21 项：PHP 版本、pdo_mysql/mbstring/fileinfo/gd/openssl、APP_KEY、APP_URL、邮件驱动、storage 各子目录可写、数据库连接、核心表完整、存在管理员、公开纪念馆数量、上传限制、AI 工作室启用。

警告 4 项（CONFIRMED，均为本地/环境配置，不是代码缺陷）：
1. `APP_DEBUG=true`，生产应为 false。
2. `APP_ENV=local`，生产应为 production。
3. `upload_max_filesize=2M` < 应用照片上限 20MB。
4. `post_max_size=8M` < 照片上传所需。

无阻断项。生产环境对应配置需单独核验（NEEDS_VERIFICATION，见 `00_CURRENT_CODEBASE_AUDIT.md` 第 2/7 节）。

## 5. 失败、跳过与解释

- 失败：0；跳过：0。
- 测试运行期间出现 `notify failed: SMTP 连接失败：Connection refused (111)`，这是测试环境故意不启用 SMTP 时 `Notify` 的预期日志输出，对应用途是“SMTP 不可达时返回 false 且不抛错”，该测试通过。
- 未发现需要在基线阶段解释的失败项。

## 6. 基线保真要求（进入阶段 B 的约束）

1. 在新增任何表/路由/服务前，重新运行 `bash bin/test.sh`，确认仍是 71 通过 / 0 失败。
2. 每个阶段结束必须重新运行全量测试；**不得删除、改写或降低原有 71 项测试的标准**。
3. 新增测试沿用 `tests/run.php` 的 `test()` 风格，按模块分组；`tests/Integration/*`、`tests/Security/*` 目录可在后续阶段按现有运行器组织方式建立。
4. 本报告的基线 SHA 为 `4e73652`；若后续基线变化，需在本文件追加新基线记录并说明原因。
5. 生产数据库不得作为测试目标；测试始终使用 `jidian_test`。
