#!/usr/bin/env bash
#
# Sync PHR DICOM blobs between web1 (authoritative) and the local x-data mirror.
#
#   pnpm blobs pull            dry-run:  web1 -> x-data
#   pnpm blobs pull --apply    execute
#   pnpm blobs push --apply    x-data -> web1   (restore path; rare)
#
# web1 is the source of truth. `pull` mirrors (deletes local extras); `push` never
# deletes on the remote unless --prune is passed, which also prompts. The direction
# is baked into each subcommand precisely so a transposed rsync argument can't wipe
# production.
set -euo pipefail

# ---------------------------------------------------------------- config
# storage/app/private is synced wholesale rather than per-disk: it is the root of
# every local disk this app defines (phr-dicom, phr-documents, phr-exports), so a
# disk added later is covered without touching this script. Nothing else lives
# there — the repo tracks only a .gitignore under that path.
PROJECT="phr"
REMOTE_HOST="ssh-bwh-php"
REMOTE_PATH="phr-laravel/storage/app/private"
# -----------------------------------------------------------------------

X_DATA="${X_DATA_DIR:-$HOME/proj/x-data}"
LOCAL_PATH="$X_DATA/$PROJECT"
REMOTE="${REMOTE_HOST}:${REMOTE_PATH}"

die() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }

usage() {
  sed -n '3,10p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-1}"
}

MODE="${1:-}"; shift || true
APPLY=0; PRUNE=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --prune) PRUNE=1 ;;
    -h|--help) usage 0 ;;
    *) die "unknown argument: $arg" ;;
  esac
done

# .gitignore is Laravel's stock storage scaffolding: repo-tracked, restored by every
# deploy, and not data. Mirroring it makes web1 and x-data differ by exactly one file.
RSYNC_OPTS=(-a --human-readable --itemize-changes --stats --exclude '.DS_Store' --exclude '.gitignore')
[ "$APPLY" -eq 1 ] && RSYNC_OPTS+=(--partial --progress) || RSYNC_OPTS+=(--dry-run)

case "$MODE" in
  pull)
    mkdir -p "$LOCAL_PATH"; chmod 700 "$LOCAL_PATH"
    # --delete is safe here: it only prunes the local mirror to match web1.
    info "pull  ${REMOTE}/  ->  ${LOCAL_PATH}/   $([ "$APPLY" -eq 1 ] && echo '(APPLY)' || echo '(dry-run)')"
    rsync "${RSYNC_OPTS[@]}" --delete "${REMOTE}/" "${LOCAL_PATH}/"
    ;;

  push)
    [ -d "$LOCAL_PATH" ] || die "local mirror does not exist: $LOCAL_PATH — run 'pull' first"
    # Guard: pushing an empty mirror with --prune would erase production.
    if [ -z "$(find "$LOCAL_PATH" -type f -print -quit)" ]; then
      die "local mirror is empty: $LOCAL_PATH — refusing to push (run 'pull' first)"
    fi
    if [ "$PRUNE" -eq 1 ]; then
      RSYNC_OPTS+=(--delete)
      if [ "$APPLY" -eq 1 ]; then
        printf '\033[31m--prune will DELETE files on %s that are absent locally.\033[0m\n' "$REMOTE"
        read -r -p "Type the project name ($PROJECT) to continue: " confirm
        [ "$confirm" = "$PROJECT" ] || die "aborted"
      fi
    fi
    info "push  ${LOCAL_PATH}/  ->  ${REMOTE}/   $([ "$APPLY" -eq 1 ] && echo '(APPLY)' || echo '(dry-run)')$([ "$PRUNE" -eq 1 ] && echo ' (PRUNE)' || echo '')"
    rsync "${RSYNC_OPTS[@]}" "${LOCAL_PATH}/" "${REMOTE}/"
    ;;

  verify)
    info "comparing file count and bytes"
    remote_stat=$(ssh "$REMOTE_HOST" "find '$REMOTE_PATH' -type f ! -name .gitignore 2>/dev/null | wc -l; find '$REMOTE_PATH' -type f ! -name .gitignore -printf '%s\n' 2>/dev/null | awk '{s+=\$1} END {print s+0}'")
    local_stat=$(printf '%s\n%s\n' \
      "$(find "$LOCAL_PATH" -type f ! -name .gitignore 2>/dev/null | wc -l | tr -d ' ')" \
      "$(find "$LOCAL_PATH" -type f ! -name .gitignore -print0 2>/dev/null | xargs -0 stat -f %z 2>/dev/null | awk '{s+=$1} END {print s+0}')")
    printf 'web1    %s files, %s bytes\n' $(echo "$remote_stat" | tr '\n' ' ')
    printf 'x-data  %s files, %s bytes\n' $(echo "$local_stat" | tr '\n' ' ')
    [ "$(echo "$remote_stat" | tr -d ' \n')" = "$(echo "$local_stat" | tr -d ' \n')" ] \
      && info "match" || die "MISMATCH — re-run 'pull --apply'"
    ;;

  *) usage ;;
esac
