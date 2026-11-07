# 絵合わせマージャン（ドンジャラ風オンラインゲーム） 設計書

## 1. システム概要とアーキテクチャ

### 1.1 システムの目的
本システムは、PHP 8.3、SQLite、および静的JSONポーリングを組み合わせた、軽量でサーバー負荷の極めて低いブラウザ対戦型「絵合わせマージャン（ドンジャラ風ゲーム）」である。リアルタイム通信（WebSocket等）を使用せず、非同期のファイル読み込み（ポーリング）とクライアントサイド暗号化によって、セキュアかつスムーズな2〜4人対戦を実現する。

### 1.2 技術スタック
* **サーバーサイド:** PHP 8.3 (Strict Types, `json_validate()` 活用), SQLite 3 (PDO)
* **クライアントサイド:** Vanilla JavaScript (ES Modules), HTML5 / CSS3, CryptoJS (クライアント側暗号化・復号用)
* **インフラ・環境:** Linux (WSLでのローカル開発を想定), PHPビルトインサーバー
* **データ永続化・通信:** SQLite（マスタ・ルーム・プレイヤー管理）, 静的JSONファイル（ポーリング用配信）

### 1.3 全体アーキテクチャ
```text
[ プレイヤーブラウザ (JS) ]
    │ (fetch / ポーリング)
    ▼
[ data/room_xxx.json ] <── (書き出し) ── [ サーバーサイド API (action.php) ]
    ▲                                              │
    │ (アクション送信 POST)                        ▼
[ プレイヤーブラウザ (JS) ] ──────────────> [ SQLite (donjara.db) ]
```

---

## 2. 基本設計（要件・データフロー）

### 2.1 ゲーム基本ルール
* **人数:** 2〜4人可変
* **勝利条件:** 同じ絵柄の3枚組（刻子）× 3セット（合計9枚）を揃え、かつ**「リーチ」を宣言していること**が上がりの必須条件となる。
* **特殊牌（オールマイティ）:** 山札に**2枚**存在する万能牌（ジョーカー）。任意の牌の代わりとして役の成立に使用できる。
* **ポン:** 自分のターンが回ってきたとき、直前のプレイヤーが捨てた牌を拾って自分の手牌に組み込み、そのまま自分のターンを始められる。
* **ロン:** 他のプレイヤーが牌を捨てたとき、その牌で上がり形が完成する場合、割り込みで「ロン」を宣言できる。対象者の画面に「上がりますか？」が表示され、回答（またはタイムアウト）するまでゲームの進行が停止する。
* **リーチ:** テンパイした際、「リーチしますか？」の確認が発生する。リーチ宣言後は、ツモった牌をそのまま捨てる（ツモ切り）か、上がるかの2択に制限される。

### 2.2 離脱・切断対策
* すべての状態（通常ターン、ロン待ち等）にタイムアウト（期限タイムスタンプ）を設ける。
* 放置や切断が発生した場合、制限時間経過後にPHP側で自動的に「自動ツモ切り」や「自動パス」を実行し、ゲームがフリーズするのを防ぐ。
* プレイヤーの識別には `localStorage` に保存した UUID を使用し、ブラウザのリロードや再接続時に自動復帰する。

---

## 3. インターフェース設計（データ構造・API）

### 3.1 SQLite データベーススキーマ (`donjara.db`)

#### `rooms` テーブル（ルーム状態管理）
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `room_id` | TEXT (PK) | ルーム識別ID (例: `abc123`) |
| `status` | TEXT | `playing` (通常), `ron_wait` (ロン待ち), `finished` (終了) |
| `current_turn_seat` | INTEGER | 現在のターンのプレイヤーの席番号 (0〜3) |
| `ron_target_seat` | INTEGER | ロン確認中の対象プレイヤー席番号 |
| `pending_tile` | TEXT | 直前に捨てられた牌 (ロン判定用) |
| `tile_deck` | TEXT | 残り山札のJSON配列 |
| `discard_pile` | TEXT | 捨て牌の川のJSON配列 |
| `deadline_at` | INTEGER | タイムアウト用のUnixタイムスタンプ |
| `updated_at` | INTEGER | 最終更新タイムスタンプ |

#### `players` テーブル（プレイヤー状態管理）
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `player_id` | INTEGER (PK) | 自動採番ID |
| `room_id` | TEXT | 所属ルームID (`rooms.room_id` FK) |
| `seat_number` | INTEGER | 席番号 (0〜3) |
| `player_uuid` | TEXT | ブラウザの `localStorage` と紐づくUUID |
| `name` | TEXT | プレイヤー名 |
| `score` | INTEGER | 所持スコア・ポイント |
| `is_riichi` | INTEGER | 0: 通常, 1: リーチ中 |
| `hand` | TEXT | 手牌のJSON配列 (サーバー側保持) |

---

### 3.2 1ファイルスプライトシート・スキン仕様 (`skin.json`)
UI描画は、1枚のBase64エンコードされたスプライトシートから各牌の座標を切り出す方式を採用する。

