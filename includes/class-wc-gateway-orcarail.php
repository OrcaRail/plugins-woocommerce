<?php

declare(strict_types=1);

/**
 * OrcaRail hosted crypto payment gateway.
 *
 * @package OrcaRail\WooCommerce
 */

use OrcaRail\Exception\ApiException;
use OrcaRail\Exception\AuthenticationException;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_Gateway_OrcaRail extends WC_Payment_Gateway
{
    public bool $logging = false;

    public function __construct()
    {
        $this->id = 'orcarail';
        $this->method_title = __('OrcaRail', 'orcarail-woocommerce');
        $this->method_description = __(
            'Accept crypto payments via OrcaRail hosted checkout. One-time payments only; refunds and subscriptions are not supported.',
            'orcarail-woocommerce'
        );
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->icon = ORCARAIL_WC_PLUGIN_URL . '/assets/images/orcarail.svg';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title', __('Pay with crypto (OrcaRail)', 'orcarail-woocommerce'));
        $this->description = (string) $this->get_option(
            'description',
            __('You will be redirected to OrcaRail to complete your crypto payment.', 'orcarail-woocommerce')
        );
        $this->enabled = (string) $this->get_option('enabled', 'no');
        $this->logging = 'yes' === $this->get_option('logging', 'no');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'save_admin_options']
        );
        add_action('woocommerce_api_wc_gateway_orcarail', [$this, 'handle_api_request']);
        add_action('admin_notices', [$this, 'test_mode_notice']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function save_admin_options(): void
    {
        $this->process_admin_options();

        // Live fields need a live key; test-mode fields need a sandbox (ak_test_) key.
        if (!WC_OrcaRail_API::api_key_matches_mode((string) $this->get_option('api_key', ''), false)) {
            WC_Admin_Settings::add_error(__('The live API key is a sandbox key (ak_test_…). Use it in the test mode fields instead.', 'orcarail-woocommerce'));
        }
        if (!WC_OrcaRail_API::api_key_matches_mode((string) $this->get_option('test_api_key', ''), true)) {
            WC_Admin_Settings::add_error(__('The test API key must be a sandbox key (ak_test_…) from your OrcaRail sandbox organization.', 'orcarail-woocommerce'));
        }
    }

    public function is_test_mode(): bool
    {
        return 'yes' === $this->get_option('testmode', 'no');
    }

    /** Reads a credential/network setting for the current mode (test mode uses the test_* fields). */
    private function mode_option(string $key, string $default = ''): string
    {
        return (string) $this->get_option(WC_OrcaRail_API::mode_setting_key($key, $this->is_test_mode()), $default);
    }

    public function test_mode_notice(): void
    {
        if (!$this->is_test_mode() || 'yes' !== $this->enabled || !current_user_can('manage_woocommerce')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__(
            'OrcaRail is in test mode: checkout uses your sandbox organization on testnets and no real funds move.',
            'orcarail-woocommerce'
        ) . '</p></div>';
    }

    public function init_form_fields(): void
    {
        $webhook_url = home_url('/?wc-api=wc_gateway_orcarail&orcarail=webhook');

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'orcarail-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable OrcaRail', 'orcarail-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Payment method title shown at checkout.', 'orcarail-woocommerce'),
                'default' => __('Pay with crypto (OrcaRail)', 'orcarail-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'orcarail-woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment method description shown at checkout.', 'orcarail-woocommerce'),
                'default' => __('You will be redirected to OrcaRail to complete your crypto payment.', 'orcarail-woocommerce'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'orcarail-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable test mode (sandbox organization, testnets, no real funds)', 'orcarail-woocommerce'),
                'description' => __('Uses the test fields below: API keys (ak_test_…), webhook secret, token and network of your OrcaRail sandbox organization.', 'orcarail-woocommerce'),
                'default' => 'no',
            ],
            'api_key' => [
                'title' => __('API key', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('OrcaRail publishable/API key (pk_… / ak_…).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'api_secret' => [
                'title' => __('API secret', 'orcarail-woocommerce'),
                'type' => 'password',
                'description' => __('OrcaRail secret key (sk_…).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'webhook_secret' => [
                'title' => __('Webhook signing secret', 'orcarail-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Signing secret from your OrcaRail API key. Configure the webhook URL to: %s', 'orcarail-woocommerce'),
                    $webhook_url
                ),
                'default' => '',
            ],
            'token_id' => [
                'title' => __('Token ID', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Fixed OrcaRail token UUID used for every order.', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'network_id' => [
                'title' => __('Network ID', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Fixed OrcaRail network UUID used for every order.', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'test_api_key' => [
                'title' => __('Test API key', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Sandbox organization API key (ak_test_…).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'test_api_secret' => [
                'title' => __('Test API secret', 'orcarail-woocommerce'),
                'type' => 'password',
                'description' => __('Sandbox organization secret key (sk_test_…).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'test_webhook_secret' => [
                'title' => __('Test webhook signing secret', 'orcarail-woocommerce'),
                'type' => 'password',
                'description' => __('Signing secret from your sandbox API key (same webhook URL).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'test_token_id' => [
                'title' => __('Test token ID', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Testnet token UUID (e.g. USDC on Arbitrum Sepolia).', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'test_network_id' => [
                'title' => __('Test network ID', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Testnet network UUID.', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'base_url' => [
                'title' => __('API base URL (optional)', 'orcarail-woocommerce'),
                'type' => 'text',
                'description' => __('Leave blank for https://api.orcarail.com/api/v1', 'orcarail-woocommerce'),
                'default' => '',
            ],
            'logging' => [
                'title' => __('Debug log', 'orcarail-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'orcarail-woocommerce'),
                'default' => 'no',
            ],
        ];
    }

    public function is_available(): bool
    {
        if ('yes' !== $this->enabled) {
            return false;
        }

        foreach (['api_key', 'api_secret', 'token_id', 'network_id'] as $key) {
            if (trim($this->mode_option($key)) === '') {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'orcarail-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $existing_url = (string) $order->get_meta(WC_OrcaRail_API::META_PAY_URL, true);
        $existing_intent = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
        if ($existing_url !== '' && $existing_intent !== '' && !$order->is_paid()) {
            WC_OrcaRail_Logger::debug(
                sprintf('Reusing intent %s for order %d', $existing_intent, $order->get_id()),
                $this->logging
            );
            return [
                'result' => 'success',
                'redirect' => $existing_url,
            ];
        }

        try {
            $client = WC_OrcaRail_API::client([
                'api_key' => $this->mode_option('api_key'),
                'api_secret' => $this->mode_option('api_secret'),
                'base_url' => (string) $this->get_option('base_url', ''),
            ]);

            $return_url = $this->get_return_callback_url($order);
            $cancel_url = $order->get_cancel_order_url_raw();
            $params = WC_OrcaRail_API::build_intent_params(
                $order,
                [
                    'token_id' => trim($this->mode_option('token_id')),
                    'network_id' => trim($this->mode_option('network_id')),
                ],
                $return_url,
                $cancel_url
            );

            $intent = $client->paymentIntents->create($params['create']);
            $intent_id = WC_OrcaRail_API::object_string($intent, 'id');
            $client_secret = WC_OrcaRail_API::object_string($intent, 'client_secret');
            if ($intent_id === null || $client_secret === null) {
                throw new RuntimeException('Payment Intent response missing id or client_secret.');
            }

            $confirmed = $client->paymentIntents->confirm($intent_id, [
                'client_secret' => $client_secret,
                'return_url' => $params['confirm']['return_url'],
            ]);

            $pay_url = WC_OrcaRail_API::object_string($confirmed, 'pay_url');
            if ($pay_url === null) {
                $link = $confirmed->payment_link ?? null;
                $pay_url = WC_OrcaRail_API::object_string(
                    is_object($link) || is_array($link) ? $link : null,
                    'link'
                );
            }
            if ($pay_url === null || $pay_url === '') {
                throw new RuntimeException('Payment Intent confirm response missing pay_url.');
            }

            $order->update_meta_data(WC_OrcaRail_API::META_INTENT_ID, $intent_id);
            $order->update_meta_data(WC_OrcaRail_API::META_PAY_URL, $pay_url);
            $order->update_meta_data(WC_OrcaRail_API::META_CLIENT_SECRET, $client_secret);
            $status = WC_OrcaRail_API::object_string($confirmed, 'status') ?? 'requires_payment_method';
            $order->update_meta_data(WC_OrcaRail_API::META_STATUS, $status);
            $order->update_status(
                'pending',
                sprintf(
                    /* translators: %s: OrcaRail payment intent id */
                    __('Awaiting OrcaRail crypto payment (%s).', 'orcarail-woocommerce'),
                    $intent_id
                )
            );
            $order->save();

            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $pay_url,
            ];
        } catch (AuthenticationException $e) {
            WC_OrcaRail_Logger::error('Auth error: ' . $e->getMessage());
            wc_add_notice(__('OrcaRail authentication failed. Check API credentials.', 'orcarail-woocommerce'), 'error');
            return ['result' => 'failure'];
        } catch (ApiException $e) {
            WC_OrcaRail_Logger::error('API error: ' . $e->getMessage());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('OrcaRail payment error: %s', 'orcarail-woocommerce'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'failure'];
        } catch (Throwable $e) {
            WC_OrcaRail_Logger::error('Payment error: ' . $e->getMessage());
            wc_add_notice(__('Unable to start OrcaRail payment. Please try again.', 'orcarail-woocommerce'), 'error');
            return ['result' => 'failure'];
        }
    }

    public function handle_api_request(): void
    {
        $action = isset($_GET['orcarail']) ? sanitize_text_field(wp_unslash((string) $_GET['orcarail'])) : 'webhook';

        if ($action === 'return') {
            $this->handle_return();
            return;
        }

        WC_OrcaRail_Webhook_Handler::process_request($this);
    }

    public function thankyou_page(int|string $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        $intent = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
        if ($intent === '') {
            return;
        }
        echo '<p>' . esc_html(
            sprintf(
                /* translators: %s: payment intent id */
                __('OrcaRail payment reference: %s', 'orcarail-woocommerce'),
                $intent
            )
        ) . '</p>';
    }

    public function get_webhook_secret(): string
    {
        return trim($this->mode_option('webhook_secret'));
    }

    public function is_logging_enabled(): bool
    {
        return $this->logging;
    }

    /**
     * @return array{api_key: string, api_secret: string, base_url: string}
     */
    public function get_api_settings(): array
    {
        return [
            'api_key' => $this->mode_option('api_key'),
            'api_secret' => $this->mode_option('api_secret'),
            'base_url' => (string) $this->get_option('base_url', ''),
        ];
    }

    private function get_return_callback_url(WC_Order $order): string
    {
        return add_query_arg(
            [
                'wc-api' => 'wc_gateway_orcarail',
                'orcarail' => 'return',
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ],
            home_url('/')
        );
    }

    private function handle_return(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order || !hash_equals($order->get_order_key(), $key)) {
            wp_die(esc_html__('Invalid return request.', 'orcarail-woocommerce'), 400);
        }

        if ($order->get_payment_method() !== $this->id) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        $intent_id = (string) $order->get_meta(WC_OrcaRail_API::META_INTENT_ID, true);
        if ($intent_id === '') {
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        try {
            $client = WC_OrcaRail_API::client($this->get_api_settings());
            $intent = $client->paymentIntents->retrieve($intent_id);
            $status = WC_OrcaRail_API::object_string($intent, 'status') ?? '';
            WC_OrcaRail_Order_Handler::reconcile($order, $intent_id, $status, $this->logging);
        } catch (Throwable $e) {
            WC_OrcaRail_Logger::error('Return reconcile failed: ' . $e->getMessage());
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && $order->is_paid()) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        if ($order instanceof WC_Order && $order->has_status(['cancelled', 'failed'])) {
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        // Still pending/processing — thank-you page is fine; webhook will finish.
        wp_safe_redirect($this->get_return_url($order instanceof WC_Order ? $order : null));
        exit;
    }
}
