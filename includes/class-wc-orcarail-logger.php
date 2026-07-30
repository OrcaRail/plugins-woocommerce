<?php

declare(strict_types=1);

/**
 * Lightweight WooCommerce logger wrapper.
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail_Logger
{
    private const SOURCE = 'orcarail';

    public static function debug(string $message, bool $enabled = false): void
    {
        if (!$enabled || !function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->debug($message, ['source' => self::SOURCE]);
    }

    public static function info(string $message, bool $enabled = false): void
    {
        if (!$enabled || !function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->info($message, ['source' => self::SOURCE]);
    }

    public static function error(string $message): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->error($message, ['source' => self::SOURCE]);
    }
}
