<?php

declare(strict_types=1);

/**
 * Main plugin bootstrap singleton.
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-logger.php';
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-api.php';
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-order-handler.php';
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-webhook-handler.php';
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-gateway-orcarail.php';

        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_filter('plugin_action_links_' . plugin_basename(ORCARAIL_WC_PLUGIN_FILE), [$this, 'plugin_links']);

        WC_OrcaRail_Webhook_Handler::init();
    }

    /**
     * @param array<int, string> $gateways
     * @return array<int, string>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = WC_Gateway_OrcaRail::class;
        return $gateways;
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function plugin_links(array $links): array
    {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=orcarail')),
            esc_html__('Settings', 'orcarail-woocommerce')
        );
        array_unshift($links, $settings);
        return $links;
    }
}
