-- 大会当日、お気に入りの選手が泳いだ種目の結果を、本番が速報サイトから直接読んで置く場所。
--
-- 正式な結果（results）は VM が毎時取り込み、10 分おきの同期で本番へ運ぶ。それを待つと
-- 泳いでから画面に出るまで最大で約 1 時間 10 分かかるので、本番の cron（bin/live-progress.php）が
-- 3 分おきの確認のついでに、泳ぎ終わった種目のページを読んでここに書く。
-- ホームの「この大会の泳ぎ」は、results に無いものだけをここから補う。
--
-- live_event_status と同じく同期（bin/transfer-data.php）の対象外で、外部キーは張らない。
-- 種目は (meet_id, event_number, round)、選手は swimmers.id で指す（同期で ID は変わらない）。

CREATE TABLE live_results (
    meet_id         INT UNSIGNED NOT NULL,
    event_number    VARCHAR(16) NOT NULL,
    round           VARCHAR(8) NOT NULL,
    swimmer_id      INT UNSIGNED NOT NULL,
    final_time_cs   INT UNSIGNED NULL,
    place           SMALLINT UNSIGNED NULL,
    status          VARCHAR(4) NOT NULL DEFAULT 'OK',
    age             SMALLINT UNSIGNED NULL,
    observed_at     DATETIME NOT NULL,
    PRIMARY KEY (meet_id, event_number, round, swimmer_id),
    KEY ix_live_results_swimmer (swimmer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
