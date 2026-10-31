#!/usr/bin/env bash
# Orchestrate real-flow backend+pay + Woo Docker + gateway smoke (local webhooks).
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
PAY_ORIGIN="${ORCARAIL_PAY_ORIGIN:-http://127.0.0.1:5174}"
WEBHOOK_URL="${ORCARAIL_WEBHOOK_URL:-${WORDPRESS_URL}/?wc-api=wc_gateway_orcarail&orcarail=webhook}"
KEEP_WOO="${ORCARAIL_KEEP_WOO:-0}"
LOG_DIR="${ORCARAIL_WOO_LOG_DIR:-$PLUGIN_DIR/.real-flow-logs}"
mkdir -p "$LOG_DIR"

BACKEND_PID=""
PAY_PID=""
STARTED_BACKEND=0
STARTED_PAY=0

cleanup() {
  local code=$?
  if [[ -n "$BACKEND_PID" ]] && kill -0 "$BACKEND_PID" 2>/dev/null; then
    kill -- "-$BACKEND_PID" 2>/dev/null || kill "$BACKEND_PID" 2>/dev/null || true
  fi
  if [[ -n "$PAY_PID" ]] && kill -0 "$PAY_PID" 2>/dev/null; then
    kill -- "-$PAY_PID" 2>/dev/null || kill "$PAY_PID" 2>/dev/null || true
  fi
  if [[ "$KEEP_WOO" != "1" ]]; then
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$code"
}
trap cleanup EXIT

wp() {
  "${COMPOSE[@]}" run --rm --no-deps wpcli wp "$@"
}

wait_http() {
  local url="$1"
  local label="$2"
  local max="${3:-120}"
  local i
  for i in $(seq 1 "$max"); do
    # Match scripts/run-real-flow-ui.sh: any HTTP response counts (401 on auth routes is OK).
    if curl -s -o /dev/null "$url" 2>/dev/null; then
      echo "OK $label ($url)"
      return 0
    fi
    sleep 1
  done
  echo "Timed out waiting for $label at $url" >&2
  return 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Required command not found: $1" >&2
    exit 1
  }
}

need_cmd docker
need_cmd curl
need_cmd jq
need_cmd php

if [[ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]]; then
  echo "Installing Composer dependencies..."
  (cd "$PLUGIN_DIR" && composer install)
fi

# shellcheck source=/dev/null
source "$ORCARAIL_ROOT/scripts/load-real-flow-env.sh"

# Signed webhooks require encryption key present at API key seed time.
export API_KEY_WEBHOOK_SECRET_ENCRYPTION_KEY="${API_KEY_WEBHOOK_SECRET_ENCRYPTION_KEY:-orcarail-woo-real-flow-dev-key}"
export ORCARAIL_SEED_WEBHOOK_URL="$WEBHOOK_URL"
export PAY_URL="${PAY_URL:-$PAY_ORIGIN}"
export REAL_FLOW_TEST_MODE=1

echo "Ensuring Redis + MailDev for real-flow..."
(
  cd "$ORCARAIL_ROOT/api"
  docker compose up -d redis maildev
)

if curl -s -o /dev/null "$API_HOST/api/v1/organizations" 2>/dev/null; then
  echo "Reusing existing API at $API_HOST"
else
  echo "Starting real-flow combined backend..."
  STARTED_BACKEND=1
  # New process group so cleanup can kill children.
  set -m
  ORCARAIL_NO_RTK=1 "$ORCARAIL_ROOT/scripts/start-real-flow-backend.sh" \
    >"$LOG_DIR/backend.log" 2>&1 &
  BACKEND_PID=$!
  set +m
  wait_http "$API_HOST/api/v1/organizations" "api" 180 \
    || {
      tail -n 80 "$LOG_DIR/backend.log" >&2 || true
      exit 1
    }
fi

if curl -s -o /dev/null "$PAY_ORIGIN/" 2>/dev/null; then
  echo "Reusing existing pay at $PAY_ORIGIN"
else
  echo "Starting pay real-flow..."
  STARTED_PAY=1
  set -m
  ORCARAIL_NO_RTK=1 "$ORCARAIL_ROOT/scripts/start-pay-real-flow.sh" \
    >"$LOG_DIR/pay.log" 2>&1 &
  PAY_PID=$!
  set +m
  wait_http "$PAY_ORIGIN/" "pay" 120 \
    || {
      tail -n 80 "$LOG_DIR/pay.log" >&2 || true
      exit 1
    }
fi

# Wait for seed credentials (written after backend bootstrap).
DEMO_ENV="$ORCARAIL_ROOT/demo/.env.sandbox.local"
for i in $(seq 1 60); do
  if [[ -f "$DEMO_ENV" ]] && grep -q '^ORCARAIL_API_KEY=' "$DEMO_ENV" 2>/dev/null; then
    break
  fi
  if ((i == 60)); then
    echo "Timed out waiting for $DEMO_ENV credentials" >&2
    exit 1
  fi
  sleep 1
