<?php
declare(strict_types=1);

require_once __DIR__ . '/db_init.php';

const GAME_TURN_SECONDS = 30;
const GAME_RON_SECONDS = 20;
const GAME_TILE_IDS = ['A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3'];
const GAME_WILDCARDS = ['J1', 'J2'];

final class GameError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function requireString(array $input, string $key, int $maxLength = 100): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value) || trim($value) === '' || mbLength($value) > $maxLength) {
        throw new GameError("Invalid {$key}.");
    }

    return trim($value);
}

function mbLength(string $value): int
{
    return preg_match_all('/./us', $value, $matches) === false
        ? strlen($value)
        : count($matches[0]);
}

function validateUuid(array $input): string
{
    $uuid = requireString($input, 'player_uuid', 36);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        throw new GameError('Invalid player UUID.');
    }

    return strtolower($uuid);
}

function validateRoomId(array $input): string
{
    $roomId = requireString($input, 'room_id', 17);
    if (!preg_match('/^room_[a-f0-9]{12}$/', $roomId)) {
        throw new GameError('Invalid room ID.');
    }

    return $roomId;
}

function beginImmediate(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE');
}

function commit(PDO $db): void
{
    $db->exec('COMMIT');
}

function rollback(PDO $db): void
{
    if ($db->inTransaction()) {
        $db->exec('ROLLBACK');
    }
}

function loadRoom(PDO $db, string $roomId): array
{
    $statement = $db->prepare('SELECT * FROM rooms WHERE room_id = ?');
    $statement->execute([$roomId]);
    $room = $statement->fetch();
    if (!$room) {
        throw new GameError('ルームが見つかりません。', 404);
    }

    return $room;
}

function loadPlayer(PDO $db, string $roomId, string $uuid): array
{
    $statement = $db->prepare('SELECT * FROM players WHERE room_id = ? AND player_uuid = ?');
    $statement->execute([$roomId, $uuid]);
    $player = $statement->fetch();
    if (!$player) {
        throw new GameError('このルームのプレイヤーではありません。', 403);
    }

    return $player;
}

function saveRoom(PDO $db, array $room): void
{
    $room['updated_at'] = time();
    $statement = $db->prepare(
        'UPDATE rooms SET
            status = :status,
            current_turn_seat = :current_turn_seat,
            ron_target_seat = :ron_target_seat,
            pending_tile = :pending_tile,
            tile_deck = :tile_deck,
            discard_pile = :discard_pile,
            discard_history = :discard_history,
            deadline_at = :deadline_at,
            updated_at = :updated_at,
            turn_has_drawn = :turn_has_drawn,
            drawn_tile = :drawn_tile,
            last_discarder_seat = :last_discarder_seat,
            ron_candidates = :ron_candidates,
            riichi_pool = :riichi_pool,
            winner_seat = :winner_seat,
            win_type = :win_type
         WHERE room_id = :room_id'
    );
    $statement->execute([
        ':room_id' => $room['room_id'],
        ':status' => $room['status'],
        ':current_turn_seat' => (int)$room['current_turn_seat'],
        ':ron_target_seat' => $room['ron_target_seat'],
        ':pending_tile' => $room['pending_tile'],
        ':tile_deck' => $room['tile_deck'],
        ':discard_pile' => $room['discard_pile'],
        ':discard_history' => $room['discard_history'],
        ':deadline_at' => (int)$room['deadline_at'],
        ':updated_at' => (int)$room['updated_at'],
        ':turn_has_drawn' => (int)$room['turn_has_drawn'],
        ':drawn_tile' => $room['drawn_tile'],
        ':last_discarder_seat' => $room['last_discarder_seat'],
        ':ron_candidates' => $room['ron_candidates'],
        ':riichi_pool' => (int)$room['riichi_pool'],
        ':winner_seat' => $room['winner_seat'],
        ':win_type' => $room['win_type'],
    ]);
}

