<?php
declare(strict_types=1);

function openGameDatabase(): PDO
{
    $databasePath = getenv('DONJARA_DB_PATH') ?: __DIR__ . '/data/donjara.db';
    $dataDirectory = dirname($databasePath);
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0770, true) && !is_dir($dataDirectory)) {
        throw new RuntimeException('Could not create the data directory.');
    }

    $db = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS rooms (
            room_id TEXT PRIMARY KEY,
            status TEXT NOT NULL CHECK (status IN ("lobby", "playing", "ron_wait", "finished")),
            current_turn_seat INTEGER NOT NULL DEFAULT 0,
            ron_target_seat INTEGER,
            pending_tile TEXT,
            tile_deck TEXT NOT NULL DEFAULT "[]",
            discard_pile TEXT NOT NULL DEFAULT "[]",
            discard_history TEXT NOT NULL DEFAULT "[]",
            deadline_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL,
            turn_has_drawn INTEGER NOT NULL DEFAULT 0,
            drawn_tile TEXT,
            last_discarder_seat INTEGER,
            ron_candidates TEXT NOT NULL DEFAULT "[]",
            riichi_pool INTEGER NOT NULL DEFAULT 0,
            winner_seat INTEGER,
            win_type TEXT
        )'
    );
    $roomColumns = $db->query('PRAGMA table_info(rooms)')->fetchAll();
    $hasDiscardHistory = in_array('discard_history', array_column($roomColumns, 'name'), true);
    if (!$hasDiscardHistory) {
        $db->exec('ALTER TABLE rooms ADD COLUMN discard_history TEXT NOT NULL DEFAULT "[]"');
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS players (
            player_id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id TEXT NOT NULL REFERENCES rooms(room_id) ON DELETE CASCADE,
            seat_number INTEGER NOT NULL,
            player_uuid TEXT NOT NULL,
            name TEXT NOT NULL,
            score INTEGER NOT NULL DEFAULT 25000,
            is_riichi INTEGER NOT NULL DEFAULT 0 CHECK (is_riichi IN (0, 1)),
            hand TEXT NOT NULL DEFAULT "[]",
            melds TEXT NOT NULL DEFAULT "[]",
            UNIQUE (room_id, seat_number),
            UNIQUE (room_id, player_uuid)
        )'
    );

    return $db;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        openGameDatabase();
        fwrite(STDOUT, "Database initialized at data/donjara.db\n");
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
