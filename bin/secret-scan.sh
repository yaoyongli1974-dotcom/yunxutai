#!/usr/bin/env bash
# 只扫描受 git 管理的文件（自动排除 .env / storage / vendor 等未受控文件）。
# 占位文档与测试夹具中的示例凭据不算泄漏；gitleaks 负责全历史与更广规则。
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

PATTERN='[a-z]+://[A-Za-z0-9._~%-]+:[A-Za-z0-9._~%+=/-]+@'

HITS=$(git ls-files -z | grep -zZvE '^(docs/|tests/)' | xargs -0 -r grep -nE "$PATTERN" || true)
if [ -n "$HITS" ]; then
  echo "发现疑似凭据："
  echo "$HITS"
  exit 1
fi
echo "secret scan: 受控文件中未发现疑似凭据"
