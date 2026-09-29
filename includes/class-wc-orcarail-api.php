<?php

declare(strict_types=1);

/**
 * OrcaRail SDK client factory and helpers.
 *
 * @package OrcaRail\WooCommerce
 */

use OrcaRail\OrcaRailClient;
use OrcaRail\OrcaRailObject;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail_API
{
    public const META_INTENT_ID = '_orcarail_payment_intent_id';
    public const META_PAY_URL = '_orcarail_pay_url';
    public const META_CLIENT_SECRET = '_orcarail_client_secret';
    public const META_STATUS = '_orcarail_status';

    /** Settings that have a separate test-mode (sandbox organization) value. */
    public const MODE_SETTINGS = ['api_key', 'api_secret', 'webhook_secret', 'token_id', 'network_id'];

    /**
     * Option name to read for a setting in the current mode: test mode uses the
     * sandbox organization's `test_*` values, live mode the plain ones.
     */
    public static function mode_setting_key(string $key, bool $testmode): string
    {
        return $testmode && in_array($key, self::MODE_SETTINGS, true) ? 'test_' . $key : $key;
    }

    /**
     * Sandbox organizations issue `ak_test_` keys, live organizations `ak_live_`.
     * An empty key is not a mismatch (it is reported as missing elsewhere).
     */
    public static function api_key_matches_mode(string $api_key, bool $testmode): bool
    {
        $api_key = trim($api_key);
        if ($api_key === '') {
            return true;
        }

        return $testmode ? str_starts_with($api_key, 'ak_test_') : !str_starts_with($api_key, 'ak_test_');
    }

    /**
     * Whether a verified webhook event belongs to the gateway's mode. Events without
     * `livemode` (older API versions) are accepted.
     */
    public static function event_matches_mode(mixed $event, bool $testmode): bool
    {
        $livemode = is_object($event) ? ($event->livemode ?? null) : (is_array($event) ? ($event['livemode'] ?? null) : null);
        if (!is_bool($livemode)) {
            return true;
        }

        return $livemode === !$testmode;
    }

    /**
     * @param array{api_key?: string, api_secret?: string, base_url?: string} $settings
     */
    public static function client(array $settings): OrcaRailClient
    {
        $config = [
            'api_key' => (string) ($settings['api_key'] ?? ''),
            'api_secret' => (string) ($settings['api_secret'] ?? ''),
        ];
        $base = trim((string) ($settings['base_url'] ?? ''));
        if ($base !== '') {
            $config['base_url'] = $base;
        }

        return new OrcaRailClient($config);
    }

    /**
     * Format WooCommerce order total for the OrcaRail amount string.
     */
    public static function format_amount(WC_Order $order): string
    {
        return number_format((float) $order->get_total(), 2, '.', '');
    }

    /**
     * Build create + confirm payload pieces for a Woo order.
     *
     * @param array{token_id: string, network_id: string} $asset
     * @return array{create: array<string, mixed>, confirm: array<string, mixed>}
     */
    public static function build_intent_params(WC_Order $order, array $asset, string $return_url, string $cancel_url): array
    {
        $create = [
            'amount' => self::format_amount($order),
            'currency' => strtolower($order->get_currency()),
            'tokenId' => $asset['token_id'],
            'networkId' => $asset['network_id'],
            'payment_method_types' => ['crypto'],
            'return_url' => $return_url,
            'cancel_url' => $cancel_url,
            'description' => sprintf(
                /* translators: %s: order number */
                __('WooCommerce order %s', 'orcarail-woocommerce'),
                $order->get_order_number()
            ),
            'metadata' => [
                'woocommerce_order_id' => (string) $order->get_id(),
                'woocommerce_order_key' => $order->get_order_key(),
                'woocommerce_order_number' => (string) $order->get_order_number(),
            ],
        ];

        $email = $order->get_billing_email();
        if ($email !== '') {
            $create['payerEmail'] = $email;
        }

        return [
            'create' => $create,
            'confirm' => [
                'return_url' => $return_url,
            ],
        ];
    }

    public static function object_string(OrcaRailObject|array|null $object, string $key): ?string
    {
        if ($object === null) {
            return null;
        }
        if (is_array($object)) {
            $value = $object[$key] ?? null;
            return is_string($value) && $value !== '' ? $value : null;
        }
        $value = $object->{$key} ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function find_order_by_intent_id(string $intent_id): ?WC_Order
    {
        $intent_id = trim($intent_id);
        if ($intent_id === '') {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => self::META_INTENT_ID,
            'meta_value' => $intent_id,
            'return' => 'objects',
        ]);

        if ($orders === [] || !isset($orders[0]) || !$orders[0] instanceof WC_Order) {
            return null;
        }

        return $orders[0];
    }
}
