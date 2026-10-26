#!/usr/bin/env bash
# Claude Code の PreToolUse フック（Bash）。このリポジトリで Claude が見張りを外す操作をしないように止め、
# GitHub に文章を出す gh のコマンドは、本文に個人名・クラブ名・機密情報が無いかを確かめる。
# 設定: .claude/settings.local.json（git 管理外）。CLAUDE.md「公開リポジトリと個人情報」。
# 止めるときは終了コード 2 と理由（Claude Code の約束）。
set -uo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
input="$(cat)"
cmd="$(jq -r '.tool_input.command // empty' <<<"$input" 2>/dev/null)"
cwd="$(jq -r '.cwd // empty' <<<"$input" 2>/dev/null)"
[ -z "$cmd" ] && exit 0

# droptime に関係する操作だけを見る（ほかのリポジトリでの作業は止めない）
case "$cwd" in
  "$root"|"$root"/*) ;;
  *) grep -q -- "$root\|droptime" <<<"$cmd" || exit 0 ;;
esac

deny() { echo "claude-guard: $1（CLAUDE.md「公開リポジトリと個人情報」）" >&2; exit 2; }

# 見張りを外す・すり抜ける操作
# （git と同じ行のときだけ。コミットメッセージの中の説明文までは止めない）
if grep -Eq -- 'git[[:space:]][^|;&]*--no-verify|git[[:space:]]+commit[^|;&]*[[:space:]]-[a-zA-Z]*n' <<<"$cmd"; then
  deny "git の --no-verify（見張りの回避）は使えません"
fi
hooks_lines="$(grep -E -- 'git[[:space:]][^|;&]*core\.hooksPath' <<<"$cmd" || true)"
if [ -n "$hooks_lines" ] && grep -Ev -- 'core\.hooksPath[[:space:]]+\.githooks([[:space:]]|$)|config[[:space:]]+core\.hooksPath[[:space:]]*($|[;&|])' <<<"$hooks_lines" | grep -q .; then
  deny "core.hooksPath を .githooks 以外にすることはできません"
fi
if grep -Eq -- 'git[[:space:]]+push[^|;&]*(--force|-f([[:space:]]|$))' <<<"$cmd"; then
  deny "強制 push は Yoshi の確認なしにはできません（履歴の書き換えは公開済みの内容を消せない）"
fi
if grep -Eq -- '(^|[[:space:];&|])(rm|mv|truncate)[[:space:]][^|;&]*var/privacy/extra\.txt|(^|[^>])>[[:space:]]*var/privacy/extra\.txt' <<<"$cmd"; then
  deny "見張りの語の一覧（var/privacy/extra.txt）は消したり空にしたりできません。足すときは >> で"
fi

# GitHub に文章を出す gh のコマンドは、コマンドの文字列ごと見張りにかける
if grep -Eq -- '(^|[[:space:];&|(])gh[[:space:]]+(issue|pr|gist|release|repo|api|discussion|label|secret[[:space:]]+set[[:space:]]+[^P])' <<<"$cmd"; then
  if ! printf '%s' "$cmd" | php "$root/bin/privacy-check.php" --stdin >/dev/null 2>"$root/var/privacy/.guard-last"; then
    deny "gh で GitHub に出す文章に、個人名・クラブ名・機密情報が含まれています（$(grep -c '  - ' "$root/var/privacy/.guard-last") 件）"
  fi
fi
exit 0
