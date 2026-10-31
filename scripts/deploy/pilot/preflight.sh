#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 3 ]] || exit 2
candidate=$1
php=$2
stable=$3
[[ $stable =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $stable != *..* ]] || exit 2
[[ $candidate = ".deployments/$stable/releases/"* && $candidate != *..* ]] || exit 2
[[ $php = /* && -x $php && -d /proc ]] || exit 1
command -v setfacl >/dev/null
"$php" -d memory_limit=1G -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 && extension_loaded("pdo_mysql") ? 0 : 1);'
