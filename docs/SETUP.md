# セットアップ

## 開発（VM）

### 1. 必要なパッケージ

```bash
sudo apt-get install -y poppler-utils      # pdftotext（インストール済み: 24.02.0）
```

MySQL 8.0 と PHP 8.3 はインストール済み。

### 2. DB とユーザーを作る

```bash
sudo mysql < bin/bootstrap-db.sql
```

作成される値（開発専用・localhost からのみ）:

- DB 名: `droptime`
- ユーザー: `droptime` / `Droptime_Dev_2026!`
- ポート: 3306

パスワードが記号と数字を含むのは、システムの MySQL で `validate_password`
コンポーネントが有効になっているため。単純な文字列だと `CREATE USER` が失敗する。

**sudo が使えない環境の代替** — プロジェクト専用の MySQL をポート 3307 に立てる。

```bash
bin/dev-mysql.sh start     # 初期化・起動・DB とユーザーの作成までやる
bin/dev-mysql.sh status
bin/dev-mysql.sh stop
```

この場合、データディレクトリは `/tmp/droptime-mysql` になる。AppArmor が
`mysqld` の書き込み先を制限しており `$HOME` 以下には datadir を作れないため、
VM を再起動すると消える。`config.php` の `db.port` を 3307 に変えること。

### 3. 設定ファイル

```bash
cp config/config.example.php config/config.php
```

`db.user` / `db.password` を上記に合わせ、`app.env` を `dev`、
`security.cookie_secure` を `false`（http で開発するため）にする。

合言葉のハッシュは次で生成し、`auth.password_hash` に貼る。
合言葉は運用者が決めたもの（VM と本番で同じ）。平文はリポジトリに書かず、`config.php` にはハッシュだけを書く。開発用スクリプト（`bin/dev-screenshot.php`・`bin/smoke-test.sh`）は環境変数 `DROPTIME_PARENT_PASS` / `DROPTIME_ADMIN_PASS` から読む。
本番では必ず別の合言葉にする。
管理画面を使うなら `auth.admin_password_hash` にも別の合言葉のハッシュを入れる。
空のままなら管理画面は無効。

```bash
php bin/hash-password.php
```

### 4. スキーマを作る

```bash
php bin/migrate.php --status
php bin/migrate.php
```

### 5. 起動

```bash
php -S 127.0.0.1:8787 -t public bin/dev-router.php
```

### 6. 疎通確認

認証まわりが通っているかをまとめて確認する。

```bash
bin/smoke-test.sh
```

### 7. 画面を確認するための架空データ（任意）

実データのパーサー（M4）ができるまでは、UI の確認用に架空データを入れられる。

```bash
php bin/seed-demo.php --force   # 大会系のテーブルを消して入れ直す（dev 限定）
```

### 8. スマホなど別の端末から使う（Apache・ポート 80）

`php -S` は VM の中からしか見えない。Tailscale 経由で普段使いするには、
VM の Apache に載せる。root で 1 回だけ:

```bash
sudo bash ~/droptime/deploy/vm/setup-apache.sh
```

以後 `http://<VM の Tailscale IP>/` でアクセスできる。コードの更新は
リポジトリを変えるだけで反映される（設定を変えたときだけ
`sudo systemctl restart apache2`）。Apache は yoshi ユーザーで動くので、
CLI（migrate / backup 等）と Web で var/ 以下の権限が食い違わない。

### 9. 大会結果の取り込み

速報サイトから保存した Hy-Tek の結果一式（イベントページの .htm 群）は
CLI でまとめて確認待ちに載せられる。投入は管理画面での承認が必要。

```bash
php bin/import-hytek.php var/manual/sources/2026juneperf \
    --source https://results.swimmeets.ca/2026juneperf/
```

`--source` に取得元 URL を渡すと、投入時に大会ページへ「データ出典」として表示される。

紙の結果などを手で書き写す場合は JSON でも入れられる。

```bash
php bin/import-manual.php var/manual/my-swimmer.json
```

形式は [examples/manual-import.example.json](examples/manual-import.example.json) を参照。
選手名を含むファイルは `var/manual/`（git 管理外・Web 到達不能）に置く。

## 本番（DreamHost）

[DEPLOY.md](DEPLOY.md) を参照。
