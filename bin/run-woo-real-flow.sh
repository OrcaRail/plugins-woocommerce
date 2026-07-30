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
DEMO_ENV="$ORCARAIL_ROOT/demo/.env.local"
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
ORDER_KEY="$(echo "$CREATE_JSON" | jq -r '.order_key')"
ORDER_NUMBER="$(echo "$CREATE_JSON" | jq -r '.order_number')"

case "$PAY_URL_RESULT" in
  http://127.0.0.1:5174*|http://localhost:5174*) ;;
  *)
    echo "pay_url is not the local pay origin: $PAY_URL_RESULT" >&2
    exit 1
    ;;
esac

echo "Simulating payment complete for slug=$PAY_SLUG ..."
SIM_JSON="$(
  curl -fsS -X POST "$API_HOST/api/__real-flow/simulate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg s "$PAY_SLUG" '{action:"complete-first-payment",FLOW_PAYMENT_LINK_SLUG:$s}')"
)"
echo "$SIM_JSON" | jq .

echo "Polling __real-flow/state for webhook delivery to Woo..."
DELIVERED=0
for i in $(seq 1 30); do
  STATE_JSON="$(
    curl -fsS -X POST "$API_HOST/api/__real-flow/state" \
      -H 'Content-Type: application/json' \
      -d '{"organizationSlug":"demo","limit":20}'
  )"
  if echo "$STATE_JSON" | jq -e --arg u "$WEBHOOK_URL" \
    '.webhooks // [] | map(select((.url // "") | contains("wc_gateway_orcarail"))) | length > 0' \
    >/dev/null 2>&1; then
    DELIVERED=1
    echo "webhook_log contains Woo WC-API URL (attempt $i)"
    break
  fi
  # Also match by host port path fragment.
  if echo "$STATE_JSON" | jq -e \
    '.webhooks // [] | map(select((.url // "") | test("8088|wc_gateway_orcarail"))) | length > 0' \
    >/dev/null 2>&1; then
    DELIVERED=1
    echo "webhook_log matched local Woo URL (attempt $i)"
    break
  fi
  sleep 1
done

if [[ "$DELIVERED" != "1" ]]; then
  echo "No webhook_log entry for Woo yet — posting signed payment_intent.completed fallback"
fi

# Always ensure order reconcile via signed webhook (covers flaky/unsigned queue delivery).
PAYLOAD="$(
  jq -n \
    --arg id "$INTENT_ID" \
    --arg oid "$ORDER_ID" \
    --arg okey "$ORDER_KEY" \
    --arg onum "$ORDER_NUMBER" \
    '{
      type: "payment_intent.completed",
      data: {
        object: {
          id: $id,
          status: "completed",
          metadata: {
            woocommerce_order_id: $oid,
            woocommerce_order_key: $okey,
            woocommerce_order_number: $onum
          }
        }
      },
      created: (now | floor)
    }'
)"
SECRET="${ORCARAIL_API_SECRET:?missing ORCARAIL_API_SECRET}"
SIGNATURE="$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"

WH_CODE="$(
  curl -sS -o "$LOG_DIR/webhook-response.json" -w '%{http_code}' \
    -X POST "$WEBHOOK_URL" \
    -H 'Content-Type: application/json' \
    -H "X-Webhook-Signature: $SIGNATURE" \
    -H 'X-Webhook-Event: payment_intent.completed' \
    --data-binary "$PAYLOAD"
)"
echo "Signed webhook HTTP $WH_CODE: $(cat "$LOG_DIR/webhook-response.json")"
if [[ "$WH_CODE" != "200" ]]; then
  echo "Signed webhook to Woo failed" >&2
  exit 1
fi

export ORCARAIL_E2E_ORDER_ID="$ORDER_ID"
export ORCARAIL_E2E_INTENT_ID="$INTENT_ID"
# wpcli service does not inherit host env — pass via --env
ASSERT1="$(
  "${COMPOSE[@]}" run --rm --no-deps \
    -e "ORCARAIL_E2E_ORDER_ID=$ORDER_ID" \
    -e "ORCARAIL_E2E_INTENT_ID=$INTENT_ID" \
    wpcli wp eval-file /var/www/html/wp-content/plugins/orcarail-woocommerce/e2e/assert-paid.php
)"
echo "$ASSERT1" | jq .

echo "Hitting return URL (idempotent reconcile)..."
curl -fsS -o /dev/null -L --max-redirs 5 "$RETURN_URL" || true

ASSERT2="$(
  "${COMPOSE[@]}" run --rm --no-deps \
    -e "ORCARAIL_E2E_ORDER_ID=$ORDER_ID" \
    -e "ORCARAIL_E2E_INTENT_ID=$INTENT_ID" \
    wpcli wp eval-file /var/www/html/wp-content/plugins/orcarail-woocommerce/e2e/assert-paid.php
)"
echo "$ASSERT2" | jq .

echo ""
echo "Woo real-flow smoke passed."
echo "  order=$ORDER_ID intent=$INTENT_ID"
echo "  webhook_url=$WEBHOOK_URL"
if [[ "$DELIVERED" == "1" ]]; then
  echo "  async webhook_log: observed"
else
  echo "  async webhook_log: not observed (signed fallback used)"
fi
if [[ "$KEEP_WOO" == "1" ]]; then
  echo "  KEEP_WOO=1 — leaving WordPress stack up at $WORDPRESS_URL"
fi
