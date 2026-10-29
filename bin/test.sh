#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${TEST_PORT:-8110}"
BASE_URL="http://127.0.0.1:${PORT}"
DB_NAME="${TEST_DB:-jidian_test}"

export DB_DATABASE="$DB_NAME"
export APP_URL="$BASE_URL"
export APP_DEBUG=true
export ADMIN_PASSWORD="TestAdmin@123"
export APP_ENV=testing
export AI_ENABLED=true
export AI_PROVIDER=demo
export AI_SYNC=true
export FFMPEG_BIN=/usr/bin/ffmpeg

echo "== 准备测试数据库 ${DB_NAME} =="
php bin/install.php --fresh --demo

echo "== 启动测试服务器 ${BASE_URL} =="
php -S "127.0.0.1:${PORT}" -t public > storage/logs/test-server.log 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT

for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null "$BASE_URL/" 2>/dev/null; then
        break
    fi
    sleep 0.2
done

BASE_URL="$BASE_URL" php tests/run.php
