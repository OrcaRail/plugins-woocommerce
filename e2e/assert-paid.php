<?php

/**
 * WP-CLI eval-file: assert order is paid / reconciled after webhook or return.
 *
 * Env: ORCARAIL_E2E_ORDER_ID (required), ORCARAIL_E2E_INTENT_ID (optional)
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Must run via wp eval-file\n");
    exit(1);
}

$order_id = (int) (getenv('ORCARAIL_E2E_ORDER_ID') ?: 0);
$expect_intent = trim((string) (getenv('ORCARAIL_E2E_INTENT_ID') ?: ''));

if ($order_id <= 0) {
    fwrite(STDERR, "ORCARAIL_E2E_ORDER_ID required\n");
    exit(1);
}

$order = wc_get_order($order_id);
if (!$order instanceof WC_Order) {
    fwrite(STDERR, "Order $order_id not found\n");
    exit(1);
}

$intent = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
$status_meta = (string) $order->get_meta(WC_OrcaRail_API::META_STATUS, true);

if ($expect_intent !== '' && !hash_equals($expect_intent, $intent)) {
    fwrite(STDERR, "Intent mismatch: expected $expect_intent got $intent\n");
    exit(1);
}

if (!$order->is_paid() && !$order->has_status(['processing', 'completed'])) {
    fwrite(STDERR, sprintf(
        "Order %d not paid (status=%s meta_status=%s intent=%s)\n",
        $order_id,
        $order->get_status(),
        $status_meta,
        $intent
    ));
    exit(1);
}

if (strtolower($status_meta) !== 'completed') {
    fwrite(STDERR, "Expected _orcarail_status=completed, got {$status_meta}\n");
    exit(1);
}

echo wp_json_encode([
    'ok' => true,
    'order_id' => $order_id,
    'status' => $order->get_status(),
    'intent_id' => $intent,
    'meta_status' => $status_meta,
    'is_paid' => $order->is_paid(),
], JSON_UNESCAPED_SLASHES) . "\n";
