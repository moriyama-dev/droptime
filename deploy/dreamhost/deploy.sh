#!/usr/bin/env bash
#
# DropTime を DreamHost（共有ホスティング）へ反映する。
#
#   DH_SSH=USER@HOST deploy/dreamhost/deploy.sh            # 下見（何も変えない）
#   DH_SSH=USER@HOST deploy/dreamhost/deploy.sh --apply    # 反映
#   DH_SSH=USER@HOST deploy/dreamhost/deploy.sh --apply --data          # 初回: 大会データも運ぶ（本番が空のときだけ）
#   DH_SSH=USER@HOST deploy/dreamhost/deploy.sh --apply --data-replace  # 大会データを入れ替える
#
# 環境変数:
#   DH_SSH   必須。ssh の接続先（~/.ssh/config の Host 名でもよい）
#   DH_DIR   本番のアプリの置き場所。既定 droptime（ホーム直下）
#   DH_PHP   本番の php。既定 php（パネルの PHP と違うときは /usr/local/php82/bin/php 等）
#
# 守ること（docs/DEPLOY.md）:
#   - config/config.php は送らない（本番の合言葉・DB 接続は本番だけに置く）
#   - var/ の中身は送らない。ただし出典の証拠 var/evidence は送る（本番では撮れないため）
#   - 反映の前に本番の DB をバックアップする
#
set -euo pipefail
cd "$(dirname "$0")/../.."

APPLY=0; DATA=""
for a in "$@"; do
  case "$a" in
    --apply) APPLY=1 ;;
    --data) DATA="import" ;;
    --data-replace) DATA="replace" ;;
    *) echo "不明な引数: $a" >&2; exit 2 ;;
  esac
done

: "${DH_SSH:?DH_SSH=USER@HOST を指定してください}"
DH_DIR="${DH_DIR:-droptime}"
DH_PHP="${DH_PHP:-php}"
DRY=(--dry-run); [ "$APPLY" = 1 ] && DRY=()

echo "== 1. 手元の点検"
php bin/test.php >/dev/null && echo "  テスト OK"
php bin/lint-php82.php | tail -1
php bin/capture-source.php --verify | tail -1
php bin/privacy-check.php --all

echo "== 2. 本番の状態"
ssh "$DH_SSH" "cd $DH_DIR 2>/dev/null && ls config/config.php >/dev/null 2>&1 && echo '  config あり' || echo '  初回（config なし）'; $DH_PHP -v | head -1"

if [ "$APPLY" = 1 ]; then
  ssh "$DH_SSH" "cd $DH_DIR 2>/dev/null && [ -f config/config.php ] && $DH_PHP bin/backup.php || echo '  バックアップは省略（初回）'"
fi

echo "== 3. コード"
# 「問題を報告」の本文に入る版。未コミットの変更があれば -dirty が付く。
git describe --always --dirty > REVISION
rsync -az --delete "${DRY[@]}" --itemize-changes \
  --exclude '.git' --exclude '.claude' --exclude 'config/config.php' \
  --exclude '/public/.dh-diag' --exclude '/public/favicon.*' \
  --include '/var/*/.htaccess' --include '/var/*/.gitignore' --exclude '/var/*/*' \
  ./ "$DH_SSH:$DH_DIR/" | grep -v '^\.' || true

echo "== 4. 出典の証拠（var/evidence）"
rsync -az "${DRY[@]}" --itemize-changes var/evidence/ "$DH_SSH:$DH_DIR/var/evidence/" | grep -v '^\.' | tail -5 || true

if [ "$APPLY" = 0 ]; then
  echo; echo "下見のみ。反映するときは --apply を付けて再実行。"; exit 0
fi

ssh "$DH_SSH" "mkdir -p $DH_DIR/var/{uploads,logs,backups,manual,evidence}"

if ! ssh "$DH_SSH" "[ -f $DH_DIR/config/config.php ]"; then
  echo; echo "本番に config/config.php がありません。docs/DEPLOY.md の 3 を行ってから再実行してください。"; exit 1
fi

echo "== 5. マイグレーション"
ssh "$DH_SSH" "cd $DH_DIR && $DH_PHP bin/migrate.php"

if [ -n "$DATA" ]; then
  echo "== 6. 大会データ"
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  php bin/transfer-data.php export "$TMP/data.json.gz" >/dev/null
  scp -q "$TMP/data.json.gz" "$DH_SSH:$DH_DIR/var/backups/transfer.json.gz"
  FLAG=""; [ "$DATA" = replace ] && FLAG="--replace"
  ssh "$DH_SSH" "cd $DH_DIR && $DH_PHP -d memory_limit=1024M bin/transfer-data.php import var/backups/transfer.json.gz $FLAG && rm var/backups/transfer.json.gz"
fi

echo "== 7. 本番の点検"
ssh "$DH_SSH" "cd $DH_DIR && $DH_PHP bin/check-prod.php"
