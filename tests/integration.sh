#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
port=18765
db="$tmp/test.sqlite"
room_state=""
ron_room_state=""
pong_room_state=""
tsumo_room_state=""
server_pid=""

cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ -n "$room_state" ]]; then
        rm -f -- "$room_state"
    fi
    if [[ -n "$ron_room_state" ]]; then
        rm -f -- "$ron_room_state"
    fi
    if [[ -n "$pong_room_state" ]]; then
        rm -f -- "$pong_room_state"
    fi
    if [[ -n "$tsumo_room_state" ]]; then
        rm -f -- "$tsumo_room_state"
    fi
    rm -rf -- "$tmp"
}
trap cleanup EXIT

DONJARA_DB_PATH="$db" php -S "127.0.0.1:$port" -t "$root/public" >"$tmp/server.log" 2>&1 &
server_pid=$!

for attempt in $(seq 1 30); do
    if curl --silent --fail "http://127.0.0.1:$port/health.php" >/dev/null; then
        break
    fi
    sleep 0.2
done
if ! curl --silent --fail "http://127.0.0.1:$port/health.php" >/dev/null; then
    cat "$tmp/server.log" >&2
    exit 1
fi

uuid1='11111111-1111-4111-8111-111111111111'
uuid2='22222222-2222-4222-8222-222222222222'
created="$(curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"create_room\",\"player_uuid\":\"$uuid1\",\"name\":\"Host\"}" \
    "http://127.0.0.1:$port/action.php")"
room_id="$(php -r '$d=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $d["room_id"] ?? "";' "$created")"
if [[ ! "$room_id" =~ ^room_[a-f0-9]{12}$ ]]; then
    echo "Room creation did not return a valid room ID." >&2
    exit 1
fi
room_state="$root/public/data/$room_id.json"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"join_room\",\"room_id\":\"$room_id\",\"player_uuid\":\"$uuid2\",\"name\":\"Guest\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"start_game\",\"room_id\":\"$room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null

php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "playing" || count($state["players"]) !== 2 || $state["remaining_tiles"] !== 22) {
    throw new RuntimeException("Unexpected started room state.");
}
foreach ($state["players"] as $player) {
    if ($player["hand_count"] !== 8 || array_key_exists("hand", $player)) {
        throw new RuntimeException("Hand was not kept private.");
    }
}
$player = $state["players"][0];
$key = substr(hash("sha256", $argv[2]), 0, 16);
$iv = substr(hash("sha256", "donjara-salt-8.3"), 0, 16);
$hand = openssl_decrypt($player["encrypted_hand"], "aes-128-cbc", $key, 0, $iv);
if (!is_string($hand) || count(json_decode($hand, true, 512, JSON_THROW_ON_ERROR)) !== 8) {
    throw new RuntimeException("Could not decrypt the owner hand.");
}
' "$room_state" "$uuid1"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"draw_tile\",\"room_id\":\"$room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null

php -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->exec("UPDATE rooms SET deadline_at = " . (time() - 1) . " WHERE room_id = " . $db->quote($argv[2]));
' "$db" "$room_id"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"advance_timeout\",\"room_id\":\"$room_id\",\"player_uuid\":\"$uuid2\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null

php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "playing" || $state["current_turn_seat"] !== 1
    || count($state["discard_pile"]) !== 1 || $state["players"][1]["hand_count"] !== 8
    || count($state["discard_history"]) !== 1
    || $state["discard_history"][0]["seat_number"] !== 0
    || $state["discard_history"][0]["tile_id"] !== $state["discard_pile"][0]) {
    throw new RuntimeException("Turn timeout did not auto-discard and advance.");
}
' "$room_state"

ron_created="$(curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"create_room\",\"player_uuid\":\"$uuid1\",\"name\":\"Host\"}" \
    "http://127.0.0.1:$port/action.php")"
ron_room_id="$(php -r '$d=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $d["room_id"] ?? "";' "$ron_created")"
ron_room_state="$root/public/data/$ron_room_id.json"
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"join_room\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid2\",\"name\":\"Guest\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"start_game\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null

php -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->beginTransaction();
$hands = [
    ["A1", "A1", "A1", "B1", "B1", "B1", "C1", "C1"],
    ["C1", "A3", "A3", "A3", "B2", "B2", "B2", "C2"],
];
$players = $db->prepare("SELECT player_id FROM players WHERE room_id = ? ORDER BY seat_number");
$players->execute([$argv[2]]);
$update = $db->prepare("UPDATE players SET hand = ?, is_riichi = 0, melds = \"[]\" WHERE player_id = ?");
foreach ($players->fetchAll(PDO::FETCH_COLUMN) as $index => $playerId) {
    $update->execute([json_encode($hands[$index], JSON_THROW_ON_ERROR), $playerId]);
}
$deck = json_encode(["B3", "A2"], JSON_THROW_ON_ERROR);
$room = $db->prepare("UPDATE rooms SET tile_deck = ?, current_turn_seat = 0, status = \"playing\", turn_has_drawn = 0, deadline_at = ? WHERE room_id = ?");
$room->execute([$deck, time() + 30, $argv[2]]);
$db->commit();
' "$db" "$ron_room_id"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"draw_tile\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"discard_tile\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid1\",\"tile_index\":8,\"declare_riichi\":true}" \
    "http://127.0.0.1:$port/action.php" >/dev/null

