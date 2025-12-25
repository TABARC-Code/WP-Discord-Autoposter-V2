#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${ROOT}/dist"
mkdir -p "${OUT}"

MAIN_FILE="${ROOT}/wp-discord-announcer.php"
VERSION="$(php -r "preg_match('/Version:\s*([^\s]+)/', file_get_contents('${MAIN_FILE}'), $m); echo $m[1] ?? '0.0.0';")"
ZIP="${OUT}/WP-Discord-Announcer-${VERSION}.zip"

rm -f "${ZIP}"
cd "${ROOT}"

zip -r "${ZIP}" .       -x ".git/*"       -x ".github/*"       -x "scripts/*"       -x "dist/*"       -x "*.DS_Store"       -x "__MACOSX/*"

echo "Built: ${ZIP}"
