#!/usr/bin/env bash

log() {
  printf '\n==> %s\n' "$*"
}

cleanup_paths=()

cleanup() {
  local path
  for path in "${cleanup_paths[@]-}"; do
    if [[ -n "$path" ]]; then
      rm -f "$path"
    fi
  done
  return 0
}

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

run_as_root() {
  if [[ "$(id -u)" == "0" ]]; then
    "$@"
  else
    need_cmd sudo
    sudo "$@"
  fi
}

is_optional_apt_uri() {
  local uri="$1" host
  [[ "$uri" == http://* || "$uri" == https://* ]] || return 1
  host="${uri#*://}"
  host="${host%%/*}"
  case "$host" in
    packages.microsoft.com|download.docker.com|deb.nodesource.com|dl.google.com|dl.yarnpkg.com|apt.llvm.org) return 0 ;;
    *) return 1 ;;
  esac
}

# Disable a failed optional feed only when every URI in its source file is an
# explicitly known optional feed. Ubuntu archives, the PHP PPA, and mixed files
# must survive a transient apt failure.
disable_unreachable_apt_sources() {
  local output="$1"
  local sources_dir="${2:-/etc/apt/sources.list.d}"
  local disabled=0 uri host file sources source source_host optional matches

  while IFS= read -r uri; do
    is_optional_apt_uri "$uri" || continue
    host="${uri#*://}"
    host="${host%%/*}"
    for file in "$sources_dir"/*.list "$sources_dir"/*.sources; do
      [[ -f "$file" && ! -e "$file.disabled" ]] || continue
      sources="$(sed '/^[[:space:]]*#/d' "$file" | grep -oE '[[:alpha:]][[:alnum:]+.-]*:[^[:space:]]+' || true)"
      [[ -n "$sources" ]] || continue
      optional=1
      matches=0
      while IFS= read -r source; do
        is_optional_apt_uri "$source" || optional=0
        source_host="${source#*://}"
        source_host="${source_host%%/*}"
        [[ "$source_host" != "$host" ]] || matches=1
      done <<< "$sources"
      [[ "$optional" == 1 && "$matches" == 1 ]] || continue
      log "Disabling unavailable optional apt feed: $host"
      run_as_root mv -- "$file" "$file.disabled" || return "$?"
      disabled=1
    done
  done <<< "$(printf '%s\n' "$output" | sed -n 's/^Err:[0-9]* \([^ ]*\).*/\1/p' | sort -u)"

  [[ "$disabled" == "1" ]]
}

apt_update() {
  need_cmd apt-get

  local output status attempt

  for attempt in 1 2; do
    if output="$(run_as_root apt-get -o APT::Update::Error-Mode=any update -q 2>&1)"; then
      printf '%s\n' "$output"
      return 0
    else
      status=$?
    fi
    printf '%s\n' "$output"

    if [[ "$attempt" -eq 2 ]] || ! disable_unreachable_apt_sources "$output"; then
      break
    fi

    log "Retrying apt-get update without the unreachable sources"
  done

  return "$status"
}

php_runtime_is_ready() {
  command -v php >/dev/null 2>&1 || return 1
  php -r '
    $required = ["bcmath", "curl", "gd", "intl", "mbstring", "pdo_mysql", "pdo_sqlite", "sodium", "sqlite3", "xml", "zip"];
    $missing = array_filter($required, fn (string $extension): bool => !extension_loaded($extension));
    exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 && $missing === [] ? 0 : 1);
  '
}

ensure_system_dependencies() {
  local packages=()

  if [[ "${CODEX_SKIP_SYSTEM_DEPENDENCIES:-0}" == "1" ]]; then
    log "Skipping Codex system dependency installation"
    return 0
  fi

  if ! php_runtime_is_ready; then
    need_cmd add-apt-repository
    run_as_root add-apt-repository --yes --no-update ppa:ondrej/php
    packages+=(
      php8.5-bcmath
      php8.5-cli
      php8.5-curl
      php8.5-gd
      php8.5-intl
      php8.5-mbstring
      php8.5-mysql
      php8.5-sqlite3
      php8.5-xml
      php8.5-zip
    )
  fi

  if [[ "${#packages[@]}" -gt 0 ]]; then
    need_cmd apt-get
    log "Installing PHP 8.5 system dependencies via apt"
    apt_update
    run_as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${packages[@]}"
    need_cmd update-alternatives
    run_as_root update-alternatives --set php /usr/bin/php8.5
    if command -v phpenv >/dev/null 2>&1; then
      phpenv global system
      phpenv rehash
    fi
    hash -r
  fi

  if ! php_runtime_is_ready; then
    echo "PHP 8.5 or a required PHP extension is unavailable." >&2
    php -r '$required = ["bcmath", "curl", "gd", "intl", "mbstring", "pdo_mysql", "pdo_sqlite", "sodium", "sqlite3", "xml", "zip"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "missing: ext-$extension\n"); } }'
    exit 2
  fi

  php -r 'printf("PHP %s; required extensions enabled\n", PHP_VERSION);'
}

