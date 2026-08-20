#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

echo "Starting Akunta development services..."
echo "Laravel API: http://127.0.0.1:8000"
echo "Accounting Web: http://127.0.0.1:5175"

if command -v bun >/dev/null 2>&1; then
    exec bun run dev
fi

echo "Bun is not available; starting the services with PHP and Node directly."

(cd apps/accounting && php -S 127.0.0.1:8000 -t public) &
api_pid=$!
(cd apps/accounting-web && node node_modules/vite/bin/vite.js dev --host 127.0.0.1 --port 5175) &
web_pid=$!

trap 'kill "$api_pid" "$web_pid" 2>/dev/null || true' INT TERM EXIT
wait -n "$api_pid" "$web_pid"
