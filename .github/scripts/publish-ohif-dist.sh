#!/usr/bin/env bash
# Serialize both publisher types; refuse delayed artifacts and verify actual bytes.
set -euo pipefail
for name in OHIF_SSH_TARGET OHIF_BUNDLE_DIR OHIF_RUN_ID OHIF_ARTIFACT_ID OHIF_ARTIFACT_DIGEST OHIF_SOURCE_COMMIT GITHUB_RUN_ID GITHUB_RUN_ATTEMPT; do
    [[ -n "${!name:-}" ]] || { echo 'OHIF publication identity missing.' >&2; exit 2; }
done
[[ "$OHIF_SSH_TARGET" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9.-]*)?$ \
    && "$OHIF_RUN_ID" =~ ^[1-9][0-9]{0,15}$ && "$OHIF_ARTIFACT_ID" =~ ^[1-9][0-9]{0,15}$ \
    && "$OHIF_ARTIFACT_DIGEST" =~ ^sha256:[a-f0-9]{64}$ && "$OHIF_SOURCE_COMMIT" =~ ^[a-f0-9]{40}$ \
    && "$GITHUB_RUN_ID" =~ ^[1-9][0-9]{0,15}$ && "$GITHUB_RUN_ATTEMPT" =~ ^[1-9][0-9]{0,5}$ ]] || exit 2
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ssh_bin=${PHR_OHIF_SSH_BIN:-ssh}
owner="ohif-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
borrow=${OHIF_LOCK_OWNER:-}
if [[ -n "$borrow" ]]; then [[ "$borrow" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && ${#borrow} -le 128 ]] || exit 2; fi
export OHIF_DEPLOY_DIR=phr-laravel
[[ -d "$OHIF_BUNDLE_DIR" && ! -L "$OHIF_BUNDLE_DIR" && -z "$(find "$OHIF_BUNDLE_DIR" -type l -print -quit)" ]] || exit 1
bash "$script_dir/../ohif/verify-dist.sh" "$OHIF_BUNDLE_DIR"
desired=$(cd "$OHIF_BUNDLE_DIR" && LC_ALL=C find . -type f ! -path ./.ohif-digest -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum | sha256sum | cut -d' ' -f1)
remote() {
    timeout --kill-after=5s 120s "$ssh_bin" "$OHIF_SSH_TARGET" \
        "timeout --kill-after=5s 90s bash -s -- $(printf '%q ' "$1" phr-laravel "${@:2}")" < "$script_dir/ohif-publication-remote.sh"
}
own_lock=false
cleanup() {
    local status=$?
    if [[ "$own_lock" == true ]]; then
        if ! remote release "$owner"; then echo 'OHIF mutex could not be released; manual recovery required.' >&2; status=1; fi
    fi
    exit "$status"
}
trap cleanup EXIT
# Interrupted transport may still have an active remote descendant. Keep the
# mutex for deliberate recovery instead of allowing another writer to overlap.
trap 'own_lock=false; exit 1' INT TERM
acquired=$(remote acquire "$owner" "$borrow")
case "$acquired" in owned) own_lock=true; active_owner=$owner ;; borrowed) active_owner=$borrow ;; *) exit 1 ;; esac
declare -A live=()
inspect() {
    local output key value
    output=$(remote inspect)
    live=()
    while IFS='=' read -r key value; do
        case "$key" in run_id|artifact_id|artifact_digest|source_commit|bundle_digest|actual_digest|record_digest) live[$key]=$value ;; *) exit 1 ;; esac
    done <<< "$output"
    [[ ${#live[@]} == 7 && ${live[run_id]} =~ ^[0-9]{1,16}$ ]] || exit 1
}
valid_live() { [[ -n "${live[bundle_digest]}" && "${live[bundle_digest]}" == "${live[actual_digest]}" && "${live[record_digest]}" == "v2:${live[bundle_digest]}" ]]; }
inspect
printf 'OHIF request writer=%s/%s source=%s run=%s artifact=%s archive=%s bundle=%s\n' "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$OHIF_SOURCE_COMMIT" "$OHIF_RUN_ID" "$OHIF_ARTIFACT_ID" "$OHIF_ARTIFACT_DIGEST" "$desired"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    # shellcheck disable=SC2016 # Markdown backticks are intentionally literal.
    printf 'OHIF publication request: source `%s`, run `%s`, artifact `%s`, archive `%s`, bundle `%s`.\n' "$OHIF_SOURCE_COMMIT" "$OHIF_RUN_ID" "$OHIF_ARTIFACT_ID" "$OHIF_ARTIFACT_DIGEST" "$desired" >> "$GITHUB_STEP_SUMMARY"
fi
if (( 10#${live[run_id]} > 10#$OHIF_RUN_ID )) || { [[ "${live[run_id]}" == "$OHIF_RUN_ID" && -n "${live[artifact_id]}" ]] && (( 10#${live[artifact_id]} > 10#$OHIF_ARTIFACT_ID )); }; then
    valid_live || { echo 'Newer OHIF publication needs repair; refusing an older artifact.' >&2; exit 1; }
    message="OHIF request run=$OHIF_RUN_ID artifact=$OHIF_ARTIFACT_ID superseded by run=${live[run_id]} artifact=${live[artifact_id]} source=${live[source_commit]}."
    echo "$message"
    [[ -z "${GITHUB_STEP_SUMMARY:-}" ]] || printf '%s\n' "$message" >> "$GITHUB_STEP_SUMMARY"
    exit 0
fi
if [[ "${live[run_id]}" == "$OHIF_RUN_ID" && "${live[artifact_id]}" == "$OHIF_ARTIFACT_ID" && -n "${live[artifact_digest]}" \
    && ( "${live[artifact_digest]}" != "$OHIF_ARTIFACT_DIGEST" || "${live[source_commit]}" != "$OHIF_SOURCE_COMMIT" || "${live[bundle_digest]}" != "$desired" ) ]]; then
    echo 'Immutable OHIF artifact identity changed; refusing publication.' >&2; exit 1
fi
# Repair corrupted bytes even when the old v2 marker falsely claims convergence.
if [[ -n "${live[actual_digest]}" && "${live[record_digest]}" == "v2:$desired" && "${live[actual_digest]}" != "$desired" ]]; then remote clear "$active_owner"; fi
if ! timeout --kill-after=10s 300s bash "$script_dir/converge-ohif-dist.sh"; then
    if [[ "$own_lock" == true ]]; then
        own_lock=false
        echo 'Interrupted OHIF transfer retains its mutex; prove the writer stopped before manual recovery.' >&2
    fi
    exit 1
fi
inspect
[[ "${live[actual_digest]}" == "$desired" && "${live[record_digest]}" == "v2:$desired" ]] || { echo 'OHIF actual-byte proof failed.' >&2; exit 1; }
remote commit "$active_owner" "$OHIF_RUN_ID" "$OHIF_ARTIFACT_ID" "$OHIF_ARTIFACT_DIGEST" "$OHIF_SOURCE_COMMIT" "$desired" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT"
inspect
valid_live && [[ "${live[run_id]}" == "$OHIF_RUN_ID" && "${live[artifact_id]}" == "$OHIF_ARTIFACT_ID" \
    && "${live[artifact_digest]}" == "$OHIF_ARTIFACT_DIGEST" && "${live[source_commit]}" == "$OHIF_SOURCE_COMMIT" ]] || exit 1
echo 'OHIF source, artifact identity and full tree bytes verified.'
