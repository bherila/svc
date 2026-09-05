#!/usr/bin/env bash
#
# Install this repository's agent skills for the current user account.
#
# The skills live here so they are versioned and reviewable, but they are useful
# from *other* repositories - logging time happens while working on a product,
# not while working on SVC. Symlinking rather than copying means `git pull`
# updates an installed skill with no second step.
#
# Usage: pnpm run skills:install   (or: bash scripts/install-skills.sh)

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="$repo_root/.claude/skills"
target_dir="${CLAUDE_SKILLS_DIR:-$HOME/.claude/skills}"

if [ ! -d "$source_dir" ]; then
    echo "No skills to install: $source_dir does not exist." >&2
    exit 1
fi

mkdir -p "$target_dir"

installed=0
skipped=0

for skill_path in "$source_dir"/*/; do
    [ -d "$skill_path" ] || continue
    skill_name="$(basename "$skill_path")"
    link="$target_dir/$skill_name"

    if [ -e "$link" ] && [ ! -L "$link" ]; then
        echo "skip    $skill_name - $link exists and is not a symlink; not overwriting." >&2
        skipped=$((skipped + 1))
        continue
    fi

    if [ -L "$link" ] && [ "$(readlink "$link")" = "${skill_path%/}" ]; then
        echo "ok      $skill_name - already linked"
        installed=$((installed + 1))
        continue
    fi

    ln -sfn "${skill_path%/}" "$link"
    echo "linked  $skill_name -> $link"
    installed=$((installed + 1))
done

echo
echo "$installed installed, $skipped skipped."

if [ "$skipped" -gt 0 ]; then
    echo "Remove the conflicting path by hand if you want the repository's version." >&2
    exit 1
fi

cat <<'EOF'

The time-logging skill needs the SVC MCP server connected for your user account:

    claude mcp add --transport http --scope user svc https://svc.bherila.net/api/v1/mcp
    claude mcp login svc

Restart the client if it was already running.
EOF
