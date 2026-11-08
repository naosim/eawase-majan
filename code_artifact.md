# 絵合わせマージャン（ドンジャラ風オンラインゲーム） システム設計書

## 1. システム概要とアーキテクチャ

### 1.1 システムの目的
本システムは、PHP 8.3、SQLite、および静的JSONポーリングを組み合わせた、軽量でサーバー負荷の極めて低いブラウザ対戦型「絵合わせマージャン（ドンジャラ風ゲーム）」である。リアルタイム通信（WebSocket等）を使用せず、非同期のファイル読み込み（ポーリング）とクライアントサイド暗号化によって、セキュアかつスムーズな2〜4人対戦を実現する。

### 1.2 技術スタック
* **サーバーサイド:** PHP 8.3 (Strict Types, `json_validate()` 活用), SQLite 3 (PDO)
* **クライアントサイド:** Vanilla JavaScript (ES Modules), HTML5 / CSS3, CryptoJS (クライアント側暗号化・復号用)
* **ツール・エディタ:** 完全静的 HTML / Vanilla JS (`editor.html`)
* **インフラ・環境:** Linux (ローカル開発環境は Windows WSL 上の PHP 8.3 ビルトインサーバー)
* **データ永続化・通信:** SQLite（マスタ・ルーム・プレイヤー管理）, 静的JSONファイル（ポーリング用配信）

### 1.3 アーキテクチャとファイル構成
フロントエンドは静的 HTML/JS をエントリーポイントとし、状態取得は `data/` 配下の静的 JSON ファイルを直接 fetch して行う。状態変更（牌を捨てる、ロン、パス等）のみ `action.php` に POST リクエストを送信する。

```text
[ プレイヤーブラウザ (index.html + Vanilla JS) ]
    │ (fetch / 静的ファイルポーリング: 2秒間隔)
    ▼
[ data/room_xxx.json ] <── (書き出し) ── [ サーバーサイド API (action.php) ]
    ▲                                              │ (PDO SQLite3)
    │ (アクション送信 POST)                        ▼
[ プレイヤーブラウザ (index.html) ] ─────────> [ SQLite (data/donjara.db) ]

[ スキン作成エディタ (editor.html) ] (完全にクライアントローカルの静的HTMLで完結)
```

#### ディレクトリ構造
```text
ehawase-mahjong/
├── index.html        # ゲーム画面エントリーポイント (HTML + Vanilla JS + CryptoJS)
├── editor.html       # スキン作成・編集用スタンドアロン静的HTML
├── action.php        # サーバーAPI (PHP 8.3 / SQLite更新 / JSON書き出し)
├── db_init.php       # DB初期化スクリプト
└── data/             # データ格納ディレクトリ (書き込み権限必要)
    ├── donjara.db    # SQLiteデータベース
    └── room_*.json   # ポーリング用の静的JSON（自動生成）
```

---

## 2. 基本設計（ゲームルール・ネットワーク仕様）

### 2.1 ゲーム基本ルール
* **人数:** 2〜4人（可変対応）
* **上がり条件:** 同じ絵柄の3枚組（刻子）× 3セット（合計9枚）を揃え、かつ**「リーチ」を宣言していること**が上がりの必須条件となる。
* **オールマイティ（特殊牌）:** 山札中に**2枚限定**で存在する万能牌（ジョーカー）。任意の絵柄の代わりとして役構成に使用できる。
* **ポン:** 自分のターンが回ってきた際、直前プレイヤーが捨てた牌が自分の手牌とセットを構成できる場合、取得して手牌に組み込める。
* **ロン:** 他プレイヤーの捨て牌で上がり形が完成する場合、割り込みでロンを宣言可能。対象者の画面に「上がりますか？」が出現し、応答（またはタイムアウト）まで全体進行が停止（`ron_wait` 状態）する。
* **リーチ:** テンパイ（あと1枚で上がり完成）時、「リーチしますか？」のダイアログを表示する。リーチ成立後は、ツモった牌をそのまま捨てる（ツモ切り）か、上がるかの2択に操作が固定される。