php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "playing" || $state["players"][0]["is_riichi"] !== 1
    || $state["players"][0]["score"] !== 24000 || $state["riichi_pool"] !== 1000
    || $state["discard_history"][0]["seat_number"] !== 0
    || $state["discard_history"][0]["tile_id"] !== "A2") {
    throw new RuntimeException("Valid riichi declaration was not applied.");
}
' "$ron_room_state"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"draw_tile\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid2\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"discard_tile\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid2\",\"tile_index\":0,\"declare_riichi\":false}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "ron_wait" || $state["ron_target_seat"] !== 0 || $state["pending_tile"] !== "C1") {
    throw new RuntimeException("A valid ron opportunity was not detected.");
}
' "$ron_room_state"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"claim_ron\",\"room_id\":\"$ron_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "finished" || $state["winner_seat"] !== 0 || $state["win_type"] !== "ron"
    || $state["players"][0]["score"] !== 25000 || $state["players"][1]["hand_count"] !== 8) {
    throw new RuntimeException("Ron did not finish and award the riichi pool.");
}
' "$ron_room_state"

pong_created="$(curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"create_room\",\"player_uuid\":\"$uuid1\",\"name\":\"Host\"}" \
    "http://127.0.0.1:$port/action.php")"
pong_room_id="$(php -r '$d=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $d["room_id"] ?? "";' "$pong_created")"
pong_room_state="$root/public/data/$pong_room_id.json"
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"join_room\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid2\",\"name\":\"Guest\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"start_game\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->beginTransaction();
$hands = [
    ["A1", "A2", "A2", "A2", "B1", "B1", "B1", "C1"],
    ["A1", "A1", "A3", "B2", "B2", "B2", "C3", "C3"],
];
$players = $db->prepare("SELECT player_id FROM players WHERE room_id = ? ORDER BY seat_number");
$players->execute([$argv[2]]);
$update = $db->prepare("UPDATE players SET hand = ?, is_riichi = 0, melds = \"[]\" WHERE player_id = ?");
foreach ($players->fetchAll(PDO::FETCH_COLUMN) as $index => $playerId) {
    $update->execute([json_encode($hands[$index], JSON_THROW_ON_ERROR), $playerId]);
}
$room = $db->prepare("UPDATE rooms SET tile_deck = ?, current_turn_seat = 0, status = \"playing\", turn_has_drawn = 0, deadline_at = ? WHERE room_id = ?");
$room->execute([json_encode(["B3", "A2"], JSON_THROW_ON_ERROR), time() + 30, $argv[2]]);
$db->commit();
' "$db" "$pong_room_id"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"draw_tile\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"discard_tile\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid1\",\"tile_index\":0,\"declare_riichi\":false}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"claim_pong\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid2\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["current_turn_seat"] !== 1 || !$state["turn_has_drawn"]
    || $state["players"][1]["hand_count"] !== 6
    || $state["players"][1]["melds"] !== [["A1", "A1", "A1"]]) {
    throw new RuntimeException("Pong did not expose a meld and preserve the turn.");
}
' "$pong_room_state"
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"discard_tile\",\"room_id\":\"$pong_room_id\",\"player_uuid\":\"$uuid2\",\"tile_index\":0,\"declare_riichi\":false}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["current_turn_seat"] !== 0 || $state["players"][1]["hand_count"] !== 5
    || count($state["players"][1]["melds"]) !== 1) {
    throw new RuntimeException("Pong player could not continue and discard.");
}
' "$pong_room_state"

tsumo_created="$(curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"create_room\",\"player_uuid\":\"$uuid1\",\"name\":\"Host\"}" \
    "http://127.0.0.1:$port/action.php")"
tsumo_room_id="$(php -r '$d=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $d["room_id"] ?? "";' "$tsumo_created")"
tsumo_room_state="$root/public/data/$tsumo_room_id.json"
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"join_room\",\"room_id\":\"$tsumo_room_id\",\"player_uuid\":\"$uuid2\",\"name\":\"Guest\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"start_game\",\"room_id\":\"$tsumo_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->beginTransaction();
$player = $db->prepare("SELECT player_id FROM players WHERE room_id = ? AND seat_number = 0");
$player->execute([$argv[2]]);
$updatePlayer = $db->prepare("UPDATE players SET hand = ?, is_riichi = 1, score = 24000 WHERE player_id = ?");
$updatePlayer->execute([
    json_encode(["A1", "A1", "A1", "B1", "B1", "B1", "C1", "C1"], JSON_THROW_ON_ERROR),
    $player->fetchColumn(),
]);
$room = $db->prepare("UPDATE rooms SET tile_deck = ?, current_turn_seat = 0, status = \"playing\", turn_has_drawn = 0, riichi_pool = 1000, deadline_at = ? WHERE room_id = ?");
$room->execute([json_encode(["C1"], JSON_THROW_ON_ERROR), time() + 30, $argv[2]]);
$db->commit();
' "$db" "$tsumo_room_id"

curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"draw_tile\",\"room_id\":\"$tsumo_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
curl --silent --show-error --fail \
    -H 'Content-Type: application/json' \
    --data "{\"action\":\"claim_tsumo\",\"room_id\":\"$tsumo_room_id\",\"player_uuid\":\"$uuid1\"}" \
    "http://127.0.0.1:$port/action.php" >/dev/null
php -r '
$state = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($state["status"] !== "finished" || $state["winner_seat"] !== 0 || $state["win_type"] !== "tsumo"
    || $state["players"][0]["score"] !== 25000 || $state["riichi_pool"] !== 0) {
    throw new RuntimeException("Tsumo did not finish and award the riichi pool.");
}
' "$tsumo_room_state"

echo "Room lifecycle, encrypted hands, drawing, timeout, riichi, ron, tsumo, pong, and scoring checks passed."
