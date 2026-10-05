#!/usr/bin/env bash
# Runner orchestration: safe aggregate proofs only; never resume or deploy.
set -euo pipefail
[[ ${DIAG_SSH_TARGET:-} =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ \
    && ${DIAG_PHP_BINARY:-} =~ ^/[A-Za-z0-9/._-]+$ \
    && ${DIAG_EXPECTED_RELEASE:-} =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ \
    && ${DIAG_EXPECTED_COMMIT:-} =~ ^[a-f0-9]{40}$ ]] || {
    echo 'Maintenance diagnostic inputs invalid.' >&2; exit 2;
}
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
shared=${PHR_SHARED_ACTION_DIR:?Verified shared action checkout required}
[[ -f "$shared/scripts/operational-audit.sh" && -f "$shared/scripts/verify-web-php.sh" ]] || exit 2
ssh_bin=${PHR_DIAG_SSH_BIN:-ssh}
curl_bin=${PHR_DIAG_CURL_BIN:-curl}
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
ssh_options=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=2)
bounded() {
    # Bound captured SSH output, helper scratch files and response bodies locally.
    (ulimit -f 1024; exec "$@")
}
state_proof() {
    if ! bounded timeout --kill-after=5s 60s "$ssh_bin" "${ssh_options[@]}" "$DIAG_SSH_TARGET" \
        "bash -s -- $(printf '%q ' "$DIAG_PHP_BINARY" "$DIAG_EXPECTED_RELEASE" "$DIAG_EXPECTED_COMMIT")" \
        <"$script_dir/inspect-phr-maintenance.sh" >"$scratch/state" 2>"$scratch/error"; then
        echo 'PHR identity/state proof failed; diagnostics redacted.' >&2; return 1;
    fi
    [[ $(wc -c <"$scratch/state") -le 512 ]] || return 1
    LC_ALL=C awk '
        NR != 1 || $0 !~ /^phr-maintenance identity=exact roots=canonical maintenance_file=(present|absent) lock=absent transactions=0 recovery_cron=(present|absent)$/ { invalid=1 }
        NR == 1 { proof=$0 }
        END { if (NR == 1 && !invalid) { print proof } else { exit 1 } }
    ' "$scratch/state"
}
# Fail before the only temporary public write if a writer or unexpected release is present.
initial_state=$(state_proof) || { echo 'PHR diagnostic refused before probing.' >&2; exit 1; }
printf '%s\n' "$initial_state"
failures=0
if bounded timeout --kill-after=5s 60s "$ssh_bin" "${ssh_options[@]}" "$DIAG_SSH_TARGET" \
    "bash -s -- $(printf '%q ' phr-laravel "$DIAG_PHP_BINARY" 1G "$DIAG_EXPECTED_RELEASE" "$DIAG_EXPECTED_COMMIT" $'storage\npublic/ohif' selected)" \
    <"$shared/scripts/operational-audit.sh" >"$scratch/audit" 2>"$scratch/error" \
    && [[ $(wc -c <"$scratch/audit") -le 512 ]] \
    && aggregate=$(LC_ALL=C awk '
        NR == 1 { if ($0 != "runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=selected") { invalid=1 }; next }
        NR != 2 || $0 !~ /^operational-audit pending_migrations=0 queue_driver=(sync|null|database|redis|sqs|beanstalkd|deferred|background|failover) queue_applicability=(database|no-persistent-queue|external) pending_total=([0-9]+|not-counted) failed_applicability=(database|disabled|external) failed_total=([0-9]+|not-counted)$/ { invalid=1; next }
        { aggregate=$0 }
        END { if (NR == 2 && !invalid) { print "runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=selected"; print aggregate } else { exit 1 } }
    ' "$scratch/audit"); then
    printf '%s\n' "$aggregate"
else
    echo 'PHR selected runtime/operational proof failed; diagnostics redacted.' >&2
    failures=$((failures + 1))
fi
for endpoint in up login; do
    if status=$(bounded "$curl_bin" --silent --show-error --max-time 20 --output /dev/null \
        --write-out '%{http_code}' "https://phr.bherila.net/$endpoint" 2>"$scratch/error") && [[ $status =~ ^[1-5][0-9]{2}$ ]]; then
        printf 'phr-http endpoint=%s status=%s\n' "$endpoint" "$status"
    else
        printf 'phr-http endpoint=%s status=unavailable\n' "$endpoint"
        failures=$((failures + 1))
    fi
done
# Re-check idle identity immediately before creating the temporary web PHP probe.
before_web=$(state_proof) || { echo 'PHR diagnostic stopped before web probe.' >&2; exit 1; }
[[ "$before_web" == "$initial_state" ]] || { echo 'PHR state changed during diagnosis.' >&2; exit 1; }
web_status=0
bounded timeout --kill-after=20s 100s bash "$shared/scripts/verify-web-php.sh" \
    "$DIAG_SSH_TARGET" phr-laravel https://phr.bherila.net 8.5 1024M "${ssh_options[@]}" \
    >"$scratch/web" 2>&1 || web_status=$?
if [[ "$web_status" == 0 ]] && ! grep -Fq 'Could not delete' "$scratch/web"; then
    echo 'phr-web-runtime validated=yes cleanup=confirmed'
else
    echo 'phr-web-runtime validated=no cleanup=unconfirmed'
    failures=$((failures + 1))
fi
final_state=$(state_proof) || { echo 'PHR diagnostic final identity/state proof failed.' >&2; exit 1; }
[[ "$final_state" == "$initial_state" ]] || { echo 'PHR state changed during diagnosis.' >&2; exit 1; }
printf '%s\n' "$final_state"
echo 'phr-diagnostic service_and_cron_mutations=none'
[[ "$failures" == 0 ]]
