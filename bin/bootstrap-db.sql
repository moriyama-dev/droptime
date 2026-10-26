-- 開発用 DB とユーザーの作成（VM でのみ実行する）。
--
--   sudo mysql < bin/bootstrap-db.sql
--
-- 本番 DreamHost では、パネルで DB とユーザーを作成してから
-- php bin/migrate.php を流す。このファイルは本番では使わない。
--
-- パスワードは validate_password の既定ポリシー（MEDIUM: 8 文字以上・
-- 大文字・小文字・数字・記号）を満たす必要がある。ここでの値は
-- localhost からしか使えない開発専用のもの。

CREATE DATABASE IF NOT EXISTS droptime
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- TCP で 127.0.0.1 に繋いだ場合も 'localhost' として解決されるが、
-- 名前解決の設定に依存しないよう両方を作る。
CREATE USER IF NOT EXISTS 'droptime'@'localhost' IDENTIFIED BY 'Droptime_Dev_2026!';
CREATE USER IF NOT EXISTS 'droptime'@'127.0.0.1' IDENTIFIED BY 'Droptime_Dev_2026!';

GRANT ALL PRIVILEGES ON droptime.* TO 'droptime'@'localhost';
GRANT ALL PRIVILEGES ON droptime.* TO 'droptime'@'127.0.0.1';
FLUSH PRIVILEGES;

SELECT '作成しました: DB droptime / ユーザー droptime' AS 結果;
