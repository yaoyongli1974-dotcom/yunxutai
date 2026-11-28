# CI Failure Baseline（CI 红灯专项 Phase 0）

## 1. Repository
- Repository: yaoyongli1974-dotcom/yunxutai
- Branch: main
- Current HEAD: `1592d879ebe60179d28d0a623262771e057c412c`
- Latest remote commit: `1592d879ebe60179d28d0a623262771e057c412c`
- Working tree: clean（git status --short 无输出）
- Note: 失败 commit 就是最新 commit，失败后没有新修复提交。

## 2. Failed Workflow
- Workflow name: CI
- Run ID: `46103003877`
- Commit: `1592d879ebe60179d28d0a623262771e057c412c`（「CI: gitleaks allowlist 排除测试夹具与文档占位」）
- Trigger: push to main
- Run URL: https://github.com/yaoyongli1974-dotcom/yunxutai/actions/runs/46103003877
- Duration: 43s

## 3. Failed Matrix
- PHP: 8.4、8.5（均失败）
- Database: mysql:8.4、mariadb:11（均失败）
- OS: ubuntu-latest

## 4. Failed Job
- Job name: `tests (8.4, mysql:8.4)`、`tests (8.4, mariadb:11)`、`tests (8.5, mysql:8.4)`、`tests (8.5, mariadb:11)`（4/4 失败）

## 5. Failed Step
- Step name: `Secret scan (local patterns)`（`bash bin/secret-scan.sh`）
- 已通过：`Set up job`、`Initialize containers`、`checkout`、`setup-php`、`setup-node`、`Install system tools`、`Wait for database`、`Secret scan (gitleaks)`
- 未执行（因前序失败）：PHP syntax check、Miniprogram JS syntax check、Static analysis (PHPStan)、Full integration suite、Migration status/repeatability、Doctor、Secret scan (no credentials in tracked source)
- 关键：gitleaks 步骤本轮**通过**（`.gitleaks.toml` allowlist 生效），红灯来自自定义本地扫描脚本。

## 6. Error Log
首个有意义错误（4 个 job 完全一致）：
```
发现疑似凭据：
app/Core/Kv/RespClient.php:13: *   redis://[:password@]host[:port][/db]
app/Core/Kv/RespClient.php:43:            throw new KvClientException('REDIS_URL 格式无效，应为 redis://[:password@]host[:port][/db]');
##[error]Process completed with exit code 1.
```
最后错误：`Process completed with exit code 1.`

## 7. Root Cause Classification
- [x] Custom secret scan（`bin/secret-scan.sh`）误报
- [ ] CI configuration / GH Actions environment / PHP compatibility / Composer / PHPStan / Gitleaks / MySQL / MariaDB / Migration / Integration tests / Doctor / Other

## 8. Initial Root Cause
`bin/secret-scan.sh` 的 URL 内嵌凭据正则 `[a-z]+://[^/@:[:space:]]+:[^@/[:space:]]+@` 会把 `redis://[:password@]host[:port][/db]` 这种**文档占位说明**识别为凭据：`[` 与 `:` 被当作凭据字符，命中 `:password@`。两处命中分别是 `RespClient.php` 的类注释与异常提示文本，均为占位格式说明，不是真实密钥；gitleaks 已通过，说明仓库无新增真实泄漏。此前只收敛了 `docs/`、`tests/` 与本地 `.env`，未排除这种方括号占位形态。

## 9. Reproduction
本地（`/mnt/data/project/jidian`）：
```
bash bin/secret-scan.sh
```
结果与 CI 一致：命中 `app/Core/Kv/RespClient.php:13` 与 `:43`，退出码 1。CI 日志见 Run URL。

## 10. Proposed Fix（最小修复，Phase 1 待批准）
仅修改 `bin/secret-scan.sh`：收紧 URL 凭据正则，要求凭据段首字符不是 `[`，且凭据内容不含 `[`/`]`，例如把 URL 分支改为匹配 `[a-z]+://[^/@:\[\][:space:]]+:[^@/\[\][:space:]]+@` 的等价 POSIX 表达；保留私钥块、`ghp_`/`sk-`/`AKIA`、`WX_SECRET=` 实值检测与 `git ls-files` 受控范围，不删除扫描规则、不排除业务目录、不扩大 gitleaks allowlist。
回归测试计划：
1. 用真实样例 `redis://alice:s3cr3t@example.test:6379/0` 验证仍能命中；
2. 用占位样例 `redis://[:password@]host[:port][/db]`、`https://user:pass@provider.test/ocr` 验证不命中；
3. `bash bin/secret-scan.sh` 退出 0；
4. 本地按 CI 顺序全跑：PHP lint、composer validate/install、PHPStan、gitleaks、secret-scan、JS `node --check`、`bash bin/test.sh`、migration fresh/status/repeat/checksum、doctor；
5. 推送后 watch 四组合（PHP 8.4/8.5 × MySQL 8.4/MariaDB 11）全绿。

## 11. 影响范围 / 风险
仅影响 CI 自定义 secret 扫描脚本的误报判定；不改业务代码、不改数据库矩阵、不改测试预期、不降低 PHPStan level、不改 gitleaks allowlist。风险：正则过宽会漏报真实 URL 凭据，需通过上述正反样例验证。

## 12. 待人工批准
Phase 0 仅诊断，未修改任何代码/CI/测试/迁移。请批准后进入 Phase 1 执行上述最小修复。
