-- DropTime 初期スキーマ
--
-- 方針
--   * 文字セットと照合順序はテーブルごとに明示する（DB 既定に依存しない）。
--   * 突き合わせ用の normalized_key だけは utf8mb4_bin。照合順序による
--     大文字小文字・アクセントの同一視に依存せず、PHP 側で正規化した文字列の
--     完全一致で判定するため。
--   * 記録用の日時（*_at）は UTC の DATETIME。PHP が値を書き込む。
--     サーバーの time_zone 設定に依存する DEFAULT CURRENT_TIMESTAMP や
--     TIMESTAMP 型は使わない。
--   * 大会の日付・時刻は現地の壁時計の値そのものなので CHAR で持つ（ISO 8601）。
--   * タイムはすべてセンチ秒の INT。NULL は「記録なし」を意味する。

-- migrations テーブルは Migrator が起動時に自動作成するため、ここには書かない。

-- ── 認証・セッション ──────────────────────────────────────────────

-- PHP のファイルセッションは共有ホスティングで 90 日保持できないため DB に置く。
CREATE TABLE auth_sessions (
    id              VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    payload         MEDIUMBLOB NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    last_activity   INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY ix_auth_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ログイン試行。IP 単位のレート制限に使う。epoch 秒で持つのでタイムゾーン非依存。
CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip              VARBINARY(16) NOT NULL,
    scope           VARCHAR(16) NOT NULL,
    succeeded       TINYINT(1) NOT NULL,
    attempted_at    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY ix_login_attempts_ip (ip, scope, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── マスタ ────────────────────────────────────────────────────────

CREATE TABLE teams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(32) NULL,
    name            VARCHAR(190) NOT NULL,
    normalized_key  VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_code (code),
    KEY ix_teams_normalized_key (normalized_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE swimmers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name       VARCHAR(190) NOT NULL,
    last_name       VARCHAR(96) NOT NULL,
    first_name      VARCHAR(96) NOT NULL,
    -- 姓名を PHP 側で正規化した文字列（小文字化・アクセント除去・記号除去・空白圧縮）。
    -- 生年やチームは含めない。含めると「名前は一致するが生年不明」という
    -- 保留候補を拾えなくなるため、突き合わせは (normalized_key, birth_year, team_id) で行う。
    normalized_key  VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    birth_year      SMALLINT UNSIGNED NULL,
    gender          CHAR(1) NULL,
    team_id         INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_swimmers_match (normalized_key, birth_year, team_id),
    KEY ix_swimmers_team (team_id),
    CONSTRAINT fk_swimmers_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
    CONSTRAINT ck_swimmers_gender CHECK (gender IS NULL OR gender IN ('M', 'F', 'X'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 大会 ──────────────────────────────────────────────────────────

CREATE TABLE meets (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(190) NOT NULL,
    host            VARCHAR(190) NULL,
    -- ISO 8601 の日付文字列。現地（America/Toronto）の暦日。
    start_date      CHAR(10) NOT NULL,
    end_date        CHAR(10) NULL,
    course          CHAR(3) NOT NULL,
    venue           VARCHAR(190) NULL,
    source_file     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_meets_start_date (start_date),
    CONSTRAINT ck_meets_course CHECK (course IN ('SCM', 'LCM', 'SCY'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 大会のセッション（午前／午後など）。アプリの認証セッションと紛らわしいため
-- 指示書の sessions ではなく meet_sessions という名前にしている。
CREATE TABLE meet_sessions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meet_id         INT UNSIGNED NOT NULL,
    day             SMALLINT UNSIGNED NOT NULL,
    name            VARCHAR(96) NULL,
    session_date    CHAR(10) NULL,
    -- 現地の壁時計の時刻 'HH:MM'。
    start_time      CHAR(5) NULL,
    PRIMARY KEY (id),
    KEY ix_meet_sessions_meet (meet_id, day),
    CONSTRAINT fk_meet_sessions_meet FOREIGN KEY (meet_id) REFERENCES meets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meet_id         INT UNSIGNED NOT NULL,
    meet_session_id INT UNSIGNED NULL,
    -- '12' だけでなく '12A' のような枝番があるため文字列で持つ。
    event_number    VARCHAR(16) NOT NULL,
    -- 予選・決勝で同じ種目番号が二度現れるため、round まで含めて一意にする。
    round           VARCHAR(8) NOT NULL,
    gender          CHAR(1) NOT NULL,
    age_group       VARCHAR(32) NULL,
    distance        SMALLINT UNSIGNED NOT NULL,
    stroke          VARCHAR(16) NOT NULL,
    course          CHAR(3) NOT NULL,
    is_relay        TINYINT(1) NOT NULL DEFAULT 0,
    -- プログラム上の並び順。種目番号が非数値でも正しく並べるために持つ。
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    -- ヒートシートに時刻の記載があれば入れる。無ければ NULL で、推定時刻を計算する。
    scheduled_time  CHAR(5) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_number (meet_id, event_number, round),
    KEY ix_events_meet_order (meet_id, sort_order),
    KEY ix_events_shape (distance, stroke, course, gender),
    CONSTRAINT fk_events_meet FOREIGN KEY (meet_id) REFERENCES meets (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_session FOREIGN KEY (meet_session_id) REFERENCES meet_sessions (id) ON DELETE SET NULL,
    CONSTRAINT ck_events_course CHECK (course IN ('SCM', 'LCM', 'SCY')),
    CONSTRAINT ck_events_gender CHECK (gender IN ('M', 'F', 'X')),
    CONSTRAINT ck_events_round CHECK (round IN ('timed', 'prelim', 'semi', 'final'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── エントリーと結果 ──────────────────────────────────────────────

CREATE TABLE entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    -- リレーの場合 swimmer_id は NULL で team_id が入る。
    swimmer_id      INT UNSIGNED NULL,
    team_id         INT UNSIGNED NULL,
    relay_letter    CHAR(1) NULL,
    heat            SMALLINT UNSIGNED NULL,
    lane            SMALLINT UNSIGNED NULL,
    seed_time_cs    INT UNSIGNED NULL,
    -- NT（ノータイム）は seed_time_cs = NULL かつ seed_is_ntime = 1 で表す。
    -- 0 秒として保存しない。
    seed_is_ntime   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entries_lane (event_id, heat, lane),
    KEY ix_entries_swimmer (swimmer_id),
    KEY ix_entries_event (event_id),
    CONSTRAINT fk_entries_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_entries_swimmer FOREIGN KEY (swimmer_id) REFERENCES swimmers (id) ON DELETE CASCADE,
    CONSTRAINT fk_entries_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE results (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    swimmer_id      INT UNSIGNED NULL,
    team_id         INT UNSIGNED NULL,
    relay_letter    CHAR(1) NULL,
    heat            SMALLINT UNSIGNED NULL,
    lane            SMALLINT UNSIGNED NULL,
    -- 失格・棄権のときは NULL。0 秒として保存しない。
    final_time_cs   INT UNSIGNED NULL,
    place           SMALLINT UNSIGNED NULL,
    -- OK=計時成立, DQ=失格, NS=不出場, DFS=失格(スタート前), SCR=スクラッチ。
    -- 指示書の is_dq は status = 'DQ' に相当する。DQ 以外の非計時状態が
    -- 必ず出るため、真偽値ではなく状態として持っている。
    status          VARCHAR(4) NOT NULL DEFAULT 'OK',
    dq_reason       VARCHAR(190) NULL,
    points          DECIMAL(6,1) NULL,
    PRIMARY KEY (id),
    KEY ix_results_event (event_id, place),
    KEY ix_results_swimmer (swimmer_id),
    CONSTRAINT fk_results_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_swimmer FOREIGN KEY (swimmer_id) REFERENCES swimmers (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
    CONSTRAINT ck_results_status CHECK (status IN ('OK', 'DQ', 'NS', 'DFS', 'SCR')),
    CONSTRAINT ck_results_time CHECK (status <> 'OK' OR final_time_cs IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE splits (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    result_id       INT UNSIGNED NOT NULL,
    distance        SMALLINT UNSIGNED NOT NULL,
    -- 累積タイム。区間タイムは表示時に引き算で出す。
    cumulative_time_cs INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_splits_distance (result_id, distance),
    CONSTRAINT fk_splits_result FOREIGN KEY (result_id) REFERENCES results (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 保護者のお気に入り ────────────────────────────────────────────

-- 端末をまたいで引き継ぐための「引き継ぎコード」。コード自体は保存せず
-- SHA-256 のみを保存する（コードは乱数由来なのでソルトは不要）。
CREATE TABLE profiles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_hash        CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    label           VARCHAR(64) NULL,
    created_at      DATETIME NOT NULL,
    last_seen_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_profiles_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id      INT UNSIGNED NOT NULL,
    swimmer_id      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_favorites (profile_id, swimmer_id),
    KEY ix_favorites_swimmer (swimmer_id),
    CONSTRAINT fk_favorites_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_swimmer FOREIGN KEY (swimmer_id) REFERENCES swimmers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 取り込み ──────────────────────────────────────────────────────

CREATE TABLE imports (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_name   VARCHAR(255) NOT NULL,
    -- var/uploads 以下の相対パス。元ファイルは再処理のため保存し続ける。
    raw_path        VARCHAR(255) NOT NULL,
    -- pdftotext で起こしたテキストの保存先（HTML の場合は抽出後のテキスト）。
    text_path       VARCHAR(255) NULL,
    sha256          CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    byte_size       INT UNSIGNED NOT NULL,
    mime            VARCHAR(96) NOT NULL,
    meet_id         INT UNSIGNED NULL,
    parser          VARCHAR(64) NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'uploaded',
    -- パース結果の要約と未認識行を JSON 文字列で保存する。
    -- JSON 型は環境差があるため LONGTEXT で持つ。
    summary_json    LONGTEXT NULL,
    uploaded_at     DATETIME NOT NULL,
    committed_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_imports_status (status, uploaded_at),
    KEY ix_imports_sha256 (sha256),
    CONSTRAINT fk_imports_meet FOREIGN KEY (meet_id) REFERENCES meets (id) ON DELETE SET NULL,
    CONSTRAINT ck_imports_status CHECK (status IN ('uploaded', 'extracted', 'parsed', 'committed', 'failed', 'discarded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
