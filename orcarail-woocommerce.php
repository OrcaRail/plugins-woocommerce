<?php
/**
 * Plugin Name: OrcaRail for WooCommerce
 * Plugin URI: https://github.com/OrcaRail/plugins-woocommerce
 * Description: Accept crypto payments on your WooCommerce store with OrcaRail hosted checkout.
 * Version: 1.0.0
 * Author: OrcaRail
 * Author URI: https://orcarail.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: orcarail-woocommerce
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 *
 * @package OrcaRail\WooCommerce
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORCARAIL_WC_VERSION', '1.0.0');
define('ORCARAIL_WC_PLUGIN_FILE', __FILE__);
define('ORCARAIL_WC_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));
define('ORCARAIL_WC_PLUGIN_URL', untrailingslashit(plugin_dir_url(__FILE__)));

/**
 * Load Composer autoloader when present (release ZIPs bundle vendor/).
 */
function orcarail_wc_autoload(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }

    $autoload = ORCARAIL_WC_PLUGIN_PATH . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        $loaded = false;
        return false;
    }

    require_once $autoload;
    $loaded = true;
    return true;
}

/**
 * Admin notice when WooCommerce is missing.
 */
function orcarail_wc_missing_woocommerce_notice(): void
{
    echo '<div class="error"><p>';
    echo esc_html__(
        'OrcaRail for WooCommerce requires WooCommerce to be installed and active.',
        'orcarail-woocommerce'
    );
    echo '</p></div>';
}

/**
 * Admin notice when Composer dependencies are missing.
 */
function orcarail_wc_missing_vendor_notice(): void
{
    echo '<div class="error"><p>';
    echo esc_html__(
        'OrcaRail for WooCommerce is missing its Composer dependencies. Install the official release ZIP or run composer install.',
        'orcarail-woocommerce'
    );
    echo '</p></div>';
}

/**
 * Bootstrap the plugin after plugins_loaded.
 */
function orcarail_wc_init(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'orcarail_wc_missing_woocommerce_notice');
        return;
    }

    if (!orcarail_wc_autoload()) {
        add_action('admin_notices', 'orcarail_wc_missing_vendor_notice');
        return;
    }

    require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail.php';
    WC_OrcaRail::instance();
}

add_action('plugins_loaded', 'orcarail_wc_init', 20);

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                ORCARAIL_WC_PLUGIN_FILE,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                ORCARAIL_WC_PLUGIN_FILE,
                true
            );
        }
    }
);

add_action(
    'woocommerce_blocks_loaded',
    static function (): void {
        if (!orcarail_wc_autoload()) {
            return;
        }
        if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            return;
        }
        require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-blocks-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ($registry): void {
                $registry->register(new WC_OrcaRail_Blocks_Support());
            }
        );
    }
);
