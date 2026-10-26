# DropTime（このリポジトリで作業する Claude へ）

Zorc 水泳クラブの子どもたちの記録を、保護者と選手に見せる Web アプリ。PHP 8.2・MySQL 8・依存なし。
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

`bin/privacy-check.php` が、DB の全選手の姓名と `var/privacy/extra.txt` の語（家族の名前など・git 管理外）を探し、
公開してはいけないファイル（config.php・var/ の中身・ダンプ）も止める。git のフックから毎回動く:

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

## 全アプリ共通のルール

- 画面のフッターに「問題を報告」と「GitHub」を置く（GBrain の `ops/app-feedback-buttons-standard`）。
  DropTime は保護者・選手の画面にだけ出し、管理画面には出さない。
- 日時はトロント時間（America/Toronto）で書く。
