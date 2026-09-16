#!/usr/bin/env bash
#
# svc's deploy steps between upload and migration.
#
# Runs ON THE HOST as the shared deploy action's pre-migrate-script, after the upload and after .env is
# installed from ~/.config/svc/deployment.env:
#
#   pre-migrate.sh <candidate-path> <php> <stable-path>
set -euo pipefail

app_dir=$1
php=$2
stable_dir=$3
test "$stable_dir" = svc-laravel
cd "$HOME/$app_dir"

# Database credentials live beside .env and are installed from the same private configuration.
test -f "$HOME/.config/svc/database.env"
install -m 600 "$HOME/.config/svc/database.env" .db-credentials

# svc-blobs is authoritative production data inside the shared storage tree.
install -d -m 700 storage/app/private/svc-blobs

# The shared action owns Passport key validation and first creation. Its
# passport-key-directory input requires this directory to be covered by the
# persistent storage path and refuses a half-present pair.
test -s storage/app/private/oauth/oauth-private.key
test -s storage/app/private/oauth/oauth-public.key

# Report the database before migrating, as the deploy always has. Config is cleared first so the probe
# reads the .env just installed, not the previous deploy's cached config.
"$php" artisan config:clear
"$php" artisan svc:database:status --format=json
