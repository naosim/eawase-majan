#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
web_root="$tmp/www"
app_root="$web_root/donjara"
port=18766
server_pid=""
room_state=""

cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ -n "$room_state" ]]; then
        rm -f -- "$room_state"
    fi
    rm -rf -- "$tmp"
}
trap cleanup EXIT

mkdir -p "$web_root"
sh "$root/scripts/build-deploy-package.sh" "$app_root"
DOCUMENT_ROOT="$web_root" php -S "127.0.0.1:$port" -t "$web_root" >"$tmp/server.log" 2>&1 &
server_pid=$!

for attempt in $(seq 1 30); do
    if curl --silent --fail "http://127.0.0.1:$port/donjara/health.php" >/dev/null; then
        break
    fi
    sleep 0.2
done
if ! curl --silent --fail "http://127.0.0.1:$port/donjara/health.php" >/dev/null; then
    cat "$tmp/server.log" >&2
    exit 1
fi

home="$(curl --silent --show-error --fail --location "http://127.0.0.1:$port/donjara/")"
if [[ "$home" != *'./assets/game.css'* ]]; then
    echo "The subdirectory homepage did not use relative asset paths." >&2
    exit 1
fi
curl --silent --show-error --fail "http://127.0.0.1:$port/donjara/assets/game.css" >/dev/null
curl --silent --show-error --fail "http://127.0.0.1:$port/donjara/editor.html" >/dev/null

created="$(curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data '{"action":"create_room","player_uuid":"11111111-1111-4111-8111-111111111111","name":"Deploy test"}' \
    "http://127.0.0.1:$port/donjara/action.php")"
room_id="$(php -r '$d=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $d["room_id"] ?? "";' "$created")"
room_state="$app_root/data/$room_id.json"
curl --silent --show-error --fail \
    "http://127.0.0.1:$port/donjara/data/$room_id.json" >"$tmp/room.json"
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["room_id"] !== $argv[2] || $state["players"][0]["name"] !== "Deploy test") {
    throw new RuntimeException("Room state was not published below the subdirectory.");
}
' "$tmp/room.json" "$room_id"

if [[ -f "$app_root/data/donjara.db" || -f "$web_root/data/donjara.db" ]]; then
    echo "SQLite database was created inside the public web root." >&2
    exit 1
fi

echo "Subdirectory deployment package checks passed."
