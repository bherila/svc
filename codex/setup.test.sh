#!/usr/bin/env bash
# Exercise bootstrap decisions with synthetic fixtures; never run apt or migrations.
set -Eeuo pipefail

setup_script="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/setup.sh"
repo_root="$(dirname "$(dirname "$setup_script")")"
php_binary="$(command -v php)"
scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT
# A guard regression must fail the test without letting setup mutate the host.
composer() { return 99; }
pnpm() { return 99; }
curl() { return 99; }
corepack() { return 99; }
sudo() { return 99; }
apt-get() { return 99; }
add-apt-repository() { return 99; }
source "$setup_script"

test_source_has_no_execution_side_effects() (
  original_path="$PATH"
  export CI=setup-regression-sentinel
  original_trap="$(trap -p EXIT)"
  source "$setup_script"
  [[ "$PATH" == "$original_path" && "$CI" == setup-regression-sentinel ]]
  [[ "$(trap -p EXIT)" == "$original_trap" ]]
  printf '%s\n' sentinel | (
    source "$setup_script"
    IFS= read -r line
    [[ "$line" == sentinel ]]
  )
)

test_empty_cleanup_preserves_success() (
  trap cleanup EXIT
  cleanup_paths=()
  cleanup
  exit 0
)

test_dependency_install_enables_disclosure_hook() (
  trace="$scratch/dependencies.trace"
  composer() { printf 'composer %s\n' "$*" >> "$trace"; }
  pnpm() { printf 'pnpm %s\n' "$*" >> "$trace"; }
  install_dependencies
  [[ "$(cat "$trace")" == $'composer check-platform-reqs --lock\ncomposer install --no-interaction --prefer-dist --no-progress\npnpm install --frozen-lockfile --prefer-offline\npnpm run hooks:install' ]]
)

test_missing_php_reaches_installer_before_php_requirement() (
  mock_php_available=0
  export CODEX_SKIP_SYSTEM_DEPENDENCIES=0
  command() {
    if [[ "$*" == '-v php' ]]; then
      [[ "$mock_php_available" == 1 ]]
    elif [[ "$*" == '-v phpenv' ]]; then
      return 1
    elif [[ "$1" == -v && "$2" =~ ^(add-apt-repository|apt-get|update-alternatives)$ ]]; then
      return 0
    else
      builtin command "$@"
    fi
  }
  php() {
    if [[ "$mock_php_available" != 1 ]]; then
      touch "$scratch/premature-php"
      return 127
    fi
  }
  run_as_root() {
    case "$1" in
      add-apt-repository) [[ "$*" == 'add-apt-repository --yes --no-update ppa:ondrej/php' ]] ;;
      env)
        [[ " $* " == *' apt-get install '* && " $* " == *' php8.5-cli '* ]]
        mock_php_available=1
        ;;
      update-alternatives) [[ "$*" == 'update-alternatives --set php /usr/bin/php8.5' ]] ;;
      *) return 1 ;;
    esac
  }
  apt_update() { :; }
  ensure_composer() { :; }
  ensure_pnpm() { :; }
  configure_github_auth() { :; }
  install_dependencies() { :; }
  prepare_laravel_environment() { :; }
  main
  [[ "$mock_php_available" == 1 && ! -e "$scratch/premature-php" ]]
)

