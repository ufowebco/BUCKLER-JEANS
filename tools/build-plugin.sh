#!/usr/bin/env bash
# Empaqueta buckler-core en dist/buckler-core-<versión>.zip (lista para Plugins → Añadir → Subir).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 -oP "Version:\s*\K[0-9.]+" buckler-core/buckler-core.php)
CONST=$(grep -m1 -oP "BK_VERSION', '\K[0-9.]+" buckler-core/buckler-core.php)
[ "$VERSION" = "$CONST" ] || { echo "Versión del header ($VERSION) y BK_VERSION ($CONST) no coinciden"; exit 1; }
for f in $(find buckler-core -name '*.php'); do php -l "$f" >/dev/null; done
for f in $(find buckler-core -name '*.js'); do node --check "$f"; done
mkdir -p dist
OUT="dist/buckler-core-$VERSION.zip"
rm -f "$OUT"
zip -rq "$OUT" buckler-core -x '*.DS_Store' '*/.gitkeep'
echo "$OUT ($(du -h "$OUT" | cut -f1))"