### 2.2 離脱・切断・再接続（リカバリー）設計
* **UUID自動識別:** 初回アクセス時に JavaScript 側で `crypto.randomUUID()` を生成し、`localStorage` に保存する。対戦中にリロードやブラウザ閉じが発生しても、同一URL（`?room=xxx`）にアクセスすれば同UUIDで自動復帰する。
* **タイムアウトと自動進行:** 各状態に `deadline_at`（Unixタイムスタンプ）を設定する。
  * **通常ターンタイムアウト:** 制限時間切れで PHP 側で自動的にツモ切り（引き牌をそのまま捨てる）を実行し、ターンを自動進行させる。
  * **ロン待ちタイムアウト:** 制限時間切れで自動的に「パス」扱いとし、ゲームを進行させる。

---

## 3. インターフェース設計（データ構造・API・スキン）

### 3.1 SQLite データベーススキーマ (`donjara.db`)

#### `rooms` テーブル
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `room_id` | TEXT (PK) | ルーム識別ID (例: `room_abc123`) |
| `status` | TEXT | `playing` (通常), `ron_wait` (ロン確認待ち), `finished` (終了) |
| `current_turn_seat` | INTEGER | 現在ターンのプレイヤー席番号 (0〜3) |
| `ron_target_seat` | INTEGER | ロン確認中対象の席番号 |
| `pending_tile` | TEXT | ロン対象となっている捨て牌ID |
| `tile_deck` | TEXT | 残り山札のJSON配列 (オールマイティ2枚含む) |
| `discard_pile` | TEXT | 場の捨て牌（川）のJSON配列 |
| `discard_history` | TEXT | 捨てた順に並ぶ `{seat_number, tile_id}` のJSON配列 |
| `deadline_at` | INTEGER | 自動進行用のタイムアウトUnixタイムスタンプ |
| `updated_at` | INTEGER | 最終更新Unixタイムスタンプ |

#### `players` テーブル
| カラム名 | 型 | 説明 |
| :--- | :--- | :--- |
| `player_id` | INTEGER (PK) | 自動採番ID |
| `room_id` | TEXT | ルームID (`rooms.room_id` 参照) |
| `seat_number` | INTEGER | 席番号 (0〜3) |
| `player_uuid` | TEXT | `localStorage` に保持されたクライアントUUID |
| `name` | TEXT | プレイヤー名 |
| `score` | INTEGER | 現在のスコア |
| `is_riichi` | INTEGER | 0: 通常, 1: リーチ中 |
| `hand` | TEXT | プレイヤーの手牌JSON配列（サーバーのみ保持） |

---

### 3.2 スプライトシート型・1ファイルスキン仕様 (`skin.json`)
スプライトシート画像を Base64 エンコードして単一 JSON 内に埋め込み、フロントエンド（HTML/CSS）側で `background-position` を用いて切り出し描画を行う。

```json
{
  "skin_id": "dot_animals_sheet",
  "skin_name": "ドット絵アニマルズ",
  "author": "Naoshimu",
  "description": "1枚のスプライトシートから切り出すスキン仕様",
  "sheet_image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...",
  "tile_size": { "width": 64, "height": 64 },
  "tiles": {
    "A1": { "name": "ライオン", "category": "赤", "x": 0, "y": 0, "is_wildcard": false },
    "A2": { "name": "ゾウ",     "category": "赤", "x": 64, "y": 0, "is_wildcard": false },
    "J1": { "name": "オールマイティ1", "category": "特殊", "x": 128, "y": 0, "is_wildcard": true },
    "J2": { "name": "オールマイティ2", "category": "特殊", "x": 192, "y": 0, "is_wildcard": true }
  }
}
```

---

### 3.3 クライアント配信用静的JSON仕様 (`data/{room_id}.json`)
`action.php` によって生成されるポーリング対象ファイル。他人の手牌は各プレイヤーの UUID を秘密鍵として AES-128-CBC 暗号化（`encrypted_hand`）され、不正閲覧を防ぐ。

