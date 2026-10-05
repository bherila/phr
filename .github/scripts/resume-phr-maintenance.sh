#!/usr/bin/env bash
# Runner: completed owning-CI proof, read-only preflight, then one bounded host session.
set -euo pipefail
[[ ${GITHUB_REPOSITORY:-} == bherila/phr \
    && ${RESUME_SSH_TARGET:-} =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ \
    && ${RESUME_PHP_BINARY:-} =~ ^/[A-Za-z0-9/._-]+$ \
    && ${RESUME_EXPECTED_COMMIT:-} =~ ^[a-f0-9]{40}$ \
    && ${RESUME_EXPECTED_RELEASE:-} =~ ^([a-f0-9]{12})-([0-9]{1,20})-([0-9]{1,4})$ ]] || exit 2
[[ ${BASH_REMATCH[1]} == "${RESUME_EXPECTED_COMMIT:0:12}" ]] || exit 2
owning_run=${BASH_REMATCH[2]} attempt=${BASH_REMATCH[3]}
[[ $owning_run != 0 && $attempt != 0 ]] || exit 2
scripts=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
shared=${PHR_SHARED_ACTION_DIR:?Verified shared helper checkout required}
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
bounded() { (ulimit -f 1024; exec "$@"); }
fail() { echo 'PHR resume refused; private diagnostics redacted.' >&2; exit 1; }
bounded timeout --kill-after=5s 30s gh api "repos/bherila/phr/actions/runs/$owning_run/attempts/$attempt" \
    >"$scratch/run.json" 2>"$scratch/error" || fail
bounded timeout --kill-after=5s 30s gh api "repos/bherila/phr/actions/runs/$owning_run/attempts/$attempt/jobs?per_page=100" \
    >"$scratch/jobs.json" 2>"$scratch/error" || fail
if ! python3 - "$scratch/run.json" "$scratch/jobs.json" "$owning_run" "$attempt" "$RESUME_EXPECTED_COMMIT" "$scratch/incident-window" \
    >"$scratch/proof" 2>"$scratch/error" <<'PY'
import datetime, json, sys
run = json.load(open(sys.argv[1]))
jobs = json.load(open(sys.argv[2]))
assert run['id'] == int(sys.argv[3]) and run['run_attempt'] == int(sys.argv[4])
assert run['status'] == 'completed' and run['conclusion'] in ('success', 'failure')
assert run['head_sha'] == sys.argv[5] and run['head_branch'] == 'main'
assert run['path'] == '.github/workflows/ci.yml' and run['event'] in ('push', 'workflow_dispatch')
assert run['repository']['full_name'] == 'bherila/phr'
assert jobs['total_count'] == len(jobs['jobs']) <= 100
deploy = [job for job in jobs['jobs'] if job['name'] == 'Deploy to Production']
assert len(deploy) == 1 and deploy[0]['run_id'] == int(sys.argv[3])
assert deploy[0]['status'] == 'completed' and deploy[0]['conclusion'] in ('success', 'failure')
def epoch(value):
    return int(datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc).timestamp())
started, completed = epoch(deploy[0]['started_at']), epoch(deploy[0]['completed_at'])
assert 0 < started <= completed and completed - started <= 3600
# Bounded clock skew only; a newly created operator marker must not be resumed.
with open(sys.argv[6], 'x') as output:
    output.write(f'{started - 60} {completed + 60}\n')
print('phr-resume owning_ci=completed identity=exact')
PY
then fail; fi
cmp -s "$scratch/proof" <(printf 'phr-resume owning_ci=completed identity=exact\n') || fail
printf 'phr-resume owning_ci=completed identity=exact\n'
read -r incident_start incident_end <"$scratch/incident-window"
[[ $incident_start =~ ^[0-9]{1,12}$ && $incident_end =~ ^[0-9]{1,12}$ ]] || fail
export DIAG_SSH_TARGET=$RESUME_SSH_TARGET DIAG_PHP_BINARY=$RESUME_PHP_BINARY
export DIAG_EXPECTED_RELEASE=$RESUME_EXPECTED_RELEASE DIAG_EXPECTED_COMMIT=$RESUME_EXPECTED_COMMIT
bounded timeout --kill-after=20s 300s bash "$scripts/diagnose-phr-maintenance.sh" \
    >"$scratch/preflight" 2>"$scratch/error" || fail
