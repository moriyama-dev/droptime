-- レース時点の年齢を結果行に持たせる。
--
-- なぜ swimmers.birth_year ではないか
--   Hy-Tek の結果には「その大会時点の年齢」しか載らない。そこから生年を
--   逆算すると、大会日と誕生日の前後関係で必ず ±1 年の曖昧さが残る。
--   一方、順位の比較に要るのは「そのレースで同い年だったのは誰か」であり、
--   これは年齢そのもので過不足なく表せる。よって曖昧な逆算はせず、
--   パーサーが読み取った年齢を結果行にそのまま記録する。
--   （選手の同一人物判定は従来どおり swimmers.birth_year を使う。ここは触らない。）
--
-- リレーの行では NULL のまま。個人種目でも年齢が読めなければ NULL。

ALTER TABLE results
    ADD COLUMN age SMALLINT UNSIGNED NULL AFTER lane;

-- 同一種目の中を年齢で絞り込む問い合わせのため。
ALTER TABLE results
    ADD KEY ix_results_event_age (event_id, age);