```json
{
  "skin_id": "dot_animals_sheet",
  "skin_name": "ドット絵アニマルズ",
  "sheet_image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...",
  "tile_size": { "width": 64, "height": 64 },
  "tiles": {
    "A1": { "name": "ライオン", "category": "赤", "x": 0, "y": 0 },
    "J1": { "name": "オールマイティ", "category": "特殊", "x": 128, "y": 0, "is_wildcard": true }
  }
}
```

---

### 3.3 クライアント向け静的JSON仕様 (`data/{room_id}.json`)
ポーリング対象。他人の手牌は暗号化（`encrypted_hand`）され、自分のUUIDを鍵としてクライアント側で復号する。

```json
{
  "room_id": "abc123",
  "status": "playing",
  "current_turn_seat": 0,
  "ron_target_seat": null,
  "discard_pile": ["A1", "B2"],
  "deadline_at": 1773000000,
  "updated_at": 1773000500,
  "players": [
    {
      "seat_number": 0,
      "name": "プレイヤー1",
      "score": 25000,
      "is_riichi": 0,
      "hand_count": 9,
      "encrypted_hand": "U2FsdGVkX1+..."
    }
  ]
}
```

---

## 4. 詳細設計・プログラム実装例

### 4.1 サーバーサイドAPI: `action.php` (PHP 8.3)
リクエストを受け取り、SQLiteの更新および静的JSONの再生成を行うコアロジック。

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
if (!json_validate($rawInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON format'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode($rawInput, true);
$action = $input['action'] ?? '';
$roomId = $input['room_id'] ?? '';
$playerUuid = $input['player_uuid'] ?? '';

try {
    $db = new PDO('sqlite:__DIR__/data/donjara.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    switch ($action) {
        case 'discard_tile':
            handleDiscard($db, $roomId, $playerUuid, $input['tile_index'] ?? 0, $input['is_riichi'] ?? false);
            break;
        case 'claim_ron':
            handleRon($db, $roomId, $playerUuid);
            break;
        case 'claim_pass':
            handlePass($db, $roomId, $playerUuid);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
            exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleDiscard(PDO $db, string $roomId, string $playerUuid, int $tileIndex, bool $declareRiichi): void {
    $db->beginTransaction();
    // 1. ターンの検証と手牌からの牌の除外・捨て牌追加処理
    // 2. ロン判定（他プレイヤーにアタリ牌となる者がいるか）
    // 3. 状態の更新 (playing または ron_wait)
    $db->commit();
    updateStaticRoomJson($db, $roomId);
}

function updateStaticRoomJson(PDO $db, string $roomId): void {
    $stmt = $db->prepare('SELECT * FROM rooms WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    $stmt = $db->prepare('SELECT seat_number, name, score, is_riichi, player_uuid, hand FROM players WHERE room_id = ? ORDER BY seat_number');
    $stmt->execute([$roomId]);
    $players = $stmt->fetchAll();

    $publicPlayers = [];
    foreach ($players as $p) {
        $handArray = json_decode($p['hand'], true);
        $encryptedHand = openssl_encrypt(
            json_encode($handArray),
            'aes-128-cbc',
            substr(hash('sha256', $p['player_uuid']), 0, 16),
            0,
            substr(hash('sha256', 'donjara-salt-8.3'), 0, 16)
        );

        $publicPlayers[] = [
            'seat_number'    => (int)$p['seat_number'],
            'name'           => $p['name'],
            'score'          => (int)$p['score'],
            'is_riichi'      => (int)$p['is_riichi'],
            'hand_count'     => count($handArray),
            'encrypted_hand' => $encryptedHand
        ];
    }

    $publicState = [
        'room_id'           => $room['room_id'],
        'status'            => $room['status'],
        'current_turn_seat' => (int)$room['current_turn_seat'],
        'ron_target_seat'   => isset($room['ron_target_seat']) ? (int)$room['ron_target_seat'] : null,
        'discard_pile'      => json_decode($room['discard_pile'], true),
        'deadline_at'       => (int)$room['deadline_at'],
        'updated_at'        => time(),
        'players'           => $publicPlayers
    ];

    file_put_contents(__DIR__ . "/data/{$roomId}.json", json_encode($publicState, JSON_UNESCAPED_UNICODE));
}
```

---

### 4.2 フロントエンド・ポーリング＆描画処理 (JavaScript例)
クライアント側で定期的に静的JSONを取得し、自身のUUIDを使って手牌を復号・描画する処理。

```javascript
// UUIDの初期化（localStorage保持）
let playerUuid = localStorage.getItem('donjara_uuid');
if (!playerUuid) {
    playerUuid = crypto.randomUUID();
    localStorage.setItem('donjara_uuid', playerUuid);
}

const roomId = "abc123"; // ルームID

async function pollRoomState() {
    try {
        const response = await fetch(`data/${roomId}.json?_=${Date.now()}`);
        if (!response.ok) return;
        const roomState = await response.json();
        
        renderGame(roomState);
    } catch (e) {
        console.error("ポーリングエラー:", e);
    }
}

// 2秒おきに静的JSONをポーリング
setInterval(pollRoomState, 2000);

function renderGame(state) {
    // ステータスや捨て牌、他家の手牌枚数の描画更新
    // 自身のセッションデータから手牌を復号してスプライト描画
}
```