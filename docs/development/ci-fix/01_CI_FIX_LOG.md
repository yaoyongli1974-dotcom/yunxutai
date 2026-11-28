# CI Fix Log

## Fix #1

### Failure
CI run `46103003877` 全部 4 个 matrix job 在 `Secret scan (local patterns)` 步骤失败，报 `app/Core/Kv/RespClient.php:13` 与 `:43` 的 `redis://[:password@]host[:port][/db]` 为疑似凭据。

### Failed Workflow
CI / tests (PHP 8.4|8.5 × mysql:8.4|mariadb:11) / Secret scan (local patterns)。

### Root Cause
`bin/secret-scan.sh` 的 URL 凭据正则 `[a-z]+://[^/@:[:space:]]+:[^@/[:space:]]+@` 将方括号占位 `[:password@]` 当作真实凭据，属自定义 Secret Scan 误报；gitleaks 步骤已通过，非真实泄漏。

### Affected Files
- `bin/secret-scan.sh`（仅正则）
- `docs/development/ci-fix/00_CI_FAILURE_BASELINE.md`、`01_CI_FIX_LOG.md`（报告）

### Fix
将 URL 凭据正则收紧为 `[a-z]+://[A-Za-z0-9._~%-]+:[A-Za-z0-9._~%+=/-]+@`，凭据段首字符必须为合法凭据字符且不含 `[`/`]`/空格。未改 allowlist、未排除业务目录、未降任何质量门禁。

### Regression Test
- 正样例：`redis://user:pass@host:6379/0` → 命中（POSITIVE_HIT=ok）
- 反样例：`redis://[:password@]host[:port][/db]` → 不命中（PLACEHOLDER_NOHIT=ok）
- `bash bin/secret-scan.sh` → 未发现疑似凭据，exit 0（SCAN_OK）

### Risk
仅影响本地自定义扫描规则；真实 `user:pass` 形式的凭据仍会被检出，未削弱检测能力。

### Result
PASS（本地针对性验证），待 Phase 2/3 全量回归与四矩阵验证。
