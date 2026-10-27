<?php

declare(strict_types=1);

/**
 * DropTime 設定ファイルの雛形。
 *
 * config/config.php にコピーして値を埋めること。config.php は git 管理外であり、
 * ドキュメントルートの外に置かれる（公開されるのは public/ のみ）。
 *
 * 合言葉のハッシュは `php bin/hash-password.php` で生成する。
 */
return [
    'app' => [
        // アプリ名は必ずここから読む。テンプレートやコードに直書きしないこと。
        'name' => 'DropTime',
        // 左上のアプリ名のリンク先。
        'home_url' => 'https://droptime.takumi.ca/',
        // 'dev' か 'prod'。prod では画面にエラー詳細を出さない。
        'env' => 'prod',
        // 大会日時の解釈はすべてこのタイムゾーンで行う。
        'timezone' => 'America/Toronto',
        // サブディレクトリに設置する場合のみ '/droptime' のように指定する。
        'base_path' => '',
    ],

    'auth' => [
        // 保護者用の合言葉（クラブ共通）のハッシュ。
        'password_hash' => '',
        // 管理画面用の別合言葉のハッシュ。空文字なら管理画面は完全に無効化される。
        'admin_password_hash' => '',
        // ログイン状態の保持日数。
        'session_days' => 90,
        // ブルートフォース対策：window_minutes 分の間に max_attempts 回失敗したら
        // lock_minutes 分ロックする。
        'lockout' => [
            'max_attempts' => 10,
            'window_minutes' => 15,
            'lock_minutes' => 15,
        ],
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'droptime',
        'user' => '',
        'password' => '',
        // ソケット接続する場合のみ指定（指定時は host/port より優先）。
        'socket' => null,
    ],

    // 保護者画面のフッターの「問題を報告」（GitHub の新しい Issue を、版・画面・ブラウザを記入済みで開く）と
    // 「GitHub」（ソース）。private のリポジトリでは「GitHub」は出さない。repo を空にすると両方消える。
    'feedback' => [
        'repo' => 'moriyama-dev/droptime',
        'private' => false,
    ],

    'security' => [
        // 本番は必ず true。HTTPS でないと Cookie が送られなくなる。
        'cookie_secure' => true,
    ],

    'paths' => [
        // いずれもドキュメントルートの外であること。
        'uploads' => APP_ROOT . '/var/uploads',
        'logs' => APP_ROOT . '/var/logs',
        'backups' => APP_ROOT . '/var/backups',
        // 出典の証拠（スクリーンショット・原本・台帳）。本番へはディレクトリごと運ぶ。
        'evidence' => APP_ROOT . '/var/evidence',
        // 「最新の記録を取得」のログ・状態・ロック。
        'fetch' => APP_ROOT . '/var/fetch',
    ],

    // 管理画面の「最新の記録を取得」（bin/fetch-latest.php）。
    'fetch' => [
        // Hy-Tek の速報 HTML 置き場。誰でも見られる公開ページだけを読む。
        'portal_url' => 'https://results.swimmeets.ca/',
        // このクラブの選手が 1 人でも出ている大会だけを取り込む（Hy-Tek のチームコード）。
        // 実際のクラブのコードは config.php にだけ書く（公開リポジトリに入れない）。ZORC は架空の見本。
        'club_code' => 'ZORC',
        // 1 リクエストごとの待ち時間（ミリ秒）。相手は小さなサイトなので空けておく。
        'delay_ms' => 300,
        // 新しく投入した大会の出典の証拠（スクショ）を撮るか。ヘッドレス Chrome が無ければ自動で撮らない。
        'evidence' => true,
        // Web から裏で起動するときの PHP CLI。共有ホスティングでは php のパスが違うことがある。
        'php_binary' => '/usr/bin/php',
    ],

    'tools' => [
        'pdftotext' => '/usr/bin/pdftotext',
        'mysqldump' => '/usr/bin/mysqldump',
    ],

    'backup' => [
        // 保持世代数。
        'keep' => 7,
    ],

    'ui' => [
        // クラブカラー。CSS カスタムプロパティとして流し込まれる。
        'brand' => [
            'primary' => '#0b4f6c',
            'accent' => '#00c2a8',
        ],
    ],
];