```json
{
  "room_id": "room_abc123",
  "status": "playing",
  "current_turn_seat": 0,
  "ron_target_seat": null,
  "discard_pile": ["A1", "B2"],
  "discard_history": [
    { "seat_number": 0, "tile_id": "A1" },
    { "seat_number": 1, "tile_id": "B2" }
  ],
  "deadline_at": 1773000030,
  "updated_at": 1773000000,
  "players": [
    {
      "seat_number": 0,
      "name": "プレイヤー1",
      "score": 25000,
      "is_riichi": 1,
      "hand_count": 9,
      "encrypted_hand": "U2FsdGVkX1+..."
    }
  ]
}
```

---

## 4. 詳細設計・プログラム実装例

### 4.1 静的スキンエディタ: `editor.html`
サーバー処理を介さず、ブラウザ上で画像を Base64 変換し、スプライト座標を設定して 1 ファイルの JSON を生成・インポートするスタンドアロン HTML。

```html
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>絵合わせマージャン - スキンエディタ</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f9; padding: 20px; }
        .container { display: flex; gap: 20px; max-width: 1200px; margin: 0 auto; }
        .panel { background: #fff; padding: 20px; border-radius: 8px; flex: 1; }
        #canvas-container { border: 2px dashed #ccc; display: inline-block; overflow: auto; max-height: 400px; }
        canvas { cursor: crosshair; }
    </style>
</head>
<body>
    <h1>絵合わせマージャン 静的スキンエディタ</h1>
    <div class="container">
        <div class="panel">
            <label>スキンID: <input type="text" id="skinId" value="custom_skin"></label><br>
            <label>牌幅(px): <input type="number" id="tileWidth" value="64"></label>
            <label>牌高(px): <input type="number" id="tileHeight" value="64"></label>
            <hr>
            <h3>タイル定義追加</h3>
            <label>タイルID: <input type="text" id="tileId" value="A1"></label><br>
            <label>タイル名: <input type="text" id="tileName" value="ライオン"></label><br>
            <label>X座標: <input type="number" id="tileX" value="0"></label>
            <label>Y座標: <input type="number" id="tileY" value="0"></label><br>
            <label><input type="checkbox" id="isWildcard"> オールマイティ牌</label><br>
            <button onclick="addTile()">タイル追加</button>
        </div>
        <div class="panel">
            <input type="file" id="imgLoader" accept="image/*" onchange="loadImage(event)"><br>
            <div id="canvas-container"><canvas id="cvs"></canvas></div><br>
            <button onclick="exportJson()">JSONエクスポート</button>
        </div>
    </div>
    <script>
        let base64Img = "", tiles = {}, cvs = document.getElementById('cvs'), ctx = cvs.getContext('2d');
        function loadImage(e) {
            let reader = new FileReader();
            reader.onload = (evt) => {
                base64Img = evt.target.result;
                let img = new Image();
                img.onload = () => { cvs.width = img.width; cvs.height = img.height; ctx.drawImage(img, 0, 0); };
                img.src = base64Img;
            };
            reader.readAsDataURL(e.target.files[0]);
        }
        cvs.addEventListener('click', (e) => {
            let rect = cvs.getBoundingClientRect();
            let tw = parseInt(document.getElementById('tileWidth').value) || 64;
            let th = parseInt(document.getElementById('tileHeight').value) || 64;
            document.getElementById('tileX').value = Math.floor((e.clientX - rect.left) / tw) * tw;
            document.getElementById('tileY').value = Math.floor((e.clientY - rect.top) / th) * th;
        });
        function addTile() {
            let id = document.getElementById('tileId').value;
            tiles[id] = {
                name: document.getElementById('tileName').value,
                x: parseInt(document.getElementById('tileX').value),
                y: parseInt(document.getElementById('tileY').value),
                is_wildcard: document.getElementById('isWildcard').checked
            };
        }
        function exportJson() {
            let data = {
                skin_id: document.getElementById('skinId').value,
                sheet_image: base64Img,
                tile_size: { width: parseInt(document.getElementById('tileWidth').value), height: parseInt(document.getElementById('tileHeight').value) },
                tiles: tiles
            };
            let a = document.createElement('a');
            a.href = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(data, null, 2));
            a.download = data.skin_id + ".json";
            a.click();
        }
    </script>
</body>
</html>
```