# Require actual saved recovery evidence and a maintenance file; do not resume a healthy release.
LC_ALL=C awk '
    NR == 1 || NR == 7 { if ($0 != "phr-maintenance identity=exact roots=canonical maintenance_file=present lock=absent transactions=0 recovery_cron=present") invalid=1; next }
    NR == 2 { if ($0 != "runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=selected") invalid=1; next }
    NR == 3 { if ($0 !~ /^operational-audit pending_migrations=0 queue_driver=(sync|null|database|redis|sqs|beanstalkd|deferred|background|failover) queue_applicability=(database|no-persistent-queue|external) pending_total=([0-9]+|not-counted) failed_applicability=(database|disabled|external) failed_total=([0-9]+|not-counted)$/) invalid=1; next }
    NR == 4 { if ($0 != "phr-http endpoint=up status=200") invalid=1; next }
    NR == 5 { if ($0 != "phr-http endpoint=login status=503") invalid=1; next }
    NR == 6 { if ($0 != "phr-web-runtime validated=yes cleanup=confirmed") invalid=1; next }
    NR == 8 { if ($0 != "phr-diagnostic service_and_cron_mutations=none") invalid=1; next }
    { invalid=1 }
    END { if (NR != 8 || invalid) exit 1 }
' "$scratch/preflight" || fail
echo 'phr-resume diagnostic=passed maintenance_file=present recovery_cron=present'
mkdir "$scratch/bundle" "$scratch/bundle/shared"
cp "$scripts"/resume-phr-{state.php,framework.php,web-ssh.sh,maintenance-remote.sh} \
    "$scripts/verify-phr-cron.sh" "$scratch/bundle/"
cp "$shared/scripts"/{operational-audit.sh,verify-web-php.sh,prepare-cron-lines.sh,install-cron.sh} "$scratch/bundle/shared/"
chmod 700 "$scratch/bundle/resume-phr-web-ssh.sh"
tar -cf "$scratch/helpers.tar" -C "$scratch/bundle" .
nonce="resume-$(openssl rand -hex 16)"
ssh_options=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=2)
# All arguments are validated plain tokens. This session creates only private helper scratch.
remote="umask 077; work=\$(mktemp -d \"\$HOME/.phr-resume.XXXXXXXX\") || exit 1; trap 'rm -rf -- \"\$work\"' EXIT; tar -xf - -C \"\$work\" || exit 1; timeout --kill-after=120s 550s bash \"\$work/resume-phr-maintenance-remote.sh\" $(printf '%q ' "$RESUME_PHP_BINARY" "$RESUME_EXPECTED_RELEASE" "$RESUME_EXPECTED_COMMIT" "$nonce" "$incident_start" "$incident_end") \"\$work\""
status=0
bounded timeout --kill-after=120s 600s ssh "${ssh_options[@]}" "$RESUME_SSH_TARGET" "$remote" \
    <"$scratch/helpers.tar" >"$scratch/result" 2>"$scratch/error" || status=$?
[[ $(wc -c <"$scratch/result") -le 1024 ]] || fail
# One full-file validation before emitting any remote record; never relay captured errors.
if [[ $status == 0 ]]; then
    cmp -s "$scratch/result" <(printf '%s\n' \
        'phr-resume preflight=validated identity=exact runtime=valid pending_migrations=0 web=valid lock=owned' \
        'phr-resume result=serving identity=exact runtime=valid pending_migrations=0 web=valid http_up=200 http_login=healthy cron=canonical lock=released recovery_cron=preserved') || fail
    printf '%s\n' 'phr-resume result=serving identity=exact runtime=valid pending_migrations=0 web=valid http_up=200 http_login=healthy cron=canonical lock=released recovery_cron=preserved'
else
    # Failure labels remain fixed and useful, including uncertain rollback or retained locks.
    LC_ALL=C awk '
        NR == 1 && $0 == "phr-resume preflight=validated identity=exact runtime=valid pending_migrations=0 web=valid lock=owned" { next }
        $0 ~ /^phr-resume result=failed rollback=(maintenance|unconfirmed) cron=(paused|unconfirmed) lock=(released|retained)$/ ||
        $0 ~ /^phr-resume result=refused service_and_cron_mutations=none( lock=(released|retained))?$/ ||
        $0 == "phr-resume result=failed ownership=unconfirmed lock=retained" { if (found) invalid=1; found=1; proof=$0; next }
        { invalid=1 }
        END { if (found && !invalid && NR <= 2) print proof; else exit 1 }
    ' "$scratch/result" || fail
    exit 1
fi
