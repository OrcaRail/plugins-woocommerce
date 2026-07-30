<?php

declare(strict_types=1);

/**
 * Uninstall cleanup for OrcaRail WooCommerce gateway.
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_orcarail_settings');

if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}
