<?php

/**
 * WP-CLI eval-file: create unpaid order, process_payment, print JSON for the shell harness.
 *
 * Usage: wp eval-file e2e/create-payment.php
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Must run via wp eval-file\n");
    exit(1);
}

$products = wc_get_products([
    'limit' => 1,
    'status' => 'publish',
    'return' => 'ids',
]);
if ($products === []) {
    fwrite(STDERR, "No publishable product found\n");
    exit(1);
}

$order = wc_create_order();
$order->add_product(wc_get_product((int) $products[0]), 1);
$order->set_billing_email('woo-real-flow@example.com');
$order->set_billing_first_name('Woo');
$order->set_billing_last_name('RealFlow');
$order->set_payment_method('orcarail');
$order->set_payment_method_title('OrcaRail');
$order->set_status('pending');
$order->calculate_totals();
$order->save();

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway = $gateways['orcarail'] ?? null;
if (!$gateway instanceof WC_Gateway_OrcaRail) {
    fwrite(STDERR, "OrcaRail gateway not available\n");
    exit(1);
}

if (!$gateway->is_available()) {
    fwrite(STDERR, "OrcaRail gateway is_available()=false (check settings)\n");
    exit(1);
}

$result = $gateway->process_payment($order->get_id());
if (($result['result'] ?? '') !== 'success' || empty($result['redirect'])) {
    $notices = function_exists('wc_get_notices') ? wp_json_encode(wc_get_notices()) : '';
    fwrite(STDERR, 'process_payment failed: ' . wp_json_encode($result) . " notices=$notices\n");
    exit(1);
}

$order = wc_get_order($order->get_id());
if (!$order instanceof WC_Order) {
    fwrite(STDERR, "Order disappeared after process_payment\n");
    exit(1);
}

$reuse = $gateway->process_payment($order->get_id());
if (($reuse['redirect'] ?? '') !== ($result['redirect'] ?? '')) {
    fwrite(STDERR, "Intent reuse mismatch\n");
    exit(1);
}

$pay_url = (string) $result['redirect'];
$intent_id = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
$stored_pay = (string) $order->get_meta(WC_OrcaRail_API::META_PAY_URL, true);
$client_secret = (string) $order->get_meta(WC_OrcaRail_API::META_CLIENT_SECRET, true);

if ($intent_id === '' || $stored_pay === '' || $client_secret === '') {
    fwrite(STDERR, "Missing OrcaRail order meta after process_payment\n");
    exit(1);
}

// Hosted redirect flow: order stays unpaid until webhook/return.
if ($order->is_paid()) {
    fwrite(STDERR, "Order unexpectedly paid before shopper payment\n");
    exit(1);
}

// WP-CLI process_payment (outside checkout) can leave status=failed; force pending for unpaid hosted flow.
if ($order->has_status('failed')) {
    $order->update_status('pending', 'Awaiting OrcaRail crypto payment.');
    $order = wc_get_order($order->get_id());
}

$path = parse_url($pay_url, PHP_URL_PATH);
$slug = is_string($path) ? trim($path, '/') : '';
if ($slug === '' || str_contains($slug, '/')) {
    fwrite(STDERR, "Could not derive pay slug from $pay_url\n");
    exit(1);
}

$return_url = add_query_arg(
    [
        'wc-api' => 'wc_gateway_orcarail',
        'orcarail' => 'return',
        'order_id' => $order->get_id(),
        'key' => $order->get_order_key(),
    ],
    home_url('/')
);

echo wp_json_encode([
    'ok' => true,
    'order_id' => $order->get_id(),
    'order_key' => $order->get_order_key(),
    'order_number' => $order->get_order_number(),
    'intent_id' => $intent_id,
    'pay_url' => $pay_url,
    'pay_slug' => $slug,
    'return_url' => $return_url,
    'status' => $order->get_status(),
], JSON_UNESCAPED_SLASHES) . "\n";
