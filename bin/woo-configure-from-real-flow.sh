#!/usr/bin/env bash
# Pull real-flow seed credentials, resolve token/network, point API key webhook at Woo,
# and write woocommerce_orcarail_settings.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ORCARAIL_ROOT="${ORCARAIL_ROOT:-$(cd "$PLUGIN_DIR/.." && pwd)}"
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
fi

WORDPRESS_URL="${WORDPRESS_URL:-http://127.0.0.1:${WORDPRESS_PORT:-8088}}"
API_HOST="${ORCARAIL_API_HOST:-http://127.0.0.1:3000}"
WEBHOOK_URL="${ORCARAIL_WEBHOOK_URL:-${WORDPRESS_URL}/?wc-api=wc_gateway_orcarail&orcarail=webhook}"
BASE_URL="${ORCARAIL_API_BASE_URL:-http://host.docker.internal:3000/api/v1}"

DEMO_ENV="${ORCARAIL_DEMO_ENV_LOCAL:-$ORCARAIL_ROOT/demo/.env.local}"
if [[ ! -f "$DEMO_ENV" ]]; then
  echo "Missing $DEMO_ENV — start real-flow backend so relational seed writes credentials." >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a
source "$DEMO_ENV"
set +a

if [[ -z "${ORCARAIL_API_KEY:-}" || -z "${ORCARAIL_API_SECRET:-}" ]]; then
  echo "ORCARAIL_API_KEY / ORCARAIL_API_SECRET missing in $DEMO_ENV" >&2
  exit 1
fi

echo "Configuring demo API key webhook → $WEBHOOK_URL"
CONFIG_JSON="$(
  curl -fsS -X POST "$API_HOST/api/__real-flow/configure-woo" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg u "$WEBHOOK_URL" '{webhookUrl:$u}')"
)"

TOKEN_ID="$(echo "$CONFIG_JSON" | jq -r '.tokenId')"
NETWORK_ID="$(echo "$CONFIG_JSON" | jq -r '.networkId')"
if [[ -z "$TOKEN_ID" || "$TOKEN_ID" == null || -z "$NETWORK_ID" || "$NETWORK_ID" == null ]]; then
  echo "configure-woo failed: $CONFIG_JSON" >&2
  exit 1
fi

wp() {
  "${COMPOSE[@]}" run --rm --no-deps wpcli wp "$@"
}

SETTINGS_JSON="$(
  jq -n \
    --arg key "$ORCARAIL_API_KEY" \
    --arg secret "$ORCARAIL_API_SECRET" \
    --arg whsec "$ORCARAIL_API_SECRET" \
    --arg token "$TOKEN_ID" \
    --arg network "$NETWORK_ID" \
    --arg base "$BASE_URL" \
    '{
      enabled: "yes",
      title: "Pay with crypto (OrcaRail)",
      description: "You will be redirected to OrcaRail to complete your crypto payment.",
      api_key: $key,
      api_secret: $secret,
      webhook_secret: $whsec,
      token_id: $token,
      network_id: $network,
      base_url: $base,
      logging: "yes"
    }'
)"

SETTINGS_B64="$(printf '%s' "$SETTINGS_JSON" | base64 -w0 2>/dev/null || printf '%s' "$SETTINGS_JSON" | base64)"
wp eval "update_option('woocommerce_orcarail_settings', json_decode(base64_decode('${SETTINGS_B64}'), true));"

# Persist resolved IDs for assert scripts / reruns.
{
  echo "ORCARAIL_API_KEY=${ORCARAIL_API_KEY}"
  echo "ORCARAIL_API_SECRET=${ORCARAIL_API_SECRET}"
  echo "ORCARAIL_TOKEN_ID=${TOKEN_ID}"
  echo "ORCARAIL_NETWORK_ID=${NETWORK_ID}"
  echo "ORCARAIL_API_BASE_URL=${BASE_URL}"
  echo "ORCARAIL_WEBHOOK_URL=${WEBHOOK_URL}"
  echo "WORDPRESS_URL=${WORDPRESS_URL}"
  echo "WORDPRESS_PORT=${WORDPRESS_PORT:-8088}"
} >"$PLUGIN_DIR/.env.wordpress.generated"

echo "Gateway configured (token=$TOKEN_ID network=$NETWORK_ID)"
echo "Wrote $PLUGIN_DIR/.env.wordpress.generated"
