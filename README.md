# 絵合わせマージャン

PHP 8.3 + SQLite を使ったオンラインゲームの開発プロジェクトです。現在は、WSL上の実行環境とブラウザ表示を確認する初期画面まで用意しています。ルーム作成や対局機能は未実装です。設計は [code_artifact.md](./code_artifact.md) を参照してください。

## WSLで起動

Ubuntu 22.04の場合、PHP 8.3とSQLite拡張をインストールします。PHP 8.3はUbuntu標準リポジトリにはないため、Ondřej SurýのPHP PPAを使用します。

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3-cli php8.3-sqlite3
```

この環境で別のAPTリポジトリにエラーがあり `apt update` が失敗する場合は、そのリポジトリ設定を確認・修正してからインストールしてください。

プロジェクトのルートで起動します。

```bash
./start.sh
```

起動後、Windowsのブラウザで <http://localhost:8000> を開きます。WSLのlocalhost転送が利用できない場合は、WSLで `hostname -I` を実行し、表示されたIPアドレスを使って `http://<WSLのIP>:8000` を開いてください。

環境チェックAPIは <http://localhost:8000/health.php> です。サーバーを停止するには起動したターミナルで `Ctrl+C` を押します。

この開発サーバーはローカル開発専用です。インターネットに公開しないでください。