function updatePlayer(PDO $db, array $player, ?array $hand = null, ?array $melds = null, ?int $score = null, ?int $isRiichi = null): void
{
    $statement = $db->prepare(
        'UPDATE players SET hand = ?, melds = ?, score = ?, is_riichi = ? WHERE player_id = ?'
    );
    $statement->execute([
        json_encode($hand ?? json_decode($player['hand'], true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR),
        json_encode($melds ?? json_decode($player['melds'], true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR),
        $score ?? (int)$player['score'],
        $isRiichi ?? (int)$player['is_riichi'],
        (int)$player['player_id'],
    ]);
}

function getPlayers(PDO $db, string $roomId): array
{
    $statement = $db->prepare('SELECT * FROM players WHERE room_id = ? ORDER BY seat_number');
    $statement->execute([$roomId]);

    return $statement->fetchAll();
}

function decodedList(string $json): array
{
    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_array($value) ? $value : [];
}

function recordDiscard(array &$room, string $tileId, int $seatNumber): void
{
    $discardPile = decodedList($room['discard_pile']);
    $discardPile[] = $tileId;
    $room['discard_pile'] = json_encode($discardPile, JSON_THROW_ON_ERROR);

    $discardHistory = decodedList($room['discard_history']);
    $discardHistory[] = [
        'seat_number' => $seatNumber,
        'tile_id' => $tileId,
    ];
    $room['discard_history'] = json_encode($discardHistory, JSON_THROW_ON_ERROR);
}

function createDeck(): array
{
    $deck = [];
    foreach (GAME_TILE_IDS as $tileId) {
        for ($copy = 0; $copy < 4; $copy++) {
            $deck[] = $tileId;
        }
    }
    array_push($deck, ...GAME_WILDCARDS);
    shuffle($deck);

    return $deck;
}

function nextSeat(array $players, int $seat): int
{
    $seats = array_map(static fn(array $player): int => (int)$player['seat_number'], $players);
    $currentIndex = array_search($seat, $seats, true);
    if ($currentIndex === false || $seats === []) {
        throw new RuntimeException('Could not resolve the next player seat.');
    }

    return $seats[($currentIndex + 1) % count($seats)];
}

function isWinningHand(array $hand, array $melds = []): bool
{
    $tiles = $hand;
    foreach ($melds as $meld) {
        if (!is_array($meld) || count($meld) !== 3) {
            return false;
        }
        array_push($tiles, ...$meld);
    }
    if (count($tiles) !== 9) {
        return false;
    }

    $counts = array_fill_keys(GAME_TILE_IDS, 0);
    $wildcards = 0;
    foreach ($tiles as $tileId) {
        if (in_array($tileId, GAME_WILDCARDS, true)) {
            $wildcards++;
        } elseif (isset($counts[$tileId])) {
            $counts[$tileId]++;
        } else {
            return false;
        }
    }

    $neededWildcards = 0;
    foreach ($counts as $count) {
        $neededWildcards += (3 - ($count % 3)) % 3;
    }

    return $neededWildcards <= $wildcards && ($wildcards - $neededWildcards) % 3 === 0;
}

function isTenpai(array $hand, array $melds): bool
{
    if (count($hand) + count($melds) * 3 !== 8) {
        return false;
    }
    foreach (array_merge(GAME_TILE_IDS, GAME_WILDCARDS) as $tileId) {
        if (isWinningHand([...$hand, $tileId], $melds)) {
            return true;
        }
    }

    return false;
}

function canClaimPong(array $hand, string $tileId): bool
{
    $matches = count(array_filter($hand, static fn(string $tile): bool => $tile === $tileId));
    $wildcards = count(array_filter($hand, static fn(string $tile): bool => in_array($tile, GAME_WILDCARDS, true)));

    return $matches + $wildcards >= 2 && !in_array($tileId, GAME_WILDCARDS, true);
}

function potentialRonCandidates(PDO $db, array $room, int $discarderSeat, string $tileId): array
{
    $candidates = [];
    foreach (getPlayers($db, $room['room_id']) as $player) {
        if ((int)$player['seat_number'] === $discarderSeat || (int)$player['is_riichi'] !== 1) {
            continue;
        }
        $hand = decodedList($player['hand']);
        $melds = decodedList($player['melds']);
        if (isWinningHand([...$hand, $tileId], $melds)) {
            $candidates[] = (int)$player['seat_number'];
        }
    }
    usort($candidates, static fn(int $a, int $b): int => $a <=> $b);

    return $candidates;
}

function finishGame(PDO $db, array &$room, array $players, int $winnerSeat, string $winType): void
{
    $winner = null;
    foreach ($players as $player) {
        if ((int)$player['seat_number'] === $winnerSeat) {
            $winner = $player;
            break;
        }
    }
    if ($winner === null) {
        throw new RuntimeException('Winner seat is missing.');
    }

    updatePlayer($db, $winner, score: (int)$winner['score'] + (int)$room['riichi_pool']);
    $room['status'] = 'finished';
    $room['winner_seat'] = $winnerSeat;
    $room['win_type'] = $winType;
    $room['ron_target_seat'] = null;
    $room['ron_candidates'] = '[]';
    $room['pending_tile'] = null;
    $room['riichi_pool'] = 0;
    $room['deadline_at'] = 0;
    $room['turn_has_drawn'] = 0;
    $room['drawn_tile'] = null;
}

function advanceRonWait(PDO $db, array &$room): void
{
    $candidates = decodedList($room['ron_candidates']);
    array_shift($candidates);
    $room['ron_candidates'] = json_encode($candidates, JSON_THROW_ON_ERROR);
    if ($candidates !== []) {
        $room['ron_target_seat'] = (int)$candidates[0];
        $room['deadline_at'] = time() + GAME_RON_SECONDS;
        return;
    }

    $room['status'] = 'playing';
    $room['ron_target_seat'] = null;
    $room['deadline_at'] = time() + GAME_TURN_SECONDS;
}

function autoAdvance(PDO $db, array &$room): void
{
    if ($room['status'] === 'ron_wait') {
        advanceRonWait($db, $room);
        return;
    }
    if ($room['status'] !== 'playing') {
        return;
    }

    $players = getPlayers($db, $room['room_id']);
    $current = null;
    foreach ($players as $player) {
        if ((int)$player['seat_number'] === (int)$room['current_turn_seat']) {
            $current = $player;
            break;
        }
    }
    if ($current === null) {
        throw new RuntimeException('Current player is missing.');
    }

    $hand = decodedList($current['hand']);
    $melds = decodedList($current['melds']);
    $deck = decodedList($room['tile_deck']);
    if (!(bool)$room['turn_has_drawn']) {
        if ($deck === []) {
            $room['status'] = 'finished';
            $room['win_type'] = 'draw';
            $room['deadline_at'] = 0;
            $room['pending_tile'] = null;
            $room['ron_target_seat'] = null;
            $room['ron_candidates'] = '[]';
            $room['turn_has_drawn'] = 0;
            $room['drawn_tile'] = null;
            return;
        }
        $hand[] = array_pop($deck);
        $room['tile_deck'] = json_encode($deck, JSON_THROW_ON_ERROR);
        updatePlayer($db, $current, hand: $hand);
    }
    if ($hand === []) {
        throw new RuntimeException('Cannot auto-discard an empty hand.');
    }

    $tileId = array_pop($hand);
    updatePlayer($db, $current, hand: $hand);
    recordDiscard($room, $tileId, (int)$current['seat_number']);
    $room['pending_tile'] = $tileId;
    $room['last_discarder_seat'] = (int)$current['seat_number'];
    $room['current_turn_seat'] = nextSeat($players, (int)$current['seat_number']);
    $room['turn_has_drawn'] = 0;
    $room['drawn_tile'] = null;
    $candidates = potentialRonCandidates($db, $room, (int)$current['seat_number'], $tileId);
    if ($candidates !== []) {
        $room['status'] = 'ron_wait';
        $room['ron_candidates'] = json_encode($candidates, JSON_THROW_ON_ERROR);
        $room['ron_target_seat'] = $candidates[0];
        $room['deadline_at'] = time() + GAME_RON_SECONDS;
    } else {
        $room['status'] = 'playing';
        $room['deadline_at'] = time() + GAME_TURN_SECONDS;
    }
}

function publishRoomState(PDO $db, string $roomId): void
{
    $room = loadRoom($db, $roomId);
    $players = [];
    foreach (getPlayers($db, $roomId) as $player) {
        $hand = decodedList($player['hand']);
        $key = substr(hash('sha256', $player['player_uuid']), 0, 16);
        $iv = substr(hash('sha256', 'donjara-salt-8.3'), 0, 16);
        $encryptedHand = openssl_encrypt(
            json_encode($hand, JSON_THROW_ON_ERROR),
            'aes-128-cbc',
            $key,
            0,
            $iv
        );
        if ($encryptedHand === false) {
            throw new RuntimeException('Could not encrypt player hand.');
        }
        $players[] = [
            'seat_number' => (int)$player['seat_number'],
            'name' => $player['name'],
            'score' => (int)$player['score'],
            'is_riichi' => (int)$player['is_riichi'],
            'player_tag' => hash('sha256', $player['player_uuid']),
            'hand_count' => count($hand),
            'melds' => decodedList($player['melds']),
            'encrypted_hand' => $encryptedHand,
        ];
    }

    $state = [
        'room_id' => $roomId,
        'status' => $room['status'],
        'current_turn_seat' => (int)$room['current_turn_seat'],
        'turn_has_drawn' => (bool)$room['turn_has_drawn'],
        'ron_target_seat' => $room['ron_target_seat'] === null ? null : (int)$room['ron_target_seat'],
        'pending_tile' => $room['pending_tile'],
        'last_discarder_seat' => $room['last_discarder_seat'] === null ? null : (int)$room['last_discarder_seat'],
        'discard_pile' => decodedList($room['discard_pile']),
        'discard_history' => decodedList($room['discard_history']),
        'remaining_tiles' => count(decodedList($room['tile_deck'])),
        'riichi_pool' => (int)$room['riichi_pool'],
        'winner_seat' => $room['winner_seat'] === null ? null : (int)$room['winner_seat'],
        'win_type' => $room['win_type'],
        'deadline_at' => (int)$room['deadline_at'],
        'updated_at' => time(),
        'players' => $players,
    ];
    $encoded = json_encode(
        $state,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR
    );
    $directory = __DIR__ . '/public/data';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the public data directory.');
    }
    $destination = $directory . '/' . $roomId . '.json';
    $temporary = tempnam($directory, '.room-');
    if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not write room state.');
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish room state.');
    }
}

