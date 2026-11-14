-- 大会当日のカウントダウン。
--
-- 1. 速報サイトの目次（evtindex.htm）にあるセッション（"Session 2A - 1:45 PM / Friday 11/13/2026"）と
--    その中の種目の並びを、meet_sessions と events.meet_session_id / session_position に持つ。
--    セッションの開始時刻から種目の所要時間を積み上げて、各種目の開始時刻を見積もる。
--    予選と決勝のプールが並行する大会（1A・1B）があるので、積み上げはセッションごとに行う。
-- 2. 当日は本番サーバーが数分おきに「最後に終わった種目」のページを読み、終わった時刻を
--    live_event_status に残す。見積もりをその時刻に合わせ直す（実際の進み具合で補正する）。
--
-- live_event_status は本番で書かれ、VM から本番への大会データの同期（bin/transfer-data.php）の対象外。
-- 同期のたびに events の ID が変わり得るので、種目は ID ではなく (meet_id, event_number, round) で指す。
-- 同期で meets が入れ替わっても消えないよう、外部キーは張らない。

ALTER TABLE meet_sessions
    ADD COLUMN code VARCHAR(16) NULL AFTER day,
    ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER start_time;

ALTER TABLE events
    ADD COLUMN session_position SMALLINT UNSIGNED NULL AFTER meet_session_id;

CREATE TABLE live_event_status (
    meet_id         INT UNSIGNED NOT NULL,
    event_number    VARCHAR(16) NOT NULL,
    round           VARCHAR(8) NOT NULL,
    -- 結果のページに印字された時刻（現地の壁時計 'YYYY-MM-DD HH:MM:SS'）。終わった時刻とみなす。
    finished_at     DATETIME NOT NULL,
    -- 本番がそれを見つけた時刻（UTC）。
    observed_at     DATETIME NOT NULL,
    PRIMARY KEY (meet_id, event_number, round),
    KEY ix_live_event_status_meet (meet_id, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
