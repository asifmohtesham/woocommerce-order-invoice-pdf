#!/usr/bin/env bash
# Create an isolated git worktree for a feature, off the LATEST origin/master,
# so concurrent instances develop and land in parallel without sharing a
# working tree. See CLAUDE.md "Concurrent development".
#
# Usage:
#   tools/new-feature.sh <feature-name> [--junction]
#
#   <feature-name>   kebab-case; branch becomes feat/<name>, dir ../woi-<name>
#   --junction       link node_modules to the main install instead of `npm ci`
#                    (fast, Windows; READ the teardown warning below)
set -euo pipefail

name="${1:?usage: tools/new-feature.sh <feature-name> [--junction]}"
mode="${2:-}"
branch="feat/${name}"

# Main checkout root == parent of the common git dir (works from any worktree).
main="$(cd "$(git rev-parse --git-common-dir)/.." && pwd -P)"
parent="$(dirname "$main")"
dir="${parent}/woi-${name}"

if [ -e "$dir" ]; then
	echo "✗ $dir already exists — pick another name or remove it first." >&2
	exit 1
fi

git -C "$main" fetch origin
git -C "$main" worktree add -b "$branch" "$dir" origin/master

# Install the shared concurrency hooks repo-wide (idempotent).
git -C "$main" config core.hooksPath tools/hooks

if [ "$mode" = "--junction" ]; then
	# Fast path: link node_modules to the main install (Windows directory junction).
	# WARNING: never `rm -rf node_modules` or `git worktree remove --force` this
	# worktree — both follow the junction and EMPTY the main checkout's
	# node_modules. Tear down with:  cmd //c rmdir node_modules   THEN
	# git worktree remove "$dir".  (See CLAUDE.md.)
	if command -v cygpath >/dev/null 2>&1; then
		cmd //c mklink /J "$(cygpath -w "$dir/node_modules")" "$(cygpath -w "$main/node_modules")" \
			|| { echo "mklink failed — falling back to npm ci"; ( cd "$dir" && npm ci ); }
	else
		echo "--junction is Windows-only; running npm ci instead."
		( cd "$dir" && npm ci )
	fi
else
	( cd "$dir" && npm ci )
fi

cat <<EOF

✓ Worktree ready: $dir   (branch $branch, based on origin/master)

Next:
  cd "$dir"
  # ...implement; commit SOURCE only (leave the built bundle + version for landing)...
  # Land it (collision-safe) — see CLAUDE.md "Landing a feature":
  #   git fetch origin && git rebase origin/master
  #   # only if plugin code/assets changed: bump BOTH version strings + npm run build
  #   git push origin HEAD:master
EOF