function performGameAction(PDO $db, array $input): array
{
    $action = requireString($input, 'action', 30);
    $uuid = validateUuid($input);
    $roomId = validateRoomId($input);
    $roomChanged = false;
    $response = [];
    beginImmediate($db);
    try {
        $room = loadRoom($db, $roomId);
        $player = loadPlayer($db, $roomId, $uuid);
        if ($room['status'] !== 'lobby' && $room['status'] !== 'finished'
            && (int)$room['deadline_at'] > 0 && (int)$room['deadline_at'] <= time()
            && $action !== 'advance_timeout') {
            autoAdvance($db, $room);
            saveRoom($db, $room);
            commit($db);
            publishRoomState($db, $roomId);
            return ['message' => '時間切れのため、自動でゲームを進めました。'];
        }

        switch ($action) {
            case 'join_room':
                if ($room['status'] !== 'lobby') {
                    throw new GameError('このルームはすでに対局中です。', 409);
                }
                $response = ['room_id' => $roomId];
                break;

            case 'start_game':
                if ((int)$player['seat_number'] !== 0) {
                    throw new GameError('対局を開始できるのはルーム作成者だけです。', 403);
                }
                if ($room['status'] !== 'lobby') {
                    throw new GameError('このルームは開始できません。', 409);
                }
                $players = getPlayers($db, $roomId);
                if (count($players) < 2) {
                    throw new GameError('対局には2人以上必要です。', 409);
                }
                $deck = createDeck();
                foreach ($players as $index => $currentPlayer) {
                    $hand = array_splice($deck, 0, 8);
                    sort($hand);
                    updatePlayer($db, $currentPlayer, hand: $hand, melds: [], isRiichi: 0);
                }
                $room['status'] = 'playing';
                $room['tile_deck'] = json_encode($deck, JSON_THROW_ON_ERROR);
                $room['current_turn_seat'] = 0;
                $room['deadline_at'] = time() + GAME_TURN_SECONDS;
                $room['discard_pile'] = '[]';
                $room['discard_history'] = '[]';
                $room['pending_tile'] = null;
                $room['ron_target_seat'] = null;
                $room['last_discarder_seat'] = null;
                $room['turn_has_drawn'] = 0;
                $room['drawn_tile'] = null;
                $room['ron_candidates'] = '[]';
                $room['riichi_pool'] = 0;
                $room['winner_seat'] = null;
                $room['win_type'] = null;
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'draw_tile':
                if ($room['status'] !== 'playing' || (int)$room['current_turn_seat'] !== (int)$player['seat_number']) {
                    throw new GameError('あなたのターンではありません。', 409);
                }
                if ((bool)$room['turn_has_drawn']) {
                    throw new GameError('このターンはすでに牌を引いています。', 409);
                }
                $deck = decodedList($room['tile_deck']);
                if ($deck === []) {
                    $room['status'] = 'finished';
                    $room['win_type'] = 'draw';
                    $room['deadline_at'] = 0;
                    $room['pending_tile'] = null;
                    $room['last_discarder_seat'] = null;
                    $room['ron_target_seat'] = null;
                    $room['ron_candidates'] = '[]';
                    $room['turn_has_drawn'] = 0;
                    $room['drawn_tile'] = null;
                    saveRoom($db, $room);
                    $roomChanged = true;
                    break;
                }
                $hand = decodedList($player['hand']);
                $tileId = array_pop($deck);
                $hand[] = $tileId;
                updatePlayer($db, $player, hand: $hand);
                $room['tile_deck'] = json_encode($deck, JSON_THROW_ON_ERROR);
                $room['turn_has_drawn'] = 1;
                $room['drawn_tile'] = $tileId;
                $room['pending_tile'] = null;
                $room['last_discarder_seat'] = null;
                $room['deadline_at'] = time() + GAME_TURN_SECONDS;
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'discard_tile':
                if ($room['status'] !== 'playing' || (int)$room['current_turn_seat'] !== (int)$player['seat_number']
                    || !(bool)$room['turn_has_drawn']) {
                    throw new GameError('捨て牌を選べる状態ではありません。', 409);
                }
                $hand = decodedList($player['hand']);
                $melds = decodedList($player['melds']);
                $tileIndex = filter_var($input['tile_index'] ?? null, FILTER_VALIDATE_INT);
                if ($tileIndex === false || $tileIndex < 0 || $tileIndex >= count($hand)
                    || count($hand) + count($melds) * 3 !== 9) {
                    throw new GameError('捨てる牌を確認してください。');
                }
                $discardedTile = $hand[$tileIndex];
                if ((int)$player['is_riichi'] === 1 && $tileIndex !== count($hand) - 1) {
                    throw new GameError('リーチ後は引いた牌だけを捨てられます。', 409);
                }
                $declareRiichi = $input['declare_riichi'] ?? false;
                if (!is_bool($declareRiichi)) {
                    throw new GameError('Invalid declare_riichi value.');
                }
                if ($declareRiichi) {
                    if ((int)$player['is_riichi'] === 1 || (int)$player['score'] < 1000) {
                        throw new GameError('リーチを宣言できません。', 409);
                    }
                    $candidateHand = $hand;
                    array_splice($candidateHand, $tileIndex, 1);
                    if (!isTenpai($candidateHand, $melds)) {
                        throw new GameError('テンパイしていないためリーチできません。', 409);
                    }
                    updatePlayer(
                        $db,
                        $player,
                        hand: $candidateHand,
                        score: (int)$player['score'] - 1000,
                        isRiichi: 1
                    );
                    $room['riichi_pool'] = (int)$room['riichi_pool'] + 1000;
                } else {
                    array_splice($hand, $tileIndex, 1);
                    updatePlayer($db, $player, hand: $hand);
                }

                recordDiscard($room, $discardedTile, (int)$player['seat_number']);
                $room['pending_tile'] = $discardedTile;
                $room['last_discarder_seat'] = (int)$player['seat_number'];
                $room['current_turn_seat'] = nextSeat(getPlayers($db, $roomId), (int)$player['seat_number']);
                $room['turn_has_drawn'] = 0;
                $room['drawn_tile'] = null;
                $candidates = potentialRonCandidates($db, $room, (int)$player['seat_number'], $discardedTile);
                if ($candidates !== []) {
                    $room['status'] = 'ron_wait';
                    $room['ron_candidates'] = json_encode($candidates, JSON_THROW_ON_ERROR);
                    $room['ron_target_seat'] = $candidates[0];
                    $room['deadline_at'] = time() + GAME_RON_SECONDS;
                } else {
                    $room['status'] = 'playing';
                    $room['ron_candidates'] = '[]';
                    $room['ron_target_seat'] = null;
                    $room['deadline_at'] = time() + GAME_TURN_SECONDS;
                }
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'claim_pong':
                if ($room['status'] !== 'playing' || (int)$room['current_turn_seat'] !== (int)$player['seat_number']
                    || !(bool)$room['pending_tile'] || (int)$room['last_discarder_seat'] === (int)$player['seat_number']
                    || (bool)$room['turn_has_drawn'] || (int)$player['is_riichi'] === 1) {
                    throw new GameError('ポンできる状態ではありません。', 409);
                }
                $tileId = (string)$room['pending_tile'];
                $hand = decodedList($player['hand']);
                $melds = decodedList($player['melds']);
                if (!canClaimPong($hand, $tileId)) {
                    throw new GameError('ポンに必要な牌が手牌にありません。', 409);
                }
                $pair = [];
                foreach ($hand as $index => $heldTile) {
                    if ($heldTile === $tileId && count($pair) < 2) {
                        $pair[] = $index;
                    }
                }
                if (count($pair) < 2) {
                    foreach ($hand as $index => $heldTile) {
                        if (in_array($heldTile, GAME_WILDCARDS, true) && count($pair) < 2) {
                            $pair[] = $index;
                        }
                    }
                }
                rsort($pair);
                $meld = [$tileId];
                foreach ($pair as $index) {
                    $meld[] = $hand[$index];
                    array_splice($hand, $index, 1);
                }
                $melds[] = $meld;
                updatePlayer($db, $player, hand: $hand, melds: $melds);
                $room['pending_tile'] = null;
                $room['last_discarder_seat'] = null;
                $room['turn_has_drawn'] = 1;
                $room['drawn_tile'] = null;
                $room['deadline_at'] = time() + GAME_TURN_SECONDS;
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'claim_tsumo':
                $hand = decodedList($player['hand']);
                $melds = decodedList($player['melds']);
                if ($room['status'] !== 'playing' || (int)$room['current_turn_seat'] !== (int)$player['seat_number']
                    || !(bool)$room['turn_has_drawn'] || (int)$player['is_riichi'] !== 1
                    || !isWinningHand($hand, $melds)) {
                    throw new GameError('ツモで上がれる状態ではありません。', 409);
                }
                finishGame($db, $room, getPlayers($db, $roomId), (int)$player['seat_number'], 'tsumo');
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'claim_ron':
                if ($room['status'] !== 'ron_wait' || (int)$room['ron_target_seat'] !== (int)$player['seat_number']) {
                    throw new GameError('ロンを宣言できる状態ではありません。', 409);
                }
                $hand = decodedList($player['hand']);
                $melds = decodedList($player['melds']);
                if ((int)$player['is_riichi'] !== 1 || !isWinningHand([...$hand, (string)$room['pending_tile']], $melds)) {
                    throw new GameError('ロンの条件を満たしていません。', 409);
                }
                finishGame($db, $room, getPlayers($db, $roomId), (int)$player['seat_number'], 'ron');
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'claim_pass':
                if ($room['status'] !== 'ron_wait' || (int)$room['ron_target_seat'] !== (int)$player['seat_number']) {
                    throw new GameError('パスを選択できる状態ではありません。', 409);
                }
                advanceRonWait($db, $room);
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            case 'advance_timeout':
                if (!in_array($room['status'], ['playing', 'ron_wait'], true)
                    || (int)$room['deadline_at'] > time()) {
                    $response = ['message' => 'タイムアウトしていません。'];
                    break;
                }
                autoAdvance($db, $room);
                saveRoom($db, $room);
                $roomChanged = true;
                break;

            default:
                throw new GameError('不明なアクションです。');
        }

        $statement = $db->prepare('UPDATE rooms SET updated_at = ? WHERE room_id = ?');
        $statement->execute([time(), $roomId]);
        commit($db);
    } catch (Throwable $error) {
        rollback($db);
        throw $error;
    }

    if ($roomChanged) {
        publishRoomState($db, $roomId);
    }

    return $response;
}

