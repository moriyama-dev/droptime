#!/usr/bin/env bash
# GitHub Actions の見張り（.github/workflows/privacy.yml）が使う語のハッシュを作り直して、
# リポジトリの秘密の値 PRIVACY_TERMS に登録する。語の平文は GitHub に渡さない。
#
#   bin/privacy-sync-github.sh           # extra.txt か DB の選手が前回から変わっていれば登録する
#   bin/privacy-sync-github.sh --force   # 必ず登録する
#   bin/privacy-sync-github.sh --check   # 古くなっていれば知らせるだけ（pre-push フックが呼ぶ）
#
# GitHub の秘密の値を書き換えるので、実行は Yoshi か、Yoshi が許可した Claude が行う。
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
repo="${PRIVACY_REPO:-moriyama-dev/droptime}"
stamp="$root/var/privacy/.github-synced"

payload="$(php "$root/bin/privacy-check.php" --export-ci 2>/dev/null)"
digest="$(printf '%s' "$payload" | sha256sum | cut -d' ' -f1)"

if [ "${1:-}" != "--force" ] && [ -f "$stamp" ] && [ "$(cat "$stamp")" = "$digest" ]; then
  exit 0
fi

if [ "${1:-}" = "--check" ]; then
  echo "privacy-sync-github: GitHub 側の見張りの語が古くなっています。bin/privacy-sync-github.sh を実行してください。" >&2
  exit 1
fi

printf '%s' "$payload" | gh secret set PRIVACY_TERMS --repo "$repo" >/dev/null
printf '%s\n' "$digest" > "$stamp"
chmod 600 "$stamp"
echo "privacy-sync-github: GitHub の見張りの語を更新しました（$(($(printf '%s\n' "$payload" | wc -l) - 1)) 語）。"
