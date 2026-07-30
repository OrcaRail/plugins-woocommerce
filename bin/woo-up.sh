#!/usr/bin/env bash
# Bring up WordPress + WooCommerce and bootstrap the OrcaRail gateway plugin.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR"

COMPOSE=(docker compose -f docker-compose.yml -p orcarail-woo)
ENV_FILE="${ORCARAIL_WOO_ENV_FILE:-$PLUGIN_DIR/.env}"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
  COMPOSE+=(--env-file "$ENV_FILE")
elif [[ -f "$PLUGIN_DIR/.env.example" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$PLUGIN_DIR/.env.example"
  set +a
  COMPOSE+=(--env-file "$PLUGIN_DIR/.env.example")
fi

WORDPRESS_URL="${WORDPRESS_URL:-http://127.0.0.1:${WORDPRESS_PORT:-8088}}"
WORDPRESS_TITLE="${WORDPRESS_TITLE:-OrcaRail Woo Real-Flow}"
WORDPRESS_ADMIN_USER="${WORDPRESS_ADMIN_USER:-admin}"
WORDPRESS_ADMIN_PASSWORD="${WORDPRESS_ADMIN_PASSWORD:-admin}"
WORDPRESS_ADMIN_EMAIL="${WORDPRESS_ADMIN_EMAIL:-admin@example.com}"
WOOCOMMERCE_VERSION="${WOOCOMMERCE_VERSION:-9.6.2}"

if [[ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]]; then
  echo "vendor/ missing — run: composer install" >&2
  exit 1
fi

echo "Starting WordPress stack (project orcarail-woo)..."
"${COMPOSE[@]}" up -d db wordpress

echo "Waiting for WordPress HTTP..."
for i in $(seq 1 90); do
  if curl -fsS -o /dev/null "$WORDPRESS_URL/" 2>/dev/null; then
    break
  fi
  if ((i == 90)); then
    echo "Timed out waiting for $WORDPRESS_URL" >&2
    "${COMPOSE[@]}" logs --tail=80 wordpress db >&2 || true
    exit 1
  fi
  sleep 2
done

wp() {
  "${COMPOSE[@]}" run --rm --no-deps wpcli wp "$@"
}

if ! wp core is-installed 2>/dev/null; then
  echo "Installing WordPress at $WORDPRESS_URL ..."
  wp core install \
    --url="$WORDPRESS_URL" \
    --title="$WORDPRESS_TITLE" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL" \
    --skip-email
fi

wp option update siteurl "$WORDPRESS_URL"
wp option update home "$WORDPRESS_URL"
wp rewrite structure '/%postname%/' --hard >/dev/null

if ! wp plugin is-installed woocommerce 2>/dev/null; then
  echo "Installing WooCommerce ${WOOCOMMERCE_VERSION}..."
  wp plugin install "woocommerce" --version="$WOOCOMMERCE_VERSION" --activate
else
  wp plugin activate woocommerce >/dev/null || true
fi

wp plugin activate orcarail-woocommerce >/dev/null

wp option update woocommerce_currency USD >/dev/null
wp option update woocommerce_store_address '1 Test St' >/dev/null || true
wp option update woocommerce_store_city 'Testville' >/dev/null || true
wp option update woocommerce_default_country 'US:CA' >/dev/null || true
wp option update woocommerce_store_postcode '94105' >/dev/null || true
wp option update woocommerce_onboarding_profile '{"skipped":true,"completed":true}' --format=json >/dev/null || true
wp option update woocommerce_task_list_hidden 'yes' >/dev/null 2>&1 || true
wp option update woocommerce_task_list_complete 'yes' >/dev/null 2>&1 || true
wp wc tool run install_pages --user=1 >/dev/null 2>&1 || true

PRODUCT_ID="$(
  wp eval '
    $products = wc_get_products(["limit" => 1, "status" => "publish", "return" => "ids"]);
    if ($products) { echo (int) $products[0]; return; }
    $p = new WC_Product_Simple();
    $p->set_name("OrcaRail Test Product");
    $p->set_regular_price("10.00");
    $p->set_status("publish");
    $p->set_catalog_visibility("visible");
    echo (int) $p->save();
  '
)"

echo "WordPress ready at $WORDPRESS_URL (product id=${PRODUCT_ID})"
echo "Admin: $WORDPRESS_ADMIN_USER / $WORDPRESS_ADMIN_PASSWORD"