function createRoom(PDO $db, array $input): array
{
    $uuid = validateUuid($input);
    $name = requireString($input, 'name', 20);
    $roomId = 'room_' . bin2hex(random_bytes(6));
    $now = time();
    beginImmediate($db);
    try {
        $statement = $db->prepare('INSERT INTO rooms (room_id, status, updated_at) VALUES (?, "lobby", ?)');
        $statement->execute([$roomId, $now]);
        $statement = $db->prepare(
            'INSERT INTO players (room_id, seat_number, player_uuid, name) VALUES (?, 0, ?, ?)'
        );
        $statement->execute([$roomId, $uuid, $name]);
        commit($db);
    } catch (Throwable $error) {
        rollback($db);
        throw $error;
    }
    publishRoomState($db, $roomId);

    return ['room_id' => $roomId];
}

function joinRoom(PDO $db, array $input): array
{
    $roomId = validateRoomId($input);
    $uuid = validateUuid($input);
    $name = requireString($input, 'name', 20);
    beginImmediate($db);
    try {
        $room = loadRoom($db, $roomId);
        $statement = $db->prepare('SELECT * FROM players WHERE room_id = ? AND player_uuid = ?');
        $statement->execute([$roomId, $uuid]);
        $existing = $statement->fetch();
        if ($existing) {
            commit($db);
            return ['room_id' => $roomId, 'seat_number' => (int)$existing['seat_number']];
        }
        if ($room['status'] !== 'lobby') {
            throw new GameError('このルームはすでに対局中です。', 409);
        }
        $players = getPlayers($db, $roomId);
        if (count($players) >= 4) {
            throw new GameError('ルームは満員です。', 409);
        }
        $occupied = array_map(static fn(array $player): int => (int)$player['seat_number'], $players);
        $seat = 0;
        while (in_array($seat, $occupied, true)) {
            $seat++;
        }
        $statement = $db->prepare(
            'INSERT INTO players (room_id, seat_number, player_uuid, name) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$roomId, $seat, $uuid, $name]);
        commit($db);
    } catch (Throwable $error) {
        rollback($db);
        throw $error;
    }
    publishRoomState($db, $roomId);

    return ['room_id' => $roomId, 'seat_number' => $seat];
}
