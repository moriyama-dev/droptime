#!/usr/bin/env bash
#
# VM の大会データが変わったら、本番（DreamHost）へ自動で運ぶ。VM の cron から 10 分ごとに呼ぶ。
#
#   bin/sync-prod.sh            変わっていれば運ぶ（変わっていなければ何もしない）
#   bin/sync-prod.sh --force    変わっていなくても運ぶ
#
# 大会データの正は VM。取得ボタン・取り込み・選手の統合は VM で行い、本番はその写しになる
# （本番の管理画面で取り込んだものは、次の同期で VM の内容に置き換わる）。
# 運ぶ前に本番の DB をバックアップする（bin/backup.php・7 世代）。
# コードは運ばない（コードの反映は deploy/dreamhost/deploy.sh）。
#
# ログ: var/logs/sync-prod.log（時刻はトロント時間）
#
set -euo pipefail
cd "$(dirname "$0")/.."

DH_SSH="${DH_SSH:-droptime-prod}"
DH_DIR="${DH_DIR:-droptime.takumi.ca}"
DH_PHP="${DH_PHP:-php}"
STATE=var/sync
LOG=var/logs/sync-prod.log
mkdir -p "$STATE" var/logs

log() { echo "$(TZ=America/Toronto date '+%Y-%m-%d %H:%M:%S %Z') $*" >> "$LOG"; }

# 二重起動しない
exec 9> "$STATE/lock"
flock -n 9 || exit 0

# 「最新の記録を取得」が動いている最中は、途中の状態を運ばないよう次の回に回す
if [ -e var/fetch/run.lock ] && ! flock -n var/fetch/run.lock true; then
  exit 0
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

php bin/transfer-data.php export "$TMP/data.json.gz" > /dev/null

# 書き出した日時を除いた中身と、出典の証拠の台帳で、変わったかを判定する
HASH="$(php -d memory_limit=1024M -r '
  $d = json_decode(gzdecode(file_get_contents($argv[1])), true);
  $ledger = is_file($argv[2]) ? hash_file("sha256", $argv[2]) : "";
  echo hash("sha256", json_encode($d["tables"]) . $ledger);
' "$TMP/data.json.gz" var/evidence/ledger.jsonl)"

if [ "${1:-}" != "--force" ] && [ -f "$STATE/last.sha256" ] && [ "$(cat "$STATE/last.sha256")" = "$HASH" ]; then
  exit 0
fi

log "VM の大会データが変わったので本番へ運びます"

if ! {
  ssh "$DH_SSH" "cd $DH_DIR && $DH_PHP bin/backup.php" > /dev/null &&
  rsync -az var/evidence/ "$DH_SSH:$DH_DIR/var/evidence/" &&
  scp -q "$TMP/data.json.gz" "$DH_SSH:$DH_DIR/var/backups/transfer.json.gz" &&
  ssh "$DH_SSH" "cd $DH_DIR && $DH_PHP -d memory_limit=1024M bin/transfer-data.php import var/backups/transfer.json.gz --replace && rm var/backups/transfer.json.gz"
} >> "$LOG" 2>&1; then
  log "失敗しました。次の回にやり直します"
  exit 1
fi

echo "$HASH" > "$STATE/last.sha256"
log "本番へ運びました"
