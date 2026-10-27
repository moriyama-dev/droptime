# DropTime（このリポジトリで作業する Claude へ）

子どもたちが通う水泳クラブ（クラブ名は git に入れない）の記録を、保護者と選手に見せる Web アプリ。PHP 8.2・MySQL 8・依存なし。
使い方と構成は README.md、本番への反映は docs/DEPLOY.md。

## 公開リポジトリと個人情報（必ず守る）

このリポジトリは **GitHub で public**（moriyama-dev/droptime）。一方でアプリが扱うのは、実在の子どもたちの名前・年齢・記録。
**個人名や個人情報を git に入れない。GitHub に出たものは消しても残る前提で考える。**

- 子どもたちの名前・記録・出典の証拠・設定は `var/` と `config/config.php` だけに置く（どちらも .gitignore 済み）。
- テストデータ（tests/fixtures・tests/cases）には**架空の名前**を使う。実際の大会結果から新しいフィクスチャを作るときは、
  先に名前を架空のものへ置き換える（文字数を変えない。Hy-Tek は固定幅）。説明書の例は `Doe, Jane` のような見本の名前にする。
- コミットメッセージ・Issue・PR にも実名を書かない。選手は「選手 ID 123」のように書く。
- 「問題を報告」で開く Issue の本文は、版・画面のパス・ブラウザ・言語・日時だけ（`src/Support/IssueReport.php`）。名前を足さない。

### 見張り（自動）

`bin/privacy-check.php` が、DB の全選手の姓名と `var/privacy/extra.txt` の語（家族の名前・子どもが通うクラブの名前など・git 管理外）を探し、
機密情報の型（秘密鍵・トークン・API キー・パスワードのハッシュ・Tailscale の IP・空でないパスワードの設定・個人のメールアドレス）と、
公開してはいけないファイル（config.php・var/ の中身・ダンプ・.env・鍵ファイル）も止める。規則は `src/Support/PrivacyRules.php`。git のフックから毎回動く:

- `.githooks/pre-commit` → `--staged`（コミットに入る内容）
- `.githooks/pre-push` → `--push <範囲>`（push する全コミットの差分・メッセージ・先端のツリー）

作業を始めたら、フックが有効か確かめる。出力が `.githooks` でなければ有効にする:

```bash
git config core.hooksPath          # → .githooks
git config core.hooksPath .githooks
```

- 見張りが止めたら、**`--no-verify` で回避しない**。名前を架空のものにするか、ファイルを git 管理から外して直す。
- DB に接続できないと見張りは止まる（確かめられないものは公開しない）。開発用 MySQL を起動してからやり直す。
- 新しい VM で clone したときも、上の 2 行でフックを有効にし、`var/privacy/extra.txt` を作る（中身は Yoshi に聞く）。
- 公開前の点検は `php bin/privacy-check.php --all`。
- `var/privacy/extra.txt` が無い・空なら止まる。クラブ名は略称も正式名も入れる。足すときは `>>` で（消す・空にするのは Claude のフックが止める）。
- 公開してよいと確かめた行（見本・ダミー）だけ、行に `privacy-check: allow` と理由を書いて外す。
- クラブのコードは `config/config.php` の `fetch.club_code` にだけ書く。コード・説明書・テストでは架空の `ZORC` を使う。
- コミットの作成者のメールは GitHub の非公開用（`git config user.email` → `…@users.noreply.github.com`）。個人のアドレスのコミットは push で止まる。
- コミットメッセージに見張りの設定名やオプション名を書くと Claude のフックに止められることがある。メッセージはファイルに書いて `git commit -F` で渡す。

### VM の外から入る道の見張り

- **GitHub Actions**（`.github/workflows/privacy.yml`・`bin/privacy-ci.php`）: GitHub の Web 画面での編集など、VM のフックを通らない変更を
  push の直後に見つけて失敗の通知を出す（公開は止められない。見つけてすぐ伏せるための合図）。語は平文で渡さず、
  秘密の値 `PRIVACY_TERMS` に塩付きハッシュだけを置く。語を変えたら `bin/privacy-sync-github.sh` で登録し直す（Yoshi の許可が要る）。
- **Claude Code のフック**（`bin/claude-guard.sh`・`~/.claude/settings.json` の PreToolUse）: フックの省略・無効化、強制 push、
  語の一覧の削除を止め、`gh` で GitHub に出す文章（Issue・PR など）も見張りにかける。
- このリポジトリのファイルを GitHub の Web 画面で直接編集しない。Wiki とプロジェクトは無効にしてある。
- 強制 push（履歴の書き換え）は Yoshi の OK があるときだけ。2026-10-27 に一度、クラブ名と個人のメールを履歴から消した。

## 全アプリ共通のルール

- 画面のフッターに「問題を報告」と「GitHub」を置く（GBrain の `ops/app-feedback-buttons-standard`）。
  DropTime は保護者・選手の画面にだけ出し、管理画面には出さない。
- 日時はトロント時間（America/Toronto）で書く。

## 大会データと本番の自動同期

- 大会データの正は **VM**。取得・取り込み・選手の統合は VM で行う。
- VM の cron（10 分ごと・`# droptime-sync-prod`）が `bin/sync-prod.sh` を動かし、データが変わっていれば本番（DreamHost の MySQL）へ自動で運ぶ。
  運ぶ前に本番の DB をバックアップする。Yoshi の確認は待たない（2026-10-26 Yoshi の指示）。ログは `var/logs/sync-prod.log`。
- 本番の管理画面で取り込んだデータは、次の同期で VM の内容に置き換わる。
- コードの反映は別で、`DH_SSH=droptime-prod DH_DIR=droptime.takumi.ca deploy/dreamhost/deploy.sh --apply`。
