#!/usr/bin/env bash
#
# 開発 VM の Apache に DropTime を載せる。root で 1 回実行する:
#
#   sudo bash ~/droptime/deploy/vm/setup-apache.sh
#
# やること:
#   1. Apache の実行ユーザーを yoshi に変更
#      （CLI と Web で var/ 以下の所有者を揃えるため。DreamHost も
#        自ユーザーで PHP が動くので、本番と同じ前提になる）
#   2. DropTime の VirtualHost を有効化し、デフォルトサイトを無効化
#   3. 必要なモジュールの有効化と再起動
#
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "root で実行してください: sudo bash $0" >&2
    exit 1
fi

APP=/home/yoshi/droptime

sed -i 's/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=yoshi/' /etc/apache2/envvars
sed -i 's/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=yoshi/' /etc/apache2/envvars

install -m 644 "$APP/deploy/vm/droptime.conf" /etc/apache2/sites-available/droptime.conf

a2enmod -q rewrite headers expires
a2dissite -q 000-default || true
a2ensite -q droptime

apache2ctl configtest
systemctl restart apache2

echo
echo "完了。http://$(hostname -I | grep -oE '100\.[0-9.]+' | head -1)/ で確認してください。"
