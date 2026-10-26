#!/usr/bin/env bash
#
# 認証まわりの疎通確認。開発サーバと開発用 MySQL が動いている状態で実行する。
#
#   bin/dev-mysql.sh start
#   php -S 127.0.0.1:8787 -t public bin/dev-router.php &
#   bin/smoke-test.sh
#
# 合言葉は環境変数 DROPTIME_PARENT_PASS・DROPTIME_ADMIN_PASS から読む（本番と同じ合言葉なので、リポジトリには書かない）。
#
set -u
B="${DROPTIME_SMOKE_BASE:-http://127.0.0.1:8787}"
J="${1:-$(mktemp)}"
pass=0; fail=0
check() { # label expected actual
  if [ "$2" = "$3" ]; then echo "  OK   $1 ($3)"; pass=$((pass+1));
  else echo "  NG   $1 期待=$2 実際=$3"; fail=$((fail+1)); fi
}
code() { curl -s -H "Accept-Language: ja" -o /dev/null -w '%{http_code}' -b $J -c $J "$@"; }
loc()  { curl -s -H "Accept-Language: ja" -o /dev/null -w '%{redirect_url}' -b $J -c $J "$@"; }
token() { curl -s -H "Accept-Language: ja" -b $J -c $J "$1" | grep -o 'name="_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }

echo "1. 未ログインでトップ"
check "302 になる" 302 "$(code $B/)"
check "ログインへ飛ぶ" "$B/login" "$(loc $B/)"

echo "2. ログイン画面"
check "200 で表示される" 200 "$(code $B/login)"
H=$(curl -s -H "Accept-Language: ja" -D - -o /dev/null -b $J -c $J $B/login)
echo "$H" | grep -qi 'X-Robots-Tag: noindex' && { echo "  OK   X-Robots-Tag がある"; pass=$((pass+1)); } || { echo "  NG   X-Robots-Tag が無い"; fail=$((fail+1)); }
curl -s -H "Accept-Language: ja" -b $J -c $J $B/login | grep -q 'content="noindex, nofollow' && { echo "  OK   meta robots がある"; pass=$((pass+1)); } || { echo "  NG   meta robots が無い"; fail=$((fail+1)); }
grep -qi 'HttpOnly' $J && { echo "  OK   Cookie が HttpOnly"; pass=$((pass+1)); } || { echo "  NG   HttpOnly でない"; fail=$((fail+1)); }

echo "3. CSRF"
check "トークン無しの POST は 400" 400 "$(code -X POST -d "passphrase=$DROPTIME_PARENT_PASS" $B/login)"

echo "4. 合言葉"
T=$(token $B/login)
check "違う合言葉は 401" 401 "$(code -X POST -d "_token=$T&passphrase=wrong" $B/login)"
T=$(token $B/login)
check "正しい合言葉は 302" 302 "$(code -X POST -d "_token=$T&passphrase=$DROPTIME_PARENT_PASS" $B/login)"

echo "5. ログイン後"
check "トップが 200" 200 "$(code $B/)"
curl -s -H "Accept-Language: ja" -b $J -c $J $B/ | grep -q '次に泳ぐレース' && { echo "  OK   トップの見出しが出る"; pass=$((pass+1)); } || { echo "  NG   見出しが無い"; fail=$((fail+1)); }
check "ログイン画面はトップへ戻す" "$B/" "$(loc $B/login)"

echo "6. 管理画面の 2 段目"
check "管理画面は 302" 302 "$(code $B/admin)"
check "管理ログインへ飛ぶ" "$B/admin/login" "$(loc $B/admin)"
T=$(token $B/admin/login)
check "違う管理合言葉は 401" 401 "$(code -X POST -d "_token=$T&passphrase=nope" $B/admin/login)"
T=$(token $B/admin/login)
check "正しい管理合言葉は 302" 302 "$(code -X POST -d "_token=$T&passphrase=$DROPTIME_ADMIN_PASS" $B/admin/login)"
check "管理画面が 200" 200 "$(code $B/admin)"

echo "7. 取り込みフロー（HTML アップロード → 確認 → 破棄）"
TMPHTML=$(mktemp --suffix=.html)
printf '<html><body><pre>Event 1  Girls 50 Free\nLane 4  Test Line</pre></body></html>' > $TMPHTML
T=$(token $B/admin)
IMPORT_URL=$(curl -s -o /dev/null -w '%{redirect_url}' -b $J -c $J -F "_token=$T" -F "file=@$TMPHTML;type=text/html" $B/admin/imports)
echo "$IMPORT_URL" | grep -q '/admin/imports/[0-9]*$' && { echo "  OK   アップロード後に確認画面へ飛ぶ"; pass=$((pass+1)); } || { echo "  NG   確認画面へ飛ばない ($IMPORT_URL)"; fail=$((fail+1)); }
check "確認画面が 200" 200 "$(code $IMPORT_URL)"
PAGE=$(curl -s -H "Accept-Language: ja" -b $J -c $J $IMPORT_URL)
echo "$PAGE" | grep -q '認識できた件数' && { echo "  OK   件数の要約が出る"; pass=$((pass+1)); } || { echo "  NG   件数の要約が無い"; fail=$((fail+1)); }
echo "$PAGE" | grep -q '未認識の行' && { echo "  OK   未認識行の一覧が出る"; pass=$((pass+1)); } || { echo "  NG   未認識行が無い"; fail=$((fail+1)); }
T=$(token $IMPORT_URL)
check "再パースは 302" 302 "$(code -X POST -d "_token=$T" $IMPORT_URL/reparse)"
T=$(token $IMPORT_URL)
check "破棄は 302" 302 "$(code -X POST -d "_token=$T" $IMPORT_URL/discard)"
curl -s -H "Accept-Language: ja" -b $J -c $J $IMPORT_URL | grep -q 'discarded' && { echo "  OK   破棄済みになる"; pass=$((pass+1)); } || { echo "  NG   破棄済みにならない"; fail=$((fail+1)); }
rm -f $TMPHTML

echo "8. ログアウト"
T=$(token $B/)
check "ログアウトは 302" 302 "$(code -X POST -d "_token=$T" $B/logout)"
check "ログアウト後のトップは 302" 302 "$(code $B/)"

echo "9. 戻り先つきログイン"
check "next 付きで飛ぶ" "$B/login?next=%2Fadmin" "$(loc $B/admin)"

echo "10. 404"
check "未定義パスは 404" 404 "$(code $B/nope)"

echo
echo "成功 $pass 件 / 失敗 $fail 件"
[ $fail -eq 0 ]
