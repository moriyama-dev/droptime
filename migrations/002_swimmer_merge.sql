-- 選手の同一人物判定の保留と、取り消し可能な統合の記録。
--
-- 突き合わせは (normalized_key, birth_year, team_id) の完全一致を基本とし、
-- 自動で結び付けるのは信頼度が高い場合だけ。曖昧なものは別人として登録した
-- うえで「候補」に積み、管理画面で人間が判定する。
-- 誤った統合は記録が壊れて発見しにくいため、統合時に移した行の ID を
-- JSON で保存し、そのまま巻き戻せるようにする。

CREATE TABLE swimmer_merge_candidates (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 新しく作られたほう（取り込みで登録された側）。
    swimmer_id      INT UNSIGNED NOT NULL,
    -- 既存の似ている選手。
    candidate_id    INT UNSIGNED NOT NULL,
    -- なぜ自動で結び付けなかったか。管理画面にそのまま表示する。
    reason          VARCHAR(190) NOT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL,
    resolved_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_merge_candidates_pair (swimmer_id, candidate_id),
    KEY ix_merge_candidates_status (status, created_at),
    CONSTRAINT fk_merge_candidates_swimmer FOREIGN KEY (swimmer_id) REFERENCES swimmers (id) ON DELETE CASCADE,
    CONSTRAINT fk_merge_candidates_candidate FOREIGN KEY (candidate_id) REFERENCES swimmers (id) ON DELETE CASCADE,
    CONSTRAINT ck_merge_candidates_status CHECK (status IN ('pending', 'merged', 'dismissed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE swimmer_merges (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 残したほうの選手。
    kept_id         INT UNSIGNED NOT NULL,
    -- 消したほうの選手のスナップショット。取り消し時にこの内容で復元する。
    -- removed_id は復元後に変わるため、参照制約は張らない。
    removed_id      INT UNSIGNED NOT NULL,
    removed_json    LONGTEXT NOT NULL,
    -- 統合で kept 側に付け替えた行の ID（entries / results / favorites）。
    moved_json      LONGTEXT NOT NULL,
    merged_at       DATETIME NOT NULL,
    undone_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_swimmer_merges_kept (kept_id, merged_at),
    CONSTRAINT fk_swimmer_merges_kept FOREIGN KEY (kept_id) REFERENCES swimmers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
