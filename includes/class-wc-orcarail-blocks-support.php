<?php

declare(strict_types=1);

/**
 * WooCommerce Checkout Blocks payment method registration.
 *
 * @package OrcaRail\WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'orcarail';

    private ?WC_Gateway_OrcaRail $gateway = null;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_orcarail_settings', []);
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($gateways['orcarail']) && $gateways['orcarail'] instanceof WC_Gateway_OrcaRail) {
            $this->gateway = $gateways['orcarail'];
        }
    }

    public function is_active(): bool
    {
        return $this->gateway instanceof WC_Gateway_OrcaRail && $this->gateway->is_available();
    }

    /**
     * @return array<int, string>
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'wc-orcarail-blocks';
        wp_register_script(
            $handle,
            ORCARAIL_WC_PLUGIN_URL . '/assets/js/blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            ORCARAIL_WC_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'orcarail-woocommerce', ORCARAIL_WC_PLUGIN_PATH . '/languages');
        }

        return [$handle];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $gateway = $this->gateway;
        return [
            'title' => $gateway ? $gateway->get_title() : __('Pay with crypto (OrcaRail)', 'orcarail-woocommerce'),
            'description' => $gateway ? $gateway->get_description() : '',
            'supports' => $gateway ? array_filter($gateway->supports) : ['products'],
            'icon' => ORCARAIL_WC_PLUGIN_URL . '/assets/images/orcarail.svg',
        ];
    }
}
