-- 種目の所要時間の実測。予定時刻の見積もりを、回を重ねるごとに実際のデータで正確にしていくための蓄え。
--
-- 大会当日、本番サーバーが数分おきに速報サイトを見て、各種目の結果が「初めて載った時刻」を
-- live_event_status に残す。同じセッションで続けて泳いだ 2 つの種目の時刻の差を、あとの種目の所要時間とみなし、
-- ここに 1 種目 1 行で置く（セッションの最初の種目は、ウォームアップや開始の遅れが混ざるので置かない）。
--
-- 見積もりに使うのは「同じ会場」かつ「同じ種目・同じ距離」の行だけ。会場は meets.venue（無ければ、
-- 施設の名前と分かる meets.host）で、読むときに meets から引く（あとから会場を登録・訂正しても効くように）。
--
-- 人数が違えば所要時間も違うので、実測（actual_seconds）と一緒に、そのときの計算式での見積もり
--（model_seconds）と人数（swimmers）を残す。見積もりは「計算式 ×（実測 ÷ 計算式）の中央値」で出す。
--
-- 本番で書かれ、VM から本番への大会データの同期（bin/transfer-data.php）の対象外。
-- 同期で meets が入れ替わっても消えないよう、外部キーは張らない。

CREATE TABLE event_timings (
    meet_id         INT UNSIGNED NOT NULL,
    event_number    VARCHAR(16) NOT NULL,
    round           VARCHAR(8) NOT NULL,
    session_date    CHAR(10) NOT NULL,
    stroke          VARCHAR(16) NOT NULL,
    distance        SMALLINT UNSIGNED NOT NULL,
    is_relay        TINYINT(1) NOT NULL DEFAULT 0,
    -- 泳いだ人数（リレーはチーム数）。
    swimmers        SMALLINT UNSIGNED NOT NULL,
    -- 実測: 前の種目の結果が載ってから、この種目の結果が載るまで（秒）。
    actual_seconds  INT UNSIGNED NOT NULL,
    -- そのときの計算式（エントリータイムと組の入れ替え）での見積もり（秒）と、区切りに使ったレーン数。
    model_seconds   INT UNSIGNED NOT NULL,
    lanes           TINYINT UNSIGNED NOT NULL,
    -- 終わった時刻（現地の壁時計）と、この行を書いた時刻（UTC）。
    finished_at     DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (meet_id, event_number, round),
    KEY ix_event_timings_key (stroke, distance, is_relay)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