---

### 4.2 サーバーサイドAPI: `action.php` (PHP 8.3)
PHP 8.3 の `json_validate()` および PDO Strict Type 処理を活用した状態更新処理。

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
if (!json_validate($rawInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode($rawInput, true);
$action = $input['action'] ?? '';
$roomId = $input['room_id'] ?? '';
$playerUuid = $input['player_uuid'] ?? '';

try {
    $db = new PDO('sqlite:' . __DIR__ . '/data/donjara.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    switch ($action) {
        case 'discard_tile':
            handleDiscard($db, $roomId, $playerUuid, (int)($input['tile_index'] ?? 0), (bool)($input['is_riichi'] ?? false));
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
    // ターンの権利チェック、手牌削除、捨て牌川追加、ロン判定およびタイムアウト更新
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
        // 各プレイヤーのUUIDを鍵として手牌をAES-128-CBC暗号化
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

### 4.3 フロントエンド・クライアント処理: `index.html`
画面エントリーポイント。静的JSONの定期取得（ポーリング）、UUIDを用いた手牌復号、およびスプライト描画を担当。

```html
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>絵合わせマージャン</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <style>
        .tile { display: inline-block; border: 1px solid #ccc; border-radius: 4px; }
        .hand-container { display: flex; gap: 5px; margin-top: 20px; }
    </style>
</head>
<body>
    <div id="game-board">
        <h2>絵合わせマージャン 盤面</h2>
        <div id="players-area"></div>
        <div id="my-hand" class="hand-container"></div>
    </div>

    <script>
        let playerUuid = localStorage.getItem('donjara_uuid');
        if (!playerUuid) {
            playerUuid = crypto.randomUUID();
            localStorage.setItem('donjara_uuid', playerUuid);
        }

        const urlParams = new URLSearchParams(window.location.search);
        const roomId = urlParams.get('room') || 'room_default';
        let currentSkin = null;

        async function poll() {
            try {
                const res = await fetch(`data/${roomId}.json?_=${Date.now()}`);
                if (res.ok) {
                    const data = await res.json();
                    renderBoard(data);
                }
            } catch (e) {
                console.error("Polling error", e);
            }
        }

        function decryptHand(encryptedStr, uuid) {
            // PHP側の OpenSSL 暗号化（sha256ハッシュ先頭16文字鍵）に対応する CryptoJS 復号
            const key = CryptoJS.enc.Utf8.parse(CryptoJS.SHA256(uuid).toString().substring(0, 16));
            const iv = CryptoJS.enc.Utf8.parse(CryptoJS.SHA256('donjara-salt-8.3').toString().substring(0, 16));
            const decrypted = CryptoJS.AES.decrypt(encryptedStr, key, { iv: iv, mode: CryptoJS.mode.CBC });
            return JSON.parse(decrypted.toString(CryptoJS.enc.Utf8));
        }

        function renderBoard(state) {
            const myPlayer = state.players.find(p => {
                try {
                    const hand = decryptHand(p.encrypted_hand, playerUuid);
                    return Array.isArray(hand);
                } catch(e) { return false; }
            });

            if (myPlayer) {
                const handTiles = decryptHand(myPlayer.encrypted_hand, playerUuid);
                const container = document.getElementById('my-hand');
                container.innerHTML = '';
                handTiles.forEach((tileId, idx) => {
                    const el = document.createElement('div');
                    el.className = 'tile';
                    el.textContent = tileId;
                    container.appendChild(el);
                });
            }
        }

        setInterval(poll, 2000);
    </script>
</body>
</html>
```