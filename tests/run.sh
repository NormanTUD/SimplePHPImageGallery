#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8765}"
DOCROOT="${TMPDIR:-/tmp}/simplephpgallery-ci-$$"
ARTIFACTS="$ROOT/tests/artifacts"
BASE_URL="http://127.0.0.1:${PORT}"

rm -rf "$DOCROOT"
mkdir -p "$DOCROOT" "$ARTIFACTS"

cleanup() {
	local status=$?
	kill "$SERVER_PID" 2>/dev/null || true
	wait "$SERVER_PID" 2>/dev/null || true
	if [ "$status" -ne 0 ] && [ -f "$DOCROOT/server.log" ]; then
		cp "$DOCROOT/server.log" "$ARTIFACTS/server.log"
	fi
	rm -rf "$DOCROOT"
}
trap cleanup EXIT

php "$ROOT/tests/create_fixtures.php" "$DOCROOT"

PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" php \
	-d opcache.enable_cli=0 \
	-d display_errors=1 \
	-d error_reporting=E_ALL \
	-S "127.0.0.1:${PORT}" \
	-t "$DOCROOT" \
	>"$DOCROOT/server.log" 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 100); do
	if curl -fsS "$BASE_URL/index.php" >/dev/null 2>&1; then
		ready=1
		break
	fi
	if ! kill -0 "$SERVER_PID" 2>/dev/null; then
		cat "$DOCROOT/server.log" >&2 || true
		exit 1
	fi
	sleep 0.1
done

if [ "$ready" -ne 1 ]; then
	echo "PHP test server did not become ready" >&2
	cat "$DOCROOT/server.log" >&2 || true
	exit 1
fi

php "$ROOT/tests/http_tests.php" "$BASE_URL" "$DOCROOT"
BASE_URL="$BASE_URL" node "$ROOT/tests/ui_tests.mjs"
