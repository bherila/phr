#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
mkdir -p "$scratch/bin"
cat > "$scratch/bin/gh" <<'GH'
#!/usr/bin/env bash
[[ "$*" == 'api repos/synthetic/phr/actions/artifacts/123/zip' ]] || exit 2
cat "$FIXTURE_ARCHIVE"
GH
chmod +x "$scratch/bin/gh"
export PATH="$scratch/bin:$PATH" GITHUB_REPOSITORY=synthetic/phr OHIF_ARTIFACT_ID=123
python3 - "$scratch" <<'PY'
import pathlib, stat, sys, zipfile
root = pathlib.Path(sys.argv[1])
with zipfile.ZipFile(root/'valid.zip','w') as archive:
    archive.writestr('index.html','<title>OHIF</title><script src="/ohif/app.bundle.abc.js"></script>')
    archive.writestr('app.bundle.abc.js','console.log("synthetic");')
    archive.writestr('app-config.js','window.config = { routerBasename: "/ohif/", defaultDataSourceName: "dicomjson" };')
with zipfile.ZipFile(root/'traversal.zip','w') as archive: archive.writestr('../escape', 'synthetic')
with zipfile.ZipFile(root/'symlink.zip','w') as archive:
    link = zipfile.ZipInfo('linked')
    link.create_system = 3
    link.external_attr = (stat.S_IFLNK | 0o777) << 16
    archive.writestr(link,'../escape')
PY
cd "$scratch"
export FIXTURE_ARCHIVE="$scratch/valid.zip" OHIF_BUNDLE_DIR=valid
OHIF_ARTIFACT_DIGEST="sha256:$(sha256sum "$FIXTURE_ARCHIVE" | cut -d' ' -f1)"
export OHIF_ARTIFACT_DIGEST
bash "$script_dir/download-ohif-bundle.sh"
export OHIF_BUNDLE_DIR=mismatch
OHIF_ARTIFACT_DIGEST="sha256:$(printf 'a%.0s' {1..64})"
if bash "$script_dir/download-ohif-bundle.sh" > "$scratch/output" 2>&1; then exit 1; fi
for fixture in traversal symlink; do
    export FIXTURE_ARCHIVE="$scratch/$fixture.zip" OHIF_BUNDLE_DIR="$fixture"
    OHIF_ARTIFACT_DIGEST="sha256:$(sha256sum "$FIXTURE_ARCHIVE" | cut -d' ' -f1)"
    if bash "$script_dir/download-ohif-bundle.sh" > "$scratch/output" 2>&1; then exit 1; fi
    [[ ! -e "$scratch/escape" ]]
done
echo 'Exact archive digest and extraction path/symlink fixtures passed.'
