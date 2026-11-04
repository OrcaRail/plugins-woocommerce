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

# Allowlist: only runtime files go into the release zip. Never copy the folder wholesale: it holds
# local secrets (.env.wordpress.generated), real-flow logs, e2e specs and dev config.
RUNTIME_PATHS=(orcarail-woocommerce.php uninstall.php readme.txt README.md LICENSE assets includes languages vendor)
SOURCES=()
for path in "${RUNTIME_PATHS[@]}"; do
  [[ -e "$path" ]] && SOURCES+=("$path")
done

rsync -a \
  --exclude='vendor/**/tests/' \
  --exclude='vendor/**/.github/' \
  --exclude='vendor/**/phpunit.xml.dist' \
  --exclude='vendor/**/phpstan.neon' \
  --exclude='vendor/**/composer.lock' \
  --exclude='vendor/**/.php-cs-fixer*' \
  "${SOURCES[@]}" "$STAGE/"

(
  cd "$DIST"
  zip -rq "$(basename "$ZIP")" orcarail-woocommerce
)

echo "Built $ZIP"
