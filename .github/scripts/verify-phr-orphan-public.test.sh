#!/usr/bin/env bash
set -euo pipefail
readonly script_dir="$(cd "$(dirname "$0")" && pwd)"
readonly fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT
mkdir "$fixture_root/bin"
printf '%s\n' '#!/bin/bash' 'set -euo pipefail' \
    'headers= body= url=; while [[ $# -gt 0 ]]; do case "$1" in --dump-header) headers=$2; shift;; --output) body=$2; shift;; https://*) url=$1;; esac; shift; done' \
    'printf "%s\n" "$url" >> "$MOCK_REQUEST_LOG"' \
    'printf "%s\n" "Cache-Control: private, no-store" "WWW-Authenticate: Bearer resource_metadata=\"https://phr.bherila.net/.well-known/oauth-protected-resource/api/v1\"" > "$headers"' \
    'printf "Location: %s\r\n" "${MOCK_VIEWER_LOCATION:-https://phr.bherila.net/login}" >> "$headers"' \
    'printf "%s\n" "{\"resource\":\"https://phr.bherila.net/api/v1\",\"authorization_servers\":[\"https://phr.bherila.net\"]}" > "$body"' \
    'case "$url" in */ohif/viewer/dicomjson) printf "%s" "${MOCK_VIEWER_STATUS:-302}";; */api/v1/mcp|*/api/v1/genai/queue/status) printf "%s" "${MOCK_PRIVATE_STATUS:-401}";; *) printf 200;; esac' > "$fixture_root/bin/curl"
chmod 700 "$fixture_root/bin/curl"
export MOCK_REQUEST_LOG="$fixture_root/requests"
env PATH="$fixture_root/bin:$PATH" bash "$script_dir/verify-phr-orphan-public.sh"
env PATH="$fixture_root/bin:$PATH" MOCK_VIEWER_LOCATION=/login bash "$script_dir/verify-phr-orphan-public.sh"
! grep -Fxq 'https://phr.bherila.net/ohif/' "$MOCK_REQUEST_LOG"
! grep -Fxq 'https://phr.bherila.net/login' "$MOCK_REQUEST_LOG"
for status in 200 301 404 503; do
    if env PATH="$fixture_root/bin:$PATH" MOCK_VIEWER_STATUS="$status" bash "$script_dir/verify-phr-orphan-public.sh" >/dev/null 2>&1; then
        echo 'Unexpected OHIF viewer status accepted.' >&2; exit 1
    fi
done
for location in https://hostile.invalid/login /ohif/ /login?next=wrong; do
    if env PATH="$fixture_root/bin:$PATH" MOCK_VIEWER_LOCATION="$location" bash "$script_dir/verify-phr-orphan-public.sh" >/dev/null 2>&1; then
        echo 'Unexpected OHIF authentication redirect accepted.' >&2; exit 1
    fi
done
if env PATH="$fixture_root/bin:$PATH" MOCK_PRIVATE_STATUS=200 bash "$script_dir/verify-phr-orphan-public.sh" > /dev/null 2>&1; then
    echo 'Unauthenticated 200 response unexpectedly accepted.' >&2
    exit 1
fi
echo 'PHR public orphan probe tests passed.'
