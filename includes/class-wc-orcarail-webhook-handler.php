<?php

declare(strict_types=1);

/**
 * Verified OrcaRail webhook receiver.
 *
 * @package OrcaRail\WooCommerce
 */

use OrcaRail\Exception\SignatureVerificationException;
use OrcaRail\Webhook;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail_Webhook_Handler
{
    public static function init(): void
    {
        // Gateway registers woocommerce_api_wc_gateway_orcarail; nothing else needed.
    }

    public static function process_request(WC_Gateway_OrcaRail $gateway): void
    {
        $payload = file_get_contents('php://input');
        if (!is_string($payload) || $payload === '') {
            status_header(400);
            echo wp_json_encode(['error' => 'empty_body']);
            exit;
        }

        $signature = '';
        if (isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
            $signature = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']));
        }

        $secret = $gateway->get_webhook_secret();
        if ($secret === '') {
            WC_OrcaRail_Logger::error('Webhook secret not configured');
            status_header(500);
            echo wp_json_encode(['error' => 'webhook_not_configured']);
            exit;
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            WC_OrcaRail_Logger::error('Webhook signature failed: ' . $e->getMessage());
            status_header(400);
            echo wp_json_encode(['error' => 'invalid_signature']);
            exit;
        }

        $type = WC_OrcaRail_API::object_string($event, 'type') ?? '';
        $status = WC_OrcaRail_Order_Handler::status_from_event_type($type);
        if ($status === null) {
            WC_OrcaRail_Logger::debug('Ignoring webhook type: ' . $type, $gateway->is_logging_enabled());
            status_header(200);
            echo wp_json_encode(['received' => true, 'ignored' => true]);
            exit;
        }

        $object = $event->data->object ?? null;
        $intent_id = WC_OrcaRail_API::object_string(
            is_object($object) || is_array($object) ? $object : null,
            'id'
        );
        if ($intent_id === null) {
            status_header(400);
            echo wp_json_encode(['error' => 'missing_intent_id']);
            exit;
        }

        $object_status = WC_OrcaRail_API::object_string(
            is_object($object) || is_array($object) ? $object : null,
            'status'
        );
        if (is_string($object_status) && $object_status !== '') {
            $status = strtolower($object_status);
        }

        $order = self::resolve_order($object, $intent_id);
        if (!$order instanceof WC_Order) {
            WC_OrcaRail_Logger::error('No order for intent ' . $intent_id);
            status_header(404);
            echo wp_json_encode(['error' => 'order_not_found']);
            exit;
        }

        if ($order->get_payment_method() !== 'orcarail') {
            status_header(409);
            echo wp_json_encode(['error' => 'payment_method_mismatch']);
            exit;
        }

        $meta = null;
        if (is_object($object) && isset($object->metadata)) {
            $meta = $object->metadata;
        } elseif (is_array($object) && isset($object['metadata'])) {
            $meta = $object['metadata'];
        }
        if ($meta !== null && !self::metadata_matches_order($meta, $order)) {
            WC_OrcaRail_Logger::error(
                sprintf('Metadata mismatch for order %d intent %s', $order->get_id(), $intent_id)
            );
            status_header(409);
            echo wp_json_encode(['error' => 'metadata_mismatch']);
            exit;
        }

        WC_OrcaRail_Order_Handler::reconcile($order, $intent_id, $status, $gateway->is_logging_enabled());

        status_header(200);
        echo wp_json_encode(['received' => true]);
        exit;
    }

    /**
     * @param mixed $object
     */
    private static function resolve_order(mixed $object, string $intent_id): ?WC_Order
    {
        $order = WC_OrcaRail_API::find_order_by_intent_id($intent_id);
        if ($order instanceof WC_Order) {
            return $order;
        }

        $meta = null;
        if (is_object($object) && isset($object->metadata)) {
            $meta = $object->metadata;
        } elseif (is_array($object) && isset($object['metadata'])) {
            $meta = $object['metadata'];
        }

        $order_id = WC_OrcaRail_API::object_string(
            is_object($meta) || is_array($meta) ? $meta : null,
            'woocommerce_order_id'
        );
        if ($order_id === null) {
            return null;
        }

        $candidate = wc_get_order(absint($order_id));
        return $candidate instanceof WC_Order ? $candidate : null;
    }

    /**
     * @param mixed $meta
     */
    private static function metadata_matches_order(mixed $meta, WC_Order $order): bool
    {
        $order_id = WC_OrcaRail_API::object_string(
            is_object($meta) || is_array($meta) ? $meta : null,
            'woocommerce_order_id'
        );
        $order_key = WC_OrcaRail_API::object_string(
            is_object($meta) || is_array($meta) ? $meta : null,
            'woocommerce_order_key'
        );

        if ($order_id !== null && (string) $order->get_id() !== $order_id) {
            return false;
        }
        if ($order_key !== null && !hash_equals($order->get_order_key(), $order_key)) {
            return false;
        }

        return true;
    }
}
