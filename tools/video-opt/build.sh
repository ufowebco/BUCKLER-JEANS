#!/usr/bin/env bash
# Genera buckler-core/assets/js/vendor/bk-video-opt.js (optimizador de video en el navegador).
# Requiere: npm i mediabunny@1.65.2 esbuild (en una carpeta temporal) y ejecutar desde ahí:
#   bash /ruta/al/repo/tools/video-opt/build.sh
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$REPO/buckler-core/assets/js/vendor/bk-video-opt.js"
npx esbuild "$REPO/tools/video-opt/entry.js" --bundle --minify --format=iife --target=es2020 --platform=browser --legal-comments=none --outfile=/tmp/bk-video-opt.js
{
	echo '/*! Buckler Core: optimizador de video en el navegador. Incluye Mediabunny 1.65.2 (c) Vanilagy y colaboradores,'
	echo ' *  licencia MPL-2.0 (https://mozilla.org/MPL/2.0/). Código fuente: https://github.com/Vanilagy/mediabunny'
	echo ' *  Archivo generado con tools/video-opt/build.sh: no editar a mano. */'
	cat /tmp/bk-video-opt.js
} > "$OUT"
echo "$OUT ($(du -h "$OUT" | cut -f1))"
