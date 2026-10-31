<?php

declare(strict_types=1);

/**
 * Idempotent order reconciliation against OrcaRail payment intent status.
 *
 * @package OrcaRail\WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_OrcaRail_Order_Handler
{
    /**
     * Apply an OrcaRail payment-intent status to a WooCommerce order.
     *
     * @return bool True when the order was changed (or already reconciled).
     */
    public static function reconcile(WC_Order $order, string $intent_id, string $status, bool $logging = false): bool
    {
        $intent_id = trim($intent_id);
        $status = strtolower(trim($status));
        if ($intent_id === '' || $status === '') {
            return false;
        }

        $stored = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
        if ($stored !== '' && !hash_equals($stored, $intent_id)) {
            WC_OrcaRail_Logger::error(
                sprintf(
                    'Intent mismatch for order %d: stored=%s incoming=%s',
                    $order->get_id(),
                    $stored,
                    $intent_id
                )
            );
            return false;
        }

        if ($stored === '') {
            $order->update_meta_data(WC_OrcaRail_API::META_INTENT_ID, $intent_id);
        }

        $previous = (string) $order->get_meta(WC_OrcaRail_API::META_STATUS, true);
        if ($previous === $status && self::is_terminal_success($order) && $status === 'completed') {
            WC_OrcaRail_Logger::debug(
                sprintf('Order %d already completed for intent %s', $order->get_id(), $intent_id),
                $logging
            );
            return true;
        }

        $order->update_meta_data(WC_OrcaRail_API::META_STATUS, $status);

        switch ($status) {
            case 'completed':
                if (!$order->is_paid()) {
                    $order->payment_complete($intent_id);
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: OrcaRail payment intent id */
                            __('OrcaRail payment completed (%s).', 'orcarail-woocommerce'),
                            $intent_id
                        )
                    );
                }
                break;

            case 'processing':
                if (!$order->is_paid() && !$order->has_status(['processing', 'completed', 'on-hold'])) {
                    $order->update_status(
                        'on-hold',
                        sprintf(
                            /* translators: %s: OrcaRail payment intent id */
                            __('OrcaRail payment processing (%s).', 'orcarail-woocommerce'),
                            $intent_id
                        )
                    );
                } else {
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: OrcaRail payment intent id */
                            __('OrcaRail payment still processing (%s).', 'orcarail-woocommerce'),
                            $intent_id
                        )
                    );
                }
                break;

            case 'canceled':
            case 'cancelled':
                if (!$order->is_paid() && !$order->has_status(['cancelled', 'refunded', 'failed'])) {
                    $order->update_status(
                        'cancelled',
                        sprintf(
                            /* translators: %s: OrcaRail payment intent id */
                            __('OrcaRail payment canceled (%s).', 'orcarail-woocommerce'),
                            $intent_id
                        )
                    );
                }
                break;

            case 'requires_payment_method':
                if (!$order->is_paid() && !$order->has_status(['failed', 'cancelled', 'refunded'])) {
                    $order->update_status(
                        'failed',
                        sprintf(
                            /* translators: %s: OrcaRail payment intent id */
                            __('OrcaRail payment requires a new payment method (%s).', 'orcarail-woocommerce'),
                            $intent_id
                        )
                    );
                }
                break;

            default:
                WC_OrcaRail_Logger::debug(
                    sprintf('Ignoring status "%s" for order %d', $status, $order->get_id()),
                    $logging
                );
                break;
        }

        $order->save();
        return true;
    }

    /**
     * Read the intent, finish it when it is processing, and apply the result.
     *
     * OrcaRail keeps a paid intent `processing` until the merchant calls
     * `complete` (until then the payment is not final and never withdrawn).
     * `complete` completes the intent once the payment is confirmed on-chain and
     * leaves it `processing` otherwise, so calling it again later is safe.
     *
     * @param callable(string): (\OrcaRail\OrcaRailObject|array<string, mixed>) $retrieve Loads the intent.
     * @param callable(string): (\OrcaRail\OrcaRailObject|array<string, mixed>) $complete Completes it.
     * @param bool $only_paid Leave the order alone unless the intent ends completed (the background
     *                        sweep only finishes paid orders; it never fails or holds others).
     */
    public static function settle(
        WC_Order $order,
        string $intent_id,
        callable $retrieve,
        callable $complete,
        bool $logging = false,
        bool $only_paid = false
    ): bool {
        $status = WC_OrcaRail_API::object_string($retrieve($intent_id), 'status') ?? '';
        if ($status === 'processing') {
            $status = WC_OrcaRail_API::object_string($complete($intent_id), 'status') ?? $status;
        }
        if ($only_paid && $status !== 'completed') {
            return false;
        }

        return self::reconcile($order, $intent_id, $status, $logging);
    }

    /**
     * Map a webhook event type to an intent status string.
     */
    public static function status_from_event_type(string $event_type): ?string
    {
        return match ($event_type) {
            'payment_intent.completed' => 'completed',
            'payment_intent.processing' => 'processing',
            'payment_intent.canceled' => 'canceled',
            'payment_intent.requires_payment_method' => 'requires_payment_method',
            'payment_intent.requires_confirmation' => 'requires_confirmation',
            default => null,
        };
    }

    private static function is_terminal_success(WC_Order $order): bool
    {
        return $order->is_paid() || $order->has_status(['processing', 'completed']);
    }
}
