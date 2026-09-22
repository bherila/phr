#!/usr/bin/env bash
# Mutation self-check for the OHIF real-transport suite.
#
# Proves the suite can still see the bug `--checksum` exists to prevent: it
# copies converge-ohif-dist.sh to a disposable directory, removes `--checksum`
# from the transfer (and nothing else), verifies that exactly that changed,
# runs converge-ohif-dist.integration.test.sh against the copy, and exits 0
# only if the suite FAILED at a `STALE-CONTENT:` assertion. A mutated suite
# that passes, or fails for any other reason (a missing prerequisite, an
# earlier assertion), exits non-zero.
#
# `--control` is the negative control for this wrapper's own verdict: it skips
# the mutation, so the suite passes and this wrapper must exit non-zero.
#
# Runs under the same environment as the integration suite (root,
# PHR_OHIF_INTEGRATION_REQUIRED, PHR_SHARED_ACTION_DIR, PHR_SHARED_ACTION_SHA),
# which it passes through unchanged. It never modifies the real script.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly original="$script_dir/converge-ohif-dist.sh"
readonly suite="$script_dir/converge-ohif-dist.integration.test.sh"
readonly marker='STALE-CONTENT:'

mutate=true
case "${1:-}" in
    '') ;;
    --control) mutate=false ;;
    *) echo "usage: $0 [--control]" >&2; exit 2 ;;
esac

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
chmod 755 "$work"
readonly copy="$work/converge-ohif-dist.sh"
cp "$original" "$copy"

# Code lines (comments excluded) that carry `--checksum`.
checksum_lines() { grep -v '^[[:space:]]*#' "$1" | grep -c -- '--checksum' || true; }

[[ "$(checksum_lines "$original")" == 1 ]] \
    || { echo "MUTATION-CHECK: expected exactly one code line with --checksum in $original." >&2; exit 1; }

if [[ "$mutate" == true ]]; then
    # shellcheck disable=SC2016  # `$rsync_bin` is the literal script text.
    sed -i -E '/^[[:space:]]*"\$rsync_bin" /s/ --checksum / /' "$copy"
    # The mutation must be exactly: one line changed, and that line is the
    # original with ` --checksum` removed. Anything else is not this test.
    [[ "$(checksum_lines "$copy")" == 0 ]] \
        || { echo 'MUTATION-CHECK: --checksum is still present in the mutated copy.' >&2; exit 1; }
    changed="$(diff "$original" "$copy" || true)"
    [[ "$(grep -c '^<' <<<"$changed")" == 1 && "$(grep -c '^>' <<<"$changed")" == 1 ]] \
        || { echo "MUTATION-CHECK: the mutation changed more than one line:" >&2; echo "$changed" >&2; exit 1; }
    before_line="$(sed -n 's/^< //p' <<<"$changed")"
    after_line="$(sed -n 's/^> //p' <<<"$changed")"
    # shellcheck disable=SC2016  # Matching the literal script text again.
    [[ "${before_line/ --checksum / }" == "$after_line" && "$before_line" == *'"$rsync_bin" -a --checksum --delete'* ]] \
        || { echo "MUTATION-CHECK: unexpected mutation: '$before_line' -> '$after_line'" >&2; exit 1; }
    echo "Mutation applied to a disposable copy:"
    while IFS= read -r line; do echo "    $line"; done <<<"$changed"
else
    cmp -s "$original" "$copy" || { echo 'MUTATION-CHECK: the control copy differs.' >&2; exit 1; }
    echo 'Control run: the copy is unmodified; the suite should pass and this check must fail.'
fi

status=0
PHR_OHIF_CONVERGE_SCRIPT="$copy" bash "$suite" >"$work/suite.log" 2>&1 || status=$?
fail_lines="$(grep '^FAIL:' "$work/suite.log" || true)"
echo "Suite exit status against the copy: $status"
[[ -z "$fail_lines" ]] || echo "Suite failure: $fail_lines"

if [[ "$status" -eq 0 ]]; then
    if [[ "$mutate" == true ]]; then
        echo 'MUTATION-CHECK FAILED: the suite passed against a copy without --checksum; it cannot detect stale content.' >&2
        tail -5 "$work/suite.log" >&2
    else
        echo 'MUTATION-CHECK CONTROL: the suite passed against the unmodified copy, so this wrapper exits non-zero, as it must.' >&2
    fi
    exit 1
fi
if [[ "$(grep -c . <<<"$fail_lines")" != 1 || "$fail_lines" != "FAIL: $marker"* ]]; then
    echo "MUTATION-CHECK FAILED: the suite failed, but not at a $marker assertion." >&2
    tail -20 "$work/suite.log" >&2
    exit 1
fi
echo "MUTATION-CHECK PASSED: removing --checksum is caught by the suite ($marker)."
