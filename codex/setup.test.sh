#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2329 # Source location and indirect function mocks are intentional.
# Exercise fallback policy with synthetic feeds; never run apt or alter the host.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/setup.sh"
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
run_as_root() { "$@"; }
printf '%s\n' 'deb https://packages.microsoft.com/repos/code stable main' > "$scratch/code.list"
printf '%s\n' 'URIs: https://archive.ubuntu.com/ubuntu' > "$scratch/ubuntu.sources"
printf '%s\n' 'deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main' > "$scratch/php.list"
printf '%s\n' 'URIs: https://download.docker.com/linux/ubuntu https://archive.ubuntu.com/ubuntu' > "$scratch/mixed.sources"
output=$'Err:1 https://packages.microsoft.com/repos/code stable InRelease\nErr:2 https://archive.ubuntu.com/ubuntu noble InRelease\nErr:3 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease\nErr:4 https://download.docker.com/linux/ubuntu noble InRelease'
disable_unreachable_apt_sources "$output" "$scratch"
test -f "$scratch/code.list.disabled"
test -f "$scratch/ubuntu.sources"
test -f "$scratch/php.list"
test -f "$scratch/mixed.sources"
if disable_unreachable_apt_sources "$output" "$scratch"; then exit 1; fi
calls=0
need_cmd() { :; }
run_as_root() {
  test "$*" = 'apt-get -o APT::Update::Error-Mode=any update -q'
  # Files survive the command-substitution subshell, unlike shell counters.
  if [[ ! -f "$scratch/retried" ]]; then
    touch "$scratch/retried"
    echo 'Err:1 https://packages.microsoft.com/repos/code stable InRelease'
    return 100
  fi
  echo 'updated'
}
disable_unreachable_apt_sources() { calls=$((calls + 1)); }
apt_update
test "$calls" = 1
run_as_root() { return 100; }
disable_unreachable_apt_sources() { return 1; }
if apt_update; then exit 1; else test "$?" = 100; fi
echo 'Codex setup fallback checks passed.'
