#!/usr/bin/env bash
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

SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

export CI="${CI:-1}"
export PATH="$HOME/.local/bin:$PATH"

cd "$REPO_ROOT"

need_cmd curl
need_cmd node
need_cmd php

ensure_pdftotext() {
  if command -v pdftotext >/dev/null 2>&1; then
    log "pdftotext already installed: $(pdftotext -v 2>&1 | head -n 1)"
    return 0
  fi

  need_cmd apt-get
  log "Installing poppler-utils via apt"
  run_as_root apt-get update
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

ensure_pdftotext
ensure_composer
configure_github_auth
install_php_dependencies
ensure_pnpm
install_node_dependencies
prepare_laravel_environment

log "Codex environment setup complete"
