#!/usr/bin/env bash
#
# Package the ChatGPT plugin for upload.
#
#   pnpm chatgpt-plugin                 writes build/phr-chatgpt-plugin.zip
#   pnpm chatgpt-plugin path/to/out.zip
#
# The plugin only points ChatGPT at the remote MCP endpoint; it contains no
# server code. Files sit at the archive root, where ChatGPT looks for
# plugin.json and mcp.json.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/integrations/chatgpt-plugin"
OUT="${1:-$ROOT/build/phr-chatgpt-plugin.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
# Sorted names and no extra attributes keep the archive's contents stable.
(cd "$SRC" && find . -type f ! -name '.DS_Store' | LC_ALL=C sort | zip -q -X "$OUT" -@)
echo "$OUT"
