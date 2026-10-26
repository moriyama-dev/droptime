#!/usr/bin/env bash
#
# 開発用の MySQL を、sudo なしでこのプロジェクト配下に立てる。
# 本番はもちろん DreamHost の MySQL を使う。これは VM での開発専用。
#
#   bin/dev-mysql.sh start    初期化して起動し、droptime DB とユーザーを用意する
#   bin/dev-mysql.sh stop     停止する
#   bin/dev-mysql.sh status   起動しているか確認する
#   bin/dev-mysql.sh cli      mysql クライアントで接続する
#
set -euo pipefail

# データディレクトリはプロジェクト配下に置けない。Ubuntu の AppArmor プロファイルが
# /usr/sbin/mysqld の書き込み先を制限しており、$HOME 以下は拒否されるため。
BASE="${DROPTIME_DEV_MYSQL_DIR:-/tmp/droptime-mysql}"
DATA="$BASE/data"
SOCK="$BASE/mysql.sock"
LOG="$BASE/error.log"
PID="$BASE/mysqld.pid"
PORT="${DROPTIME_DEV_MYSQL_PORT:-3307}"

DB_NAME=droptime
DB_USER=droptime
DB_PASS='Droptime_Dev_2026!'

client() {
    mysql --no-defaults -h 127.0.0.1 -P "$PORT" -u root "$@"
}

is_running() {
    mysqladmin --no-defaults -h 127.0.0.1 -P "$PORT" -u root ping >/dev/null 2>&1
}

case "${1:-start}" in
start)
    if is_running; then
        echo "すでに起動しています（ポート $PORT）"
        exit 0
    fi

    mkdir -p "$BASE"

    if [ ! -d "$DATA" ]; then
        echo "データディレクトリを初期化します: $DATA"
        # --initialize-insecure はディレクトリを自分で作るため、先に作らない。
        mysqld --no-defaults --initialize-insecure --datadir="$DATA" --log-error="$LOG"
    fi

    nohup mysqld --no-defaults \
        --datadir="$DATA" \
        --socket="$SOCK" \
        --port="$PORT" \
        --bind-address=127.0.0.1 \
        --log-error="$LOG" \
        --pid-file="$PID" \
        --mysqlx=OFF \
        >/dev/null 2>&1 &

    for _ in $(seq 1 30); do
        if is_running; then break; fi
        sleep 1
    done

    if ! is_running; then
        echo "起動できませんでした。$LOG を確認してください。" >&2
        exit 1
    fi

    # 文字セットは DB 既定に頼らず明示する（本番の既定が古い可能性があるため）。
    client <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

    echo "起動しました: 127.0.0.1:$PORT （DB: $DB_NAME / ユーザー: $DB_USER）"
    ;;
stop)
    if ! is_running; then
        echo "起動していません"
        exit 0
    fi
    mysqladmin --no-defaults -h 127.0.0.1 -P "$PORT" -u root shutdown
    echo "停止しました"
    ;;
status)
    if is_running; then
        client -e "SELECT @@version AS version, @@port AS port" 2>/dev/null
    else
        echo "起動していません"
        exit 1
    fi
    ;;
cli)
    shift || true
    client "$@"
    ;;
*)
    echo "使い方: $0 {start|stop|status|cli}" >&2
    exit 1
    ;;
esac
