# 本番デプロイ（DreamHost 共有ホスティング）

対象は DreamHost の共有ホスティング。root 権限・常駐プロセス・Docker は使えない前提。
以下 `~/droptime` にアプリ本体を置くものとして書く。パスは読み替えてよい。

## 0. 事前に決めること

- 公開するドメイン（例: `swim.example.com`）
- 保護者用の合言葉と、管理用の合言葉（**必ず別の文字列**）

## 1. パネルでの設定

1. **PHP バージョン**: ドメインの設定で **PHP 8.2** を選ぶ
2. **HTTPS**: Let's Encrypt を有効にする（Cookie が `Secure` なので HTTPS 必須）
3. **Web ディレクトリ**: `~/droptime/public` に向ける。
   変更できない場合は `public/` の中身をドキュメントルートに置き、
   `.htaccess` 先頭の `SetEnv DROPTIME_ROOT /home/USER/droptime` を有効にする
4. **MySQL**: パネルで DB とユーザーを作る（例: DB `droptime_prod`）。
   ホスト名はパネルに表示されるもの（`mysql.example.com` 形式）を使う

## 2. コードを置く

```bash
# 手元から（.git や var の中身は送らない）
rsync -av --exclude '.git' --exclude 'var/*' --exclude 'config/config.php' \
      ./ USER@server:~/droptime/
ssh USER@server 'mkdir -p ~/droptime/var/{uploads,logs,backups}'
```

`var/` 以下 3 ディレクトリには `.htaccess`（`Require all denied`）と
`.gitignore` が必要。rsync で送られなかった場合はリポジトリからコピーする。

## 3. 設定ファイル

```bash
cp config/config.example.php config/config.php
```

本番で必ず変える値:

| キー | 値 |
|---|---|
| `app.env` | `prod` |
| `auth.password_hash` | `php bin/hash-password.php` で**本番用の新しい合言葉**から生成 |
| `auth.admin_password_hash` | 同上（別の合言葉で） |
| `db.*` | パネルで作った DB の接続情報（`db.host` はパネル表示のホスト名） |
| `security.cookie_secure` | `true` |
| `tools.pdftotext` | `/usr/bin/pdftotext`（`which pdftotext` で確認） |
| `tools.mysqldump` | `which mysqldump` の結果。無ければそのままでよい |

合言葉の平文はリポジトリやサーバーのファイルに残さず、`config.php` にはハッシュだけを書くこと。

## 4. スキーマ構築と点検

開発のデータは持ち込まない。マイグレーションで空から作る。

```bash
cd ~/droptime
php bin/migrate.php --status
php bin/migrate.php
php bin/check-prod.php     # 全項目 OK になるまで公開しない
```

## 5. 動作確認（ブラウザ）

1. `https://ドメイン/` → ログイン画面へリダイレクトされる
2. ログイン画面の HTML に `noindex` がある（表示 → ソースを表示）
3. `https://ドメイン/robots.txt` → `Disallow: /`
4. `https://ドメイン/config/config.php` → 404 か 403（**200 になったら公開を止めて構成を見直す**）
5. `https://ドメイン/var/logs/` → 403
6. 合言葉でログイン → 空のトップが出る
7. `/admin` → 管理の合言葉 → 管理画面 → PDF を 1 つアップロードして確認画面まで進む

## 6. バックアップの cron

パネルの「Cron ジョブ」で毎日 1 回:

```bash
cd $HOME/droptime && /usr/local/bin/php bin/backup.php >> var/logs/backup.log 2>&1
```

- `php` のパスは `which php` で確認（パネルの PHP と別なことがある）
- 7 世代保持は `backup.php` が自動で行う
- `mysqldump` が無いサーバーでは `php bin/backup.php --php` に変える

月に 1 回は `var/backups/` の最新ファイルを手元にもダウンロードしておく
（サーバーごと消える事故に備える。子供の名前を含むので保管場所には注意）。

## 7. 更新のデプロイ（`deploy/dreamhost/deploy.sh`）

手順 2〜4 と更新はスクリプトにまとめてある。既定は下見で、何も変えない。

```bash
DH_SSH=USER@server deploy/dreamhost/deploy.sh                 # 下見（rsync --dry-run）
DH_SSH=USER@server deploy/dreamhost/deploy.sh --apply         # 反映
DH_SSH=USER@server deploy/dreamhost/deploy.sh --apply --data  # 初回のみ: 大会データも運ぶ
```

やること: 手元のテスト → 本番 DB のバックアップ → コードの rsync（`config.php` と
`var/` の中身は送らない・本番側のそれらは消さない）→ **出典の証拠 `var/evidence` の送付**
（本番では Chrome が動かず撮れないため）→ マイグレーション →（`--data` のとき）大会データ →
`check-prod`。`DH_DIR`（既定 `droptime`）・`DH_PHP`（既定 `php`）で置き場所と PHP を変えられる。

### 大会データを運ぶ（`--data`）

手順 4 では「開発のデータは持ち込まない」としていたが、開発環境で取り込み・確認・統合の
判定まで済ませた大会データは、`bin/transfer-data.php` で本番へ運べる。運ぶのは公開情報から
作った表（チーム・選手・大会・種目・エントリー・結果・スプリット・統合の判定）だけで、
ログイン・セッション・お気に入り・取り込み履歴は運ばない。本番に大会が 1 件でもあると
止まる（入れ替えるときは `--data-replace`。先にバックアップが取られる）。

- マイグレーションは追記のみ（適用済みファイルは編集しない）
- 更新前に `php bin/backup.php` を一度回しておくと巻き戻せる

## 8. 障害時

| 症状 | 見る場所 |
|---|---|
| 500 が出る | `var/logs/app-日付.log`（画面には詳細を出さない設計） |
| セットアップ画面が出る | `config/config.php` が無い・読めない |
| DB 接続エラー | config の `db.*`、パネルの DB ホスト名 |
| 取り込みが失敗する | 管理画面の取り込み詳細 → 失敗理由が出る。元ファイルは `var/uploads/` に残っている |

復元は「空の DB にバックアップ SQL を流す → コード再配置 → check-prod」。

## 9. 公開後の運用メモ

- 合言葉は保護者への連絡手段（LINE 等）で共有。漏れた気配があれば
  `bin/hash-password.php` で作り直して `config.php` を差し替えるだけ（全員再ログイン）
- 大会のたび: ヒートシート PDF を管理画面から取り込み → 確認 → 投入。
  結果 PDF が出たら同様に取り込み
- 選手の統合候補が溜まったら管理画面の「選手の統合」で判定