test_keys_are_parsed_and_preserved() (
  fixture="$scratch/keys"
  mkdir "$fixture"
  ln -s "$repo_root/vendor" "$fixture/vendor"
  touch "$fixture/artisan"
  cd "$fixture"
  php() {
    if [[ "$1" == artisan ]]; then
      [[ "$*" == 'artisan key:generate --no-interaction --force' ]]
      touch generated
      printf '%s\n' 'APP_KEY=synthetic-generated-key' > .env
    else
      "$php_binary" "$@"
    fi
  }
  for key_line in \
    'APP_KEY=0123456789abcdef0123456789abcdef' \
    'APP_KEY="base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE="' \
    "APP_KEY='0123456789abcdef0123456789abcdef'"; do
    printf '%s\n' "$key_line" > .env
    cp .env before.env
    prepare_laravel_environment
    cmp -s .env before.env
    [[ ! -e generated ]]
  done
  for key_line in 'APP_KEY=' 'APP_KEY=""' 'APP_NAME=SyntheticFixture'; do
    printf '%s\n' "$key_line" > .env
    prepare_laravel_environment
    [[ -f generated ]]
    rm generated
  done
  printf '%s\n' 'APP_KEY=two words' > .env
  cp .env before.env
  if prepare_laravel_environment > output.log 2> error.log; then
    return 1
  else
    [[ "$?" == 2 ]]
  fi
  cmp -s .env before.env
  [[ ! -e generated ]]
  [[ "$(cat error.log)" == 'Unable to parse local environment configuration.' ]]
)

test_only_optional_apt_source_files_are_disabled() (
  sources_dir="$scratch/sources"
  mkdir "$sources_dir"
  run_as_root() { "$@"; }
  printf '%s\n' 'deb https://packages.microsoft.com/repos/code stable main' > "$sources_dir/code.list"
  printf '%s\n' 'URIs: https://download.docker.com/linux/ubuntu' > "$sources_dir/docker.sources"
  printf '%s\n' 'URIs: https://snapshot.ubuntu.com/ubuntu' > "$sources_dir/ubuntu.sources"
  printf '%s\n' 'deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main' > "$sources_dir/php.list"
  printf '%s\n' 'URIs: https://download.docker.com/linux/ubuntu https://archive.ubuntu.com/ubuntu' > "$sources_dir/mixed.sources"
  printf '%s\n' 'URIs: https://packages.microsoft.com/repos/code file:///var/cache/local' > "$sources_dir/local-mixed.sources"
  printf '%s\n' 'deb https://unknown.example.test/packages stable main' > "$sources_dir/unknown.list"
  output=$'Err:1 https://packages.microsoft.com/repos/code stable InRelease\nErr:2 https://snapshot.ubuntu.com/ubuntu noble InRelease\nErr:3 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease\nErr:4 https://download.docker.com/linux/ubuntu noble InRelease\nErr:5 https://unknown.example.test/packages stable InRelease'
  disable_unreachable_apt_sources "$output" "$sources_dir"
  [[ -f "$sources_dir/code.list.disabled" && -f "$sources_dir/docker.sources.disabled" ]]
  for source_name in ubuntu.sources php.list mixed.sources local-mixed.sources unknown.list; do
    [[ -f "$sources_dir/$source_name" && ! -e "$sources_dir/$source_name.disabled" ]]
  done
  if disable_unreachable_apt_sources "$output" "$sources_dir"; then return 1; fi
)

test_apt_retries_once_and_propagates_essential_failures() (
  need_cmd() { :; }
  trace="$scratch/apt.trace"
  run_as_root() {
    [[ "$*" == 'apt-get -o APT::Update::Error-Mode=any update -q' ]]
    printf 'attempt\n' >> "$trace"
    if [[ ! -f "$scratch/apt-retried" ]]; then
      touch "$scratch/apt-retried"
      printf '%s\n' 'Err:1 https://packages.microsoft.com/repos/code stable InRelease'
      return 100
    fi
  }
  disable_unreachable_apt_sources() { :; }
  apt_update
  [[ "$(wc -l < "$trace")" == 2 ]]
  run_as_root() { return 100; }
  disable_unreachable_apt_sources() { return 1; }
  if apt_update; then return 1; else [[ "$?" == 100 ]]; fi
)

for case_name in \
  test_source_has_no_execution_side_effects \
  test_empty_cleanup_preserves_success \
  test_dependency_install_enables_disclosure_hook \
  test_missing_php_reaches_installer_before_php_requirement \
  test_keys_are_parsed_and_preserved \
  test_only_optional_apt_source_files_are_disabled \
  test_apt_retries_once_and_propagates_essential_failures; do
  "$case_name"
  printf 'PASS: %s\n' "$case_name"
done
