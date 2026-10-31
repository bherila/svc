#!/usr/bin/env bash
# Grant the web server traversal/public reads after atomic stable-directory replacement.
set -euo pipefail
[[ $# -eq 3 ]] || exit 2
stable=$1
php=$2
[[ $stable =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $stable != *..* ]] || exit 2
[[ $php = /* && -x $php ]] || exit 1
root="$HOME/$stable"
shared="$HOME/.deployments/$stable/shared"
[[ -d $root && ! -L $root && -d "$root/public" && ! -L "$root/public" ]] || exit 1
setfacl -m u:nginx:--x "$root"
trees=("$root/public")
links=$(mktemp)
trap 'rm -f -- "$links"' EXIT
find "$root/public" -type l -print0 > "$links"
while IFS= read -r -d '' link; do
    target=$(readlink -f "$link")
    case "$target/" in
        "$root/public/"* | "$shared/public/"* | "$shared/storage/app/public/") ;;
        *) echo 'Public symlink escapes the permitted public storage roots.' >&2; exit 1 ;;
    esac
    [[ -d $target ]] || exit 1
    parent=${target%/*}
    while [[ $parent != "$HOME" ]]; do
        [[ $parent = "$HOME/"* && -d $parent ]] || exit 1
        setfacl -m u:nginx:--x "$parent"
        parent=${parent%/*}
    done
    trees+=("$target")
done < "$links"
for tree in "${trees[@]}"; do
    find "$tree" -type d -exec setfacl -m u:nginx:r-x,d:u:nginx:r-x,d:m::r-x {} +
    find "$tree" -type f -exec setfacl -m u:nginx:r-- {} +
done
