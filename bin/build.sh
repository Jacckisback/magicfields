#!/usr/bin/env bash
# Builds the installable zip: dist/magicfields.zip
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/magicfields
cp -r magicfields.php uninstall.php readme.txt README.md README.fr.md LICENSE includes assets languages dist/magicfields/
(cd dist && zip -qr magicfields.zip magicfields && rm -rf magicfields)
echo "dist/magicfields.zip"
