-- 携帯への通知（Web Push）の購読と、送った通知の記録。
--
-- 購読は端末（ブラウザ）ごと。お気に入りの入れ物（profiles）に結び付け、お気に入りが変われば通知も変わる。
-- endpoint はブラウザの会社の中継サーバーの URL、p256dh・auth は端末の公開鍵と共有の秘密（通知の暗号化に使う）。
-- 本番だけのデータなので、同期（bin/transfer-data.php）の対象外。

CREATE TABLE push_subscriptions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id      INT UNSIGNED NOT NULL,
    endpoint_hash   CHAR(64) NOT NULL,
    endpoint        VARCHAR(1024) NOT NULL,
    p256dh          VARCHAR(128) NOT NULL,
    auth            VARCHAR(64) NOT NULL,
    lang            VARCHAR(8) NOT NULL DEFAULT 'en',
    created_at      DATETIME NOT NULL,
    last_sent_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_push_endpoint (endpoint_hash),
    KEY ix_push_profile (profile_id),
    CONSTRAINT fk_push_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同じ知らせを 2 度送らないための記録。notice_key は「日付|選手|種目|段階」など。
CREATE TABLE push_sent (
    subscription_id INT UNSIGNED NOT NULL,
    notice_key      VARCHAR(160) NOT NULL,
    sent_at         DATETIME NOT NULL,
    PRIMARY KEY (subscription_id, notice_key),
    CONSTRAINT fk_push_sent_sub FOREIGN KEY (subscription_id) REFERENCES push_subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