done

bash "$PLUGIN_DIR/bin/woo-up.sh"
bash "$PLUGIN_DIR/bin/woo-configure-from-real-flow.sh"

# shellcheck disable=SC1090
if [[ -f "$PLUGIN_DIR/.env.wordpress.generated" ]]; then
  set -a
  source "$PLUGIN_DIR/.env.wordpress.generated"
  set +a
fi

echo "Creating Woo order + process_payment..."
CREATE_JSON="$(wp eval-file /var/www/html/wp-content/plugins/orcarail-woocommerce/e2e/create-payment.php)"
echo "$CREATE_JSON" | jq .

ORDER_ID="$(echo "$CREATE_JSON" | jq -r '.order_id')"
INTENT_ID="$(echo "$CREATE_JSON" | jq -r '.intent_id')"
PAY_URL_RESULT="$(echo "$CREATE_JSON" | jq -r '.pay_url')"
PAY_SLUG="$(echo "$CREATE_JSON" | jq -r '.pay_slug')"
RETURN_URL="$(echo "$CREATE_JSON" | jq -r '.return_url')"

case "$PAY_URL_RESULT" in
  http://127.0.0.1:5174*|http://localhost:5174*) ;;
  *)
    echo "pay_url is not the local pay origin: $PAY_URL_RESULT" >&2
    exit 1
    ;;
esac

# The payment is confirmed on-chain (scanner-style). OrcaRail keeps the payment
# intent processing until the store calls complete, so the order must stay unpaid
# until the plugin does that (on return, or from the hourly sweep).
confirm_payment() {
  local slug="$1"
  echo "Confirming the payment on-chain for slug=$slug ..."
  curl -fsS -X POST "$API_HOST/api/__real-flow/simulate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg s "$slug" '{action:"confirm-first-payment",FLOW_PAYMENT_LINK_SLUG:$s}')" | jq .
}

assert_paid() {
  local out
  out="$(
    "${COMPOSE[@]}" run --rm --no-deps \
      -e "ORCARAIL_E2E_ORDER_ID=$1" \
      -e "ORCARAIL_E2E_INTENT_ID=$2" \
      wpcli wp eval-file /var/www/html/wp-content/plugins/orcarail-woocommerce/e2e/assert-paid.php
  )"
  echo "$out" | jq .
}

assert_unpaid() {
  local paid
  paid="$(
    "${COMPOSE[@]}" run --rm --no-deps -e "ORCARAIL_E2E_ORDER_ID=$1" \
      wpcli wp eval 'echo wc_get_order((int) getenv("ORCARAIL_E2E_ORDER_ID"))->is_paid() ? "paid" : "unpaid";'
  )"
  if [[ "$paid" != *unpaid* ]]; then
    echo "Order $1 is paid before the store completed its payment intent" >&2
    exit 1
  fi
  echo "Order $1 is still unpaid (payment intent waits for complete)"
}

confirm_payment "$PAY_SLUG"
assert_unpaid "$ORDER_ID"

echo "Hitting return URL (the plugin completes the payment intent)..."
curl -fsS -o /dev/null -L --max-redirs 5 "$RETURN_URL" || true
assert_paid "$ORDER_ID" "$INTENT_ID"

echo "Hitting return URL again (idempotent reconcile)..."
curl -fsS -o /dev/null -L --max-redirs 5 "$RETURN_URL" || true
assert_paid "$ORDER_ID" "$INTENT_ID"

echo "Second order: the shopper never returns, the hourly sweep completes it..."
CREATE2_JSON="$(wp eval-file /var/www/html/wp-content/plugins/orcarail-woocommerce/e2e/create-payment.php)"
ORDER2_ID="$(echo "$CREATE2_JSON" | jq -r '.order_id')"
INTENT2_ID="$(echo "$CREATE2_JSON" | jq -r '.intent_id')"
PAY2_SLUG="$(echo "$CREATE2_JSON" | jq -r '.pay_slug')"
confirm_payment "$PAY2_SLUG"
assert_unpaid "$ORDER2_ID"
wp eval 'WC_OrcaRail::run_completion_sweep();'
assert_paid "$ORDER2_ID" "$INTENT2_ID"

echo ""
echo "Woo real-flow smoke passed."
echo "  return URL completed order=$ORDER_ID intent=$INTENT_ID"
echo "  hourly sweep completed order=$ORDER2_ID intent=$INTENT2_ID"
if [[ "$KEEP_WOO" == "1" ]]; then
  echo "  KEEP_WOO=1 — leaving WordPress stack up at $WORDPRESS_URL"
fi
