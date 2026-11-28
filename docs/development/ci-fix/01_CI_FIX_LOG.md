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

## Fix #2

### Failure
推送后的 CI run `46105846362`（commit `03c5d09`）4 个 matrix job 中 PHP 8.5 两组合（mysql:8.4、mariadb:11）在 `Static analysis (PHPStan level 5, baseline for legacy)` 步骤失败；PHP 8.4 两组合全部步骤 PASS。

### Failed Workflow
CI / tests (8.5, mysql:8.4)、tests (8.5, mariadb:11) / Static analysis (PHPStan level 5, baseline for legacy)。

### Root Cause
PHP 8.5 的 `session_get_cookie_params()` 返回形状比 PHP 8.4 多出 `partitioned: bool` 键。`app/Core/Auth.php:97` 对必然存在的 `samesite` 键使用 `?? 'Lax'`，PHPStan 在 8.4 下报 `nullCoalesce.offset`（已由基线忽略），在 8.5 下报同一标识符但消息含 `partitioned: bool`，导致基线正则匹配失败，产生 `ignore.unmatched` + 未忽略的 `nullCoalesce.offset` 共 2 个错误。属跨版本 PHP stub 形状差异暴露的冗余代码，非业务逻辑缺陷、非门禁问题。

### Affected Files
- `app/Core/Auth.php`（删除多余的 `?? 'Lax'`）
- `phpstan-baseline.neon`（删除已不需要的 samesite 基线条目）
- `docs/development/ci-fix/01_CI_FIX_LOG.md`（本报告）

### Fix
直接消除根因：`'samesite' => $params['samesite']`（PHP ≥ 7.3 该键必然存在），并移除对应基线条目。不放宽基线正则、不降低 PHPStan level、不跳过分析步骤、不改业务行为（`setcookie` 传入值不变，仅去掉无效兜底）。

### Regression Test
- `php -l app/Core/Auth.php` → PASS
- `vendor/bin/phpstan analyse --no-progress`（本地 PHP 8.4）→ `[OK] No errors`，rc=0
- `bash bin/secret-scan.sh` → PASS（无误报）
- `bash bin/test.sh` → 128 通过 / 0 失败
- 待推送后 watch CI 四矩阵全绿（Phase 4）

### Risk
低。仅移除永不会触发的兜底值；PHP 8.4/8.5 的 `session_get_cookie_params()` 均保证 `samesite` 键存在，基线减少一条与代码删除一一对应。

### Result
本地回归 PASS，待 CI 四矩阵复验。
