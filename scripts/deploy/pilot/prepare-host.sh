#!/usr/bin/env bash
# Read-only opt-in check, then preserve only an exactly recognized first-install scaffold.
set -euo pipefail
umask 077
[[ $# -eq 3 ]] || exit 2
app=$1
env_source=$2
php=$3
[[ $app =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $app != *..* ]] || exit 2
[[ $env_source =~ ^\.config/[A-Za-z0-9._-]+/deployment\.env$ && $env_source != *..* ]] || exit 2
[[ $php = /* && -x $php ]] || exit 2
[[ -f "$HOME/$env_source" && ! -L "$HOME/$env_source" ]] || exit 1
[[ ! -e "$HOME/${env_source%/*}/cron-enabled" ]] || { echo 'Pilot cron activation must be reviewed separately.' >&2; exit 1; }
# shellcheck disable=SC2016 # PHP variable names must remain literal.
"$php" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
try {
    $env = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
    if (!is_array($env) || ($env["PILOT_DEPLOYMENT"] ?? "") !== "1"
        || ($env["APP_DEBUG"] ?? "") !== "false" || ($env["MAIL_MAILER"] ?? "") !== "log"
        || ($env["DB_HOST"] ?? "") !== "127.0.0.1"
        || ($env["DB_URL"] ?? "") !== "") { exit(1); }
} catch (Throwable $error) { exit(1); }
' "$HOME/$env_source" || { echo 'Private target does not have the required pilot-only configuration.' >&2; exit 1; }
stable="$HOME/$app"
[[ ! -L $stable ]] || exit 1
[[ -e $stable ]] || exit 0
[[ -d $stable ]] || exit 1
[[ ! -f "$stable/artisan" ]] || exit 0
prepared="$HOME/.deployments/$app/prepared-root"
[[ ! -e $prepared && ! -L $prepared ]] || { echo 'Prepared-root recovery copy already exists.' >&2; exit 1; }
for path in "$stable"/.[!.]* "$stable"/..?* "$stable"/*; do
    [[ -e $path || -L $path ]] || continue
    case ${path##*/} in
        .env) [[ -f $path && ! -L $path ]] || exit 1 ;;
        storage) [[ -L $path && $(readlink -f "$path") = "$HOME/.deployments/$app/shared/storage" ]] || exit 1 ;;
        public) [[ -d $path && ! -L $path && -z $(find "$path" -mindepth 1 -print -quit) ]] || exit 1 ;;
        bootstrap)
            [[ -d $path && ! -L $path ]] || exit 1
            [[ -z $(find "$path" -mindepth 1 ! -path "$path/cache" -print -quit) ]] || exit 1
            [[ ! -e "$path/cache" || ( -d "$path/cache" && ! -L "$path/cache" ) ]] || exit 1
            ;;
        *) echo 'Unrecognized first-install contents; refusing to move the application root.' >&2; exit 1 ;;
    esac
done
mkdir -p "$HOME/.deployments/$app"
mv -- "$stable" "$prepared"
echo 'Recognized first-install scaffold preserved; stable application path is ready.'
