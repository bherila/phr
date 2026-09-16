#!/usr/bin/env bash
set -euo pipefail
readonly script_dir="$(cd "$(dirname "$0")" && pwd)"
readonly workflow="$script_dir/../workflows/ci.yml"
grep -Fq 'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1' "$workflow"
while IFS= read -r pin; do
    [[ "$pin" =~ ^[a-f0-9]{40}$ ]]
done < <(sed -nE 's/.*uses: [^@]+@([a-f0-9]+)([[:space:]]|$).*/\1/p' "$workflow" | awk 'length($0) > 8')
readonly fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT
mkdir -p "$fixture_root/phr-laravel/public" "$fixture_root/.deployments/phr-laravel/shared/storage/framework" \
    "$fixture_root/.deployments/phr-laravel/shared/public/ohif" "$fixture_root/.deployments/phr-laravel/state" \
    "$fixture_root/.deployments/phr-laravel/releases" "$fixture_root/.deployments/phr-laravel/recovery" "$fixture_root/bin"
ln -s "$fixture_root/.deployments/phr-laravel/shared/storage" "$fixture_root/phr-laravel/storage"
ln -s "$fixture_root/.deployments/phr-laravel/shared/public/ohif" "$fixture_root/phr-laravel/public/ohif"
printf '%s\n' 'release=legacy-20260916T131957Z-c765e4086fa2-35100840905-1' \
    'commit=153ddfdcb6e8180bf1d1291aa74202e13a92681e' > "$fixture_root/phr-laravel/.deploy-release"
printf '%s\n' 'NEVER_PRINT_MARKER_CANARY' > "$fixture_root/phr-laravel/storage/framework/down"
printf '%s\n' '* * * * * NEVER_PRINT_CRON_CANARY # JOB:phr-laravel-scheduler' \
    '* * * * * NEVER_PRINT_SECRET_CANARY # JOB:phr-laravel-queue-worker' \
    > "$fixture_root/.deployments/phr-laravel/recovery/c765e4086fa2-35100840905-1.cron"
printf '%s\n' '#!/bin/bash' '[[ "$*" == "-l" ]] || exit 99' 'echo "* * * * * NEVER_PRINT_UNRELATED_CANARY"' > "$fixture_root/bin/crontab"
chmod 700 "$fixture_root/bin/crontab"
before="$(find "$fixture_root" -type f -exec sha256sum {} + | sort)"
output="$(env HOME="$fixture_root" PATH="$fixture_root/bin:$PATH" bash "$script_dir/diagnose-phr-orphan.sh")"
[[ "$output" == *'selected_identity=exact_expected'* && "$output" == *'lock=missing'* \
    && "$output" == *'failed_transaction=missing'* && "$output" == *'snapshot_scheduler_markers=1'* \
    && "$output" == *'snapshot_worker_markers=1'* && "$output" != *NEVER_PRINT* ]]
[[ "$before" == "$(find "$fixture_root" -type f -exec sha256sum {} + | sort)" ]]
printf '%s\n' 'commit=WRONG_SECRET_CANARY' >> "$fixture_root/phr-laravel/.deploy-release"
if output="$(env HOME="$fixture_root" PATH="$fixture_root/bin:$PATH" bash "$script_dir/diagnose-phr-orphan.sh" 2>&1)"; then
    echo 'Duplicate/wrong metadata unexpectedly accepted.' >&2
    exit 1
fi
[[ "$output" == *REDACTED* && "$output" != *WRONG_SECRET_CANARY* ]]
echo 'PHR orphan diagnostic tests passed.'
