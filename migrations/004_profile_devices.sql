-- 端末（ブラウザ）とお気に入りの入れ物（profiles）の結び付け。
--
-- これまでは入れ物をセッションにだけ結び付けていたので、ログアウトや 90 日の
-- 放置でその端末から入れ物が見えなくなっていた。ログインとは別の長持ちする
-- Cookie（dt_device・1 年・アクセスのたびに延長）を端末に置き、その SHA-256 を
-- ここに持つ。引き継ぎコードで繋いだ端末は、同じ profile_id を指す別の行になる。
-- Cookie の値そのものは保存しない。

CREATE TABLE profile_devices (
    device_hash     CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    profile_id      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL,
    last_seen_at    DATETIME NOT NULL,
    PRIMARY KEY (device_hash),
    KEY ix_profile_devices_profile (profile_id),
    CONSTRAINT fk_profile_devices_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