ensure_composer() {
  if command -v composer >/dev/null 2>&1; then
    log "Composer already installed: $(composer --version)"
    return 0
  fi

  log "Installing Composer"
  mkdir -p "$HOME/.local/bin"
  local installer expected_checksum actual_checksum
  installer="$(mktemp)"
  cleanup_paths+=("$installer")
  expected_checksum="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o "$installer"
  actual_checksum="$(php -r "echo hash_file('sha384', '$installer');")"
  if [[ "$expected_checksum" != "$actual_checksum" ]]; then
    echo "ERROR: Invalid Composer installer checksum." >&2
    exit 1
  fi
  php "$installer" --install-dir="$HOME/.local/bin" --filename=composer --quiet
}

ensure_pnpm() {
  if command -v pnpm >/dev/null 2>&1; then
    log "pnpm already installed: $(pnpm --version)"
    return 0
  fi

  need_cmd corepack
  mkdir -p "$HOME/.local/bin"
  corepack enable --install-directory "$HOME/.local/bin"
  local package_manager
  package_manager="$(node -p 'require("./package.json").packageManager || ""')"
  if [[ "$package_manager" != pnpm@* ]]; then
    echo "ERROR: package.json must define packageManager as pnpm@<version>." >&2
    exit 1
  fi
  corepack prepare "$package_manager" --activate
  pnpm --version
}

configure_github_auth() {
  if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    return 0
  fi

  if [[ -z "${COMPOSER_AUTH:-}" ]]; then
    export COMPOSER_AUTH
    COMPOSER_AUTH="$(php -r 'echo json_encode(["github-oauth" => ["github.com" => getenv("GITHUB_TOKEN")]], JSON_UNESCAPED_SLASHES);')"
  fi

  local npmrc existing_userconfig="${NPM_CONFIG_USERCONFIG:-}"
  npmrc="$(mktemp)"
  cleanup_paths+=("$npmrc")
  [[ -f "$existing_userconfig" ]] && cp "$existing_userconfig" "$npmrc"
  {
    printf '//github.com/:_authToken=%s\n' "$GITHUB_TOKEN"
    printf '//github.com/:always-auth=true\n'
  } >> "$npmrc"
  export NPM_CONFIG_USERCONFIG="$npmrc"
}

install_dependencies() {
  log "Checking PHP platform requirements"
  composer check-platform-reqs --lock
  log "Installing PHP dependencies"
  composer install --no-interaction --prefer-dist --no-progress

  log "Installing Node dependencies"
  pnpm install --frozen-lockfile --prefer-offline
  pnpm run hooks:install
}

app_key_is_configured() {
  php -r '
    try {
      require "vendor/autoload.php";
      $values = Dotenv\Dotenv::createArrayBacked(getcwd())->load();
      exit(isset($values["APP_KEY"]) && $values["APP_KEY"] !== "" ? 0 : 1);
    } catch (Throwable) {
      fwrite(STDERR, "Unable to parse local environment configuration.\n");
      exit(2);
    }
  '
}

prepare_laravel_environment() {
  if [[ ! -f .env && -f .env.example ]]; then
    log "Creating local .env from .env.example"
    cp .env.example .env
  fi
  if [[ -f artisan && -f .env ]]; then
    if app_key_is_configured; then
      return 0
    else
      local status=$?
      [[ "$status" == 1 ]] || return "$status"
    fi
    log "Generating Laravel application key"
    php artisan key:generate --no-interaction --force
  fi
}

main() {
  local script_dir repo_root
  script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
  repo_root="$(cd "$script_dir/.." && pwd)"

  trap cleanup EXIT
  export CI="${CI:-1}"
  export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
  export PATH="$HOME/.local/bin:$PATH"
  exec </dev/null
  cd "$repo_root"

  need_cmd curl
  need_cmd node
  ensure_system_dependencies
  need_cmd php
  ensure_composer
  ensure_pnpm
  configure_github_auth
  install_dependencies
  prepare_laravel_environment

  log "Codex environment setup complete"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -Eeuo pipefail
  main "$@"
fi
