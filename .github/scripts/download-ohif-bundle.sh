#!/usr/bin/env bash
# Download the exact immutable artifact id, verify its archive digest, then extract.
set -euo pipefail
[[ "${GITHUB_REPOSITORY:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ \
    && "${OHIF_ARTIFACT_ID:-}" =~ ^[1-9][0-9]{0,15}$ && "${OHIF_ARTIFACT_DIGEST:-}" =~ ^sha256:[a-f0-9]{64}$ \
    && "${OHIF_BUNDLE_DIR:-}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && "$OHIF_BUNDLE_DIR" != . && "$OHIF_BUNDLE_DIR" != .. ]] || exit 2
[[ ! -L "$OHIF_BUNDLE_DIR" ]] || exit 1
mkdir -p "$OHIF_BUNDLE_DIR"
[[ -z "$(find "$OHIF_BUNDLE_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]] || exit 1
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
if ! (ulimit -f 524288; timeout --kill-after=5s 120s gh api "repos/$GITHUB_REPOSITORY/actions/artifacts/$OHIF_ARTIFACT_ID/zip") > "$scratch/artifact.zip" 2> "$scratch/error"; then
    echo 'OHIF artifact download failed or exceeded its bound; details redacted.' >&2; exit 1
fi
[[ "$(wc -c < "$scratch/artifact.zip")" -le 536870912 && "$(sha256sum "$scratch/artifact.zip" | cut -d' ' -f1)" == "${OHIF_ARTIFACT_DIGEST#sha256:}" ]] || {
    echo 'OHIF archive does not match its immutable artifact digest.' >&2; exit 1;
}
if ! timeout --kill-after=5s 60s python3 - "$scratch/artifact.zip" "$OHIF_BUNDLE_DIR" 2> "$scratch/error" <<'PY'
import pathlib, stat, sys, zipfile
root = pathlib.Path(sys.argv[2]).resolve()
with zipfile.ZipFile(sys.argv[1]) as archive:
    entries = archive.infolist()
    if not entries or len(entries) > 100000 or sum(info.file_size for info in entries) > 1073741824:
        raise ValueError('archive bound')
    seen = set()
    for info in entries:
        name = info.filename.rstrip('/')
        path = pathlib.PurePosixPath(name)
        mode = stat.S_IFMT(info.external_attr >> 16)
        if not name or len(name) > 4096 or path.is_absolute() or '..' in path.parts or '\\' in name or str(path) != name \
            or any(ord(char) < 32 or ord(char) == 127 for char in name) or mode not in (0, stat.S_IFREG, stat.S_IFDIR) or name in seen:
            raise ValueError('unsafe archive member')
        seen.add(name)
    archive.extractall(root)
PY
then
    echo 'OHIF artifact extraction failed its bounds or path checks; details redacted.' >&2; exit 1
fi
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
bash "$script_dir/../ohif/verify-dist.sh" "$OHIF_BUNDLE_DIR"
echo 'Exact OHIF artifact archive digest verified before publication.'
