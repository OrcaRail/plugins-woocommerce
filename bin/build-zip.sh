#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(grep -E '^\s*\*\s*Version:' orcarail-woocommerce.php | head -1 | sed -E 's/.*Version:\s*//')"
VERSION="${VERSION:-1.0.1}"

DIST="$ROOT/dist"
STAGE="$DIST/orcarail-woocommerce"
ZIP="$DIST/orcarail-woocommerce-${VERSION}.zip"

rm -rf "$DIST"
mkdir -p "$STAGE"

composer install --no-dev --optimize-autoloader --no-interaction

rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='.phpunit.cache/' \
  --exclude='.phpunit.result.cache' \
  --exclude='tests/' \
  --exclude='bin/' \
  --exclude='dist/' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpstan.neon' \
  --exclude='vendor/**/tests/' \
  --exclude='vendor/**/.github/' \
  --exclude='vendor/**/phpunit.xml.dist' \
  --exclude='vendor/**/phpstan.neon' \
  --exclude='vendor/**/composer.lock' \
  --exclude='vendor/**/.php-cs-fixer*' \
  ./ "$STAGE/"

(
  cd "$DIST"
  zip -rq "$(basename "$ZIP")" orcarail-woocommerce
)

echo "Built $ZIP"
