# テスト用フィクスチャ

`<パーサー名>.<ケース名>.in.txt`（入力）と `.expected.json`（期待する結果）の対。`tests/cases/ParserFixturesTest.php` が全件を回す。

## 名前は架空

入力は実際の大会結果（Hy-Tek MEET MANAGER の結果ページ）の書式から作ったが、**選手名はすべて架空の名前に置き換え済み**で、
実在の人物とは関係ない。Hy-Tek の表は固定幅なので、名前は元と同じ文字数にしてあり、タイム・年齢・チーム・並びは書式の検証のためにそのまま残している。

新しいフィクスチャを足すときも、先に名前を架空のものへ置き換えてから入れる（リポジトリ直下の CLAUDE.md「公開リポジトリと個人情報」）。
実名が残っていると、コミットの前に `bin/privacy-check.php` が止める。

> All names in these fixtures are fictitious (same length as the originals, because Hy-Tek output is fixed-width).
