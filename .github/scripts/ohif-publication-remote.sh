#!/usr/bin/env bash
# Only static OHIF bytes and app-scoped deployment control metadata are accessed.
set -euo pipefail
[[ $# -ge 2 && $2 == phr-laravel ]] || exit 2
mode=$1
shift 2
control="$HOME/.deployments/phr-laravel"
shared="$control/shared"
manifest="$shared/ohif-publication"
lock="$control/deploy.lock"
fail() { echo 'OHIF publication control or identity proof failed; details redacted.' >&2; exit 1; }
plain() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && ${#1} -le 128 ]]; }
real_dir() { [[ -d "$1" && ! -L "$1" && "$(readlink -f "$1")" == "$1" ]]; }
real_dir "$HOME" || fail
for directory in "$HOME/.deployments" "$control" "$shared" "$shared/public"; do
    if [[ -e "$directory" || -L "$directory" ]]; then real_dir "$directory" || fail
    elif [[ "$mode" == acquire ]]; then mkdir -m 700 "$directory"
    else fail
    fi
done
case "$mode" in
    acquire)
        [[ $# == 2 ]] || exit 2
        owner=$1 borrow=$2
        plain "$owner" || exit 2
        if [[ -n "$borrow" ]]; then
            plain "$borrow" || exit 2
            real_dir "$lock" && [[ -f "$lock/owner" && ! -L "$lock/owner" && "$(cat "$lock/owner")" == "$borrow" ]] || fail
            [[ -f "$control/generation" && ! -L "$control/generation" && "$(cat "$control/generation")" == "$borrow" ]] || fail
            echo borrowed
        else
            mkdir -m 700 "$lock" || fail
            printf '%s\n' "$owner" > "$lock/owner"
            temporary=''
            if ! {
                temporary=$(mktemp "$control/.generation.XXXXXX") \
                    && printf '%s\n' "$owner" > "$temporary" \
                    && chmod 600 "$temporary" \
                    && mv -T "$temporary" "$control/generation"
            }; then
                [[ -z "$temporary" ]] || rm -f -- "$temporary"
                if [[ -f "$lock/owner" && ! -L "$lock/owner" && "$(cat "$lock/owner")" == "$owner" ]]; then
                    rm "$lock/owner"
                    rmdir "$lock"
                fi
                fail
            fi
            echo owned
        fi
        exit 0 ;;
    release)
        [[ $# == 1 ]] && plain "$1" || exit 2
        real_dir "$lock" && [[ -f "$lock/owner" && ! -L "$lock/owner" && "$(cat "$lock/owner")" == "$1" ]] || fail
        rm "$lock/owner"
        rmdir "$lock"
        exit 0 ;;
    inspect|clear|commit) ;;
    *) exit 2 ;;
esac
# Mutations must be made by the exact mutex owner in the same remote process.
if [[ "$mode" != inspect ]]; then
    [[ $# -ge 1 ]] && plain "$1" || exit 2
    real_dir "$lock" && [[ -f "$lock/owner" && ! -L "$lock/owner" && "$(cat "$lock/owner")" == "$1" ]] || fail
    shift
fi
root="$shared/public/ohif"
if [[ ! -e "$root" && ! -L "$root" && -d "$HOME/phr-laravel/public/ohif" && ! -L "$HOME/phr-laravel/public/ohif" ]]; then
    # Preserve the convergence script's supported pre-conversion layout.
    real_dir "$HOME/phr-laravel" || fail
    [[ -f "$HOME/phr-laravel/artisan" ]] || fail
    real_dir "$HOME/phr-laravel/public" || fail
    root="$HOME/phr-laravel/public/ohif"
fi
if [[ "$mode" == clear ]]; then
    real_dir "$root" || fail
    [[ ! -L "$root/.ohif-digest" ]] || fail
    rm -f "$root/.ohif-digest"
    exit 0
fi
if [[ "$mode" == commit ]]; then
    [[ $# == 7 && $1 =~ ^[1-9][0-9]{0,15}$ && $2 =~ ^[1-9][0-9]{0,15}$ && $3 =~ ^sha256:[a-f0-9]{64}$ \
        && $4 =~ ^[a-f0-9]{40}$ && $5 =~ ^[a-f0-9]{64}$ && $6 =~ ^[1-9][0-9]{0,15}$ && $7 =~ ^[1-9][0-9]{0,5}$ ]] || exit 2
    [[ ! -L "$manifest" && ( ! -e "$manifest" || -f "$manifest" ) ]] || fail
    temporary=$(mktemp "$shared/.ohif-publication.XXXXXX")
    printf 'version=1\nrun_id=%s\nartifact_id=%s\nartifact_digest=%s\nsource_commit=%s\nbundle_digest=%s\nwriter_run_id=%s\nwriter_attempt=%s\n' "$@" > "$temporary"
    chmod 600 "$temporary"
    mv -T "$temporary" "$manifest"
    exit 0
fi
[[ $# == 0 ]] || exit 2
declare -A record=()
if [[ -e "$manifest" || -L "$manifest" ]]; then
    [[ -f "$manifest" && ! -L "$manifest" && "$(wc -c < "$manifest")" -le 1024 ]] || fail
    while IFS='=' read -r key value; do
        case "$key" in version|run_id|artifact_id|artifact_digest|source_commit|bundle_digest|writer_run_id|writer_attempt) ;; *) fail ;; esac
        [[ ! -v "record[$key]" ]] || fail
        record[$key]=$value
    done < "$manifest"
    [[ ${#record[@]} == 8 && ${record[version]:-} == 1 && ${record[run_id]:-} =~ ^[1-9][0-9]{0,15}$ \
        && ${record[artifact_id]:-} =~ ^[1-9][0-9]{0,15}$ && ${record[artifact_digest]:-} =~ ^sha256:[a-f0-9]{64}$ \
        && ${record[source_commit]:-} =~ ^[a-f0-9]{40}$ && ${record[bundle_digest]:-} =~ ^[a-f0-9]{64}$ \
        && ${record[writer_run_id]:-} =~ ^[1-9][0-9]{0,15}$ && ${record[writer_attempt]:-} =~ ^[1-9][0-9]{0,5}$ ]] || fail
fi
actual='' marker=''
if [[ -e "$root" || -L "$root" ]]; then
    real_dir "$root" || fail
    [[ -z "$(find "$root" ! -type d ! -type f -print -quit)" ]] || fail
    actual=$(cd "$root" && LC_ALL=C find . -type f ! -path ./.ohif-digest -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum | sha256sum | cut -d' ' -f1)
    if [[ -e "$root/.ohif-digest" ]]; then
        [[ -f "$root/.ohif-digest" && ! -L "$root/.ohif-digest" && "$(wc -c < "$root/.ohif-digest")" -le 129 ]] || fail
        marker=$(cat "$root/.ohif-digest")
        # Legacy records are deliberately not echoed or trusted.
        [[ "$marker" =~ ^v2:[a-f0-9]{64}$ ]] || marker=''
    fi
fi
printf 'run_id=%s\nartifact_id=%s\nartifact_digest=%s\nsource_commit=%s\nbundle_digest=%s\nactual_digest=%s\nrecord_digest=%s\n' \
    "${record[run_id]:-0}" "${record[artifact_id]:-}" "${record[artifact_digest]:-}" "${record[source_commit]:-}" "${record[bundle_digest]:-}" "$actual" "$marker"
