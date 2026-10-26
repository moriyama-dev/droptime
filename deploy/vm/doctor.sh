#!/usr/bin/env bash
#
# 「IP を開いたら Apache の画面が出た」ときに、まずこれを実行する。
#
#   bash ~/droptime/deploy/vm/doctor.sh
#
# サーバー側が正常だと分かれば、原因はブラウザのキャッシュか IP の取り違え。
# その場合の対処もこのスクリプトが最後に案内する。

set -uo pipefail

APP=/home/yoshi/droptime
ok=0
ng=0

say()  { printf '%s\n' "$*"; }
pass() { printf '  \033[32m OK \033[0m %s\n' "$*"; ok=$((ok+1)); }
fail() { printf '  \033[31m NG \033[0m %s\n' "$*"; ng=$((ng+1)); }
warn() { printf '  \033[33m 注意\033[0m %s\n' "$*"; }

say "=== DropTime サーバー診断 ==="
say

say "[1] Apache が動いているか"
if systemctl is-active --quiet apache2; then
    pass "apache2 は起動中"
else
    fail "apache2 が止まっている  →  sudo systemctl start apache2"
fi

say "[2] 有効なサイトが DropTime だけか"
enabled=$(ls /etc/apache2/sites-enabled/ 2>/dev/null)
if [ "$enabled" = "droptime.conf" ]; then
    pass "droptime.conf のみ有効"
else
    fail "有効サイトが想定外: [$enabled]  →  sudo bash $APP/deploy/vm/setup-apache.sh"
fi

say "[3] Apache の実行ユーザーが yoshi か"
if grep -q '^export APACHE_RUN_USER=yoshi' /etc/apache2/envvars; then
    pass "APACHE_RUN_USER=yoshi"
else
    fail "実行ユーザーが yoshi でない（var/ の書き込みが壊れる）  →  sudo bash $APP/deploy/vm/setup-apache.sh"
fi

say "[4] DocumentRoot が存在するか"
if [ -f "$APP/public/index.php" ]; then
    pass "$APP/public/index.php あり"
else
    fail "$APP/public/index.php が無い"
fi

say "[5] Apache デフォルト画面の残骸"
if [ -e /var/www/html/index.html ]; then
    warn "/var/www/html/index.html がまだ存在する（配信はされないが紛らわしい）"
    warn "  消すには:  sudo rm /var/www/html/index.html"
else
    pass "デフォルト画面は削除済み"
fi

say
say "[6] 実際の応答（サーバー自身から）"
for target in "http://127.0.0.1/" "http://$(tailscale ip -4 2>/dev/null)/"; do
    case "$target" in *"//"/*|*"///"*) continue;; esac
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 "$target" 2>/dev/null)
    loc=$(curl -sS -o /dev/null -w '%{redirect_url}' --max-time 5 "$target" 2>/dev/null)
    if [ "$code" = "302" ] && [ "${loc##*/}" = "login" ]; then
        pass "$target → 302 $loc  （DropTime のログイン画面）"
    else
        fail "$target → HTTP $code $loc  （DropTime ではない）"
    fi
done

say "[7] Apache デフォルト画面が配信されうるか"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 http://127.0.0.1/index.html 2>/dev/null)
if [ "$code" = "404" ] || [ "$code" = "302" ]; then
    pass "/index.html → HTTP $code （デフォルト画面は配信されない）"
else
    fail "/index.html → HTTP $code （デフォルト画面が見えている可能性）"
fi

say
say "[8] 直近のブラウザからのアクセス（ログ）"
log=/var/log/apache2/droptime-access.log
if sudo -n true 2>/dev/null || [ -r "$log" ]; then
    (sudo -n cat "$log" 2>/dev/null || cat "$log" 2>/dev/null) \
        | grep -v 'curl/' | tail -5 | sed 's/^/  /'
else
    warn "ログ読取に sudo が必要:  sudo tail -20 $log"
fi

say
say "================================================================"
if [ "$ng" -eq 0 ]; then
    cat <<'MSG'
サーバーは正常です。Apache の画面が見えるなら、原因はブラウザ側です。

  対処1（キャッシュ）: ブラウザで  Cmd + Shift + R  （スーパーリロード）
                       または シークレット／プライベートウィンドウで開く
  対処2（IP取り違え）: IP ではなく名前でアクセスしてください
MSG
    say
    say "  →  http://droptime.tail24ad8b.ts.net/"
    say "  →  http://$(tailscale ip -4 2>/dev/null)/"
    say
    say "上の[8]のログに、あなたがアクセスした瞬間の行が出ていなければ、"
    say "リクエストはこの VM に届いていません＝ブラウザキャッシュか別マシンです。"
else
    say "NG が $ng 件あります。上の指示に従って直してください。"
fi
say "================================================================"
