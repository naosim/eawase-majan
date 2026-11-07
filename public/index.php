<?php
declare(strict_types=1);

$checks = [
    'PHP' => PHP_VERSION,
    'SQLite (PDO)' => extension_loaded('pdo_sqlite') ? '利用可能' : '未導入',
    'json_validate()' => function_exists('json_validate') ? '利用可能' : '利用不可',
];
$environmentReady = version_compare(PHP_VERSION, '8.3.0', '>=')
    && extension_loaded('pdo_sqlite')
    && function_exists('json_validate');
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123c31">
    <link rel="icon" href="data:,">
    <title>絵合わせマージャン</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main class="page">
        <header class="topbar">
            <a class="brand" href="/" aria-label="絵合わせマージャン ホーム">
                <span class="brand-mark" aria-hidden="true">絵</span>
                <span>絵合わせマージャン</span>
            </a>
            <span class="environment-badge">
                <span class="status-dot <?= $environmentReady ? 'is-ready' : 'is-error' ?>"></span>
                <?= $environmentReady ? '開発環境 起動中' : '環境設定を確認してください' ?>
            </span>
        </header>

        <section class="hero">
            <p class="eyebrow">ONLINE TILE GAME</p>
            <h1>絵をそろえて、<br>みんなで遊ぼう。</h1>
            <p class="intro">
                ドンジャラ風オンラインゲームの開発画面です。まずはWindowsのブラウザから
                WSL上のPHPアプリに接続できることを確認できます。
            </p>
        </section>

        <section class="workspace" aria-labelledby="preview-title">
            <div class="table-preview">
                <div class="table-label">
                    <span class="table-dot"></span>
                    <span>テーブル画面プレビュー</span>
                </div>
                <div class="table-center">
                    <div class="tile-stack" aria-hidden="true">
                        <span class="tile tile-red">花</span>
                        <span class="tile tile-gold">星</span>
                        <span class="tile tile-blue">鳥</span>
                    </div>
                    <p>ゲーム画面を準備しています</p>
                    <span class="table-caption">ルーム・対局機能はこれから実装します</span>
                </div>
                <div class="table-players" aria-label="対局人数のイメージ">
                    <span>プレイヤー 1</span>
                    <span>プレイヤー 2</span>
                    <span>プレイヤー 3</span>
                    <span>プレイヤー 4</span>
                </div>
            </div>

            <aside class="setup-card" aria-labelledby="preview-title">
                <p class="eyebrow">FIRST CHECK</p>
                <h2 id="preview-title">接続できました</h2>
                <p class="card-copy">
                    このページが表示されていれば、ブラウザからWSLの開発サーバーに接続できています。
                </p>

                <dl class="checks">
                    <?php foreach ($checks as $label => $value): ?>
                        <div class="check-row">
                            <dt><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <p class="next-step">
                    次はルーム作成、牌山の生成、ターン進行の順に実装していきます。
                </p>
                <a class="health-link" href="/health.php">環境状態をJSONで確認 <span aria-hidden="true">↗</span></a>
            </aside>
        </section>

        <footer class="footer">
            <span>PHP 8.3 · SQLite · Vanilla JavaScript</span>
            <span>ローカル開発用</span>
        </footer>
    </main>
</body>
</html>
