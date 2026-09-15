#!/usr/bin/env bash
#
# svc's deploy steps between upload and migration.
#
# Runs ON THE HOST as the shared deploy action's pre-migrate-script, after the upload and after .env is
# installed from ~/.config/svc/deployment.env:
#
#   pre-migrate.sh <app-dir> <php>
set -euo pipefail

app_dir=$1
php=$2
cd "$HOME/$app_dir"

# Database credentials live beside .env and are installed from the same private configuration.
test -f "$HOME/.config/svc/database.env"
install -m 600 "$HOME/.config/svc/database.env" .db-credentials

# svc-blobs is authoritative production data; the upload excludes it, and it must exist.
install -d -m 700 storage/app/private/svc-blobs

# Passport signing keys are created only when both are absent. Rotating them invalidates every issued
# token, so half a pair is a refusal, never a silent regeneration.
install -d -m 700 storage/app/private/oauth
private_key=storage/app/private/oauth/oauth-private.key
public_key=storage/app/private/oauth/oauth-public.key
if [ -s "$private_key" ] && [ -s "$public_key" ]; then
    chmod 600 "$private_key" "$public_key"
elif [ ! -e "$private_key" ] && [ ! -e "$public_key" ]; then
    "$php" artisan passport:keys --force
    chmod 600 "$private_key" "$public_key"
else
    echo "::error::OAuth signing key pair is incomplete; refusing to rotate it automatically." >&2
    exit 1
fi

# Report the database before migrating, as the deploy always has. Config is cleared first so the probe
# reads the .env just installed, not the previous deploy's cached config.
"$php" artisan config:clear
"$php" artisan svc:database:status --format=json
