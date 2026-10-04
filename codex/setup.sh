#!/usr/bin/env bash
# shellcheck disable=SC2016 # PHP source intentionally uses literal shell strings.
set -Eeuo pipefail

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
}

trap cleanup EXIT

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

# Optional image-provided package feeds can be unavailable behind an egress proxy.
# Never disable Ubuntu archives, the required PHP PPA, or multi-feed source files.
disable_unreachable_apt_sources() {
  local output="$1" uri host file sources source source_host optional
  local disabled=0
  local sources_dir="${2:-/etc/apt/sources.list.d}"
  while IFS= read -r uri; do
    host="${uri#*://}"
    host="${host%%/*}"
    case "$host" in
      packages.microsoft.com|download.docker.com|deb.nodesource.com|dl.google.com|dl.yarnpkg.com) ;;
      *) continue ;;
    esac
    for file in "$sources_dir"/*.list "$sources_dir"/*.sources; do
      [[ -f "$file" ]] || continue
      grep -Fq "$uri" "$file" || continue
      sources="$(sed '/^[[:space:]]*#/d' "$file" | grep -oE 'https?://[^[:space:]]+' || true)"
      optional=1
      while IFS= read -r source; do
        [[ -n "$source" ]] || continue
        source_host="${source#*://}"
        source_host="${source_host%%/*}"
        [[ "$source_host" == "$host" ]] || optional=0
      done <<< "$sources"
      [[ "$optional" == 1 && ! -e "$file.disabled" ]] || continue
      log "Disabling unavailable optional apt feed: $host"
      run_as_root mv -- "$file" "$file.disabled"
      disabled=1
    done
  done <<< "$(printf '%s\n' "$output" | sed -n 's/^Err:[0-9]* \([^ ]*\).*/\1/p' | sort -u)"
  [[ "$disabled" == 1 ]]
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
    if [[ "$attempt" == 2 ]] || ! disable_unreachable_apt_sources "$output"; then
      return "$status"
    fi
    log "Retrying apt-get update without the unavailable optional feed"
  done
}

php_runtime_is_ready() {
  php -r '
    $required = ["bcmath", "curl", "gd", "intl", "mbstring", "pdo_mysql", "pdo_sqlite", "dom", "xml", "xmlreader", "xmlwriter", "sodium", "sqlite3", "zip"];
    $missing = array_filter($required, fn (string $extension): bool => !extension_loaded($extension));
    exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 && $missing === [] ? 0 : 1);
  '
}

ensure_php_runtime() {
  if php_runtime_is_ready; then
    log "PHP 8.5 and required extensions already available"
    return 0
  fi

  log "Installing PHP 8.5 and required extensions"
  need_cmd add-apt-repository
  run_as_root add-apt-repository --yes --no-update ppa:ondrej/php
  apt_update
  run_as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    php8.5-bcmath \
    php8.5-cli \
    php8.5-curl \
    php8.5-gd \
    php8.5-intl \
    php8.5-mbstring \
    php8.5-mysql \
    php8.5-sqlite3 \
    php8.5-xml \
    php8.5-zip

  run_as_root update-alternatives --set php /usr/bin/php8.5
  if command -v phpenv >/dev/null 2>&1; then
    phpenv global system
    phpenv rehash
  fi
  hash -r

  if ! php_runtime_is_ready; then
    echo "PHP 8.5 is selected, but one or more required extensions are unavailable." >&2
    php -r '$required = ["bcmath", "curl", "gd", "intl", "mbstring", "pdo_mysql", "pdo_sqlite", "dom", "xml", "xmlreader", "xmlwriter", "sodium", "sqlite3", "zip"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "missing: ext-$extension\n"); } }'
    exit 2
  fi
  php -r 'printf("PHP %s; required extensions enabled\n", PHP_VERSION);'
}

ensure_pdftotext() {
  if command -v pdftotext >/dev/null 2>&1; then
    log "pdftotext already installed: $(pdftotext -v 2>&1 | head -n 1)"
    return 0
  fi

  log "Installing poppler-utils via apt"
  apt_update
  run_as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends poppler-utils
}

ensure_composer() {
  if command -v composer >/dev/null 2>&1; then
    log "Composer already installed: $(composer --version)"
    return 0
  fi

  log "Installing Composer"

  mkdir -p "$HOME/.local/bin"

  local installer
  installer="$(mktemp)"
  cleanup_paths+=("$installer")

  local expected_checksum
  local actual_checksum
  expected_checksum="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o "$installer"
  actual_checksum="$(php -r "echo hash_file('sha384', '$installer');")"

  if [[ "$expected_checksum" != "$actual_checksum" ]]; then
    echo "ERROR: Invalid Composer installer checksum." >&2
    exit 1
  fi

  php "$installer" \
    --install-dir="$HOME/.local/bin" \
    --filename=composer \
    --quiet
}

ensure_pnpm() {
  if command -v pnpm >/dev/null 2>&1; then
    log "pnpm already installed: $(pnpm --version)"
    return 0
  fi

  log "Installing pnpm via Corepack"

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
  if [[ -z "${GITHUB_TOKEN:-}" || -n "${COMPOSER_AUTH:-}" ]]; then
    return 0
  fi

  export COMPOSER_AUTH
  COMPOSER_AUTH="$(php -r 'echo json_encode(["github-oauth" => ["github.com" => getenv("GITHUB_TOKEN")]], JSON_UNESCAPED_SLASHES);')"
}

install_php_dependencies() {
  log "Checking PHP platform requirements"
  composer check-platform-reqs --lock

  log "Installing PHP dependencies"
  composer install --no-interaction --prefer-dist --no-progress
}

install_node_dependencies() {
  log "Installing Node dependencies"
  pnpm install --frozen-lockfile --prefer-offline
}

prepare_laravel_environment() {
  if [[ ! -f .env && -f .env.example ]]; then
    log "Creating local .env from .env.example"
    cp .env.example .env
  fi

  if [[ -f artisan && -f .env ]] && ! grep -Eq '^APP_KEY=base64:.+' .env; then
    log "Generating Laravel application key"
    php artisan key:generate --no-interaction --force
  fi
}

main() {
  SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

  export CI="${CI:-1}"
  export PATH="$HOME/.local/bin:$PATH"

  cd "$REPO_ROOT"

  need_cmd curl
  need_cmd node
  need_cmd php

  ensure_php_runtime
  ensure_pdftotext
  ensure_composer
  configure_github_auth
  install_php_dependencies
  ensure_pnpm
  install_node_dependencies
  prepare_laravel_environment

  log "Codex environment setup complete"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
