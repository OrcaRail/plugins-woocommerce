<?php

declare(strict_types=1);

namespace {
    // Minimal WooCommerce/WordPress surface for loading the gateway in unit tests.
    if (!class_exists('WC_Payment_Gateway')) {
        class WC_Payment_Gateway
        {
            public string $id = '';
            /** @var array<string, mixed> */
            public array $settings = [];
            public string $title = '';
            public string $description = '';
            public string $enabled = 'no';

            public function init_form_fields(): void
            {
            }

            public function init_settings(): void
            {
            }

            public function get_option(string $key, mixed $default = ''): mixed
            {
                return $this->settings[$key] ?? $default;
            }

            public function is_available(): bool
            {
                return true;
            }
        }
    }
    if (!function_exists('add_action')) {
        function add_action(string $hook, mixed $callback): void
        {
        }
    }
    if (!function_exists('wc_add_notice')) {
        function wc_add_notice(string $message, string $type = 'success'): void
        {
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://shop.example' . $path;
        }
    }
    if (!function_exists('wc_get_order')) {
        function wc_get_order(mixed $id): mixed
        {
            return $GLOBALS['orcarail_test_orders'][(int) $id] ?? false;
        }
    }
}

namespace OrcaRail\WooCommerce\Tests {
    use PHPUnit\Framework\TestCase;

    /**
     * plan/security 2026-11-04: WOO-1 (paying an order with an intent for another amount) and
     * WOO-2 (release zip packaging local secrets).
     */
    final class SecurityAuditTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__) . '/includes/class-wc-gateway-orcarail.php';
        }

        /** @param array<string, mixed> $intent */
        private function settle(\WC_Order $order, array $intent): bool
        {
            return \WC_OrcaRail_Order_Handler::settle(
                $order,
                'pi_1',
                static fn () => $intent,
                static fn () => $intent,
            );
        }

        // WOO-1: settlement trusted the status alone; a paid intent for a different amount or
        // currency completed the order.
        public function testWoo1SettleDoesNotCompleteAnOrderPaidWithALowerAmount(): void
        {
            $order = new \WC_Order(99, '2000.00', 'USD', 'wc_order_abc');

            $this->settle($order, ['id' => 'pi_1', 'status' => 'completed', 'amount' => '5.00', 'currency' => 'usd']);

            self::assertFalse($order->payment_complete_called);
        }

        public function testWoo1SettleDoesNotCompleteAnOrderPaidInAnotherCurrency(): void
        {
            $order = new \WC_Order(99, '2000.00', 'USD', 'wc_order_abc');

            $this->settle($order, ['id' => 'pi_1', 'status' => 'completed', 'amount' => '2000.00', 'currency' => 'eur']);

            self::assertFalse($order->payment_complete_called);
        }

        public function testSettleStillCompletesAMatchingIntent(): void
        {
            $order = new \WC_Order(99, '2000.00', 'USD', 'wc_order_abc');

            $this->settle($order, ['id' => 'pi_1', 'status' => 'completed', 'amount' => '2000.00', 'currency' => 'usd']);

            self::assertTrue($order->payment_complete_called);
        }

        // WOO-1: WooCommerce reuses a pending order when the cart changes, so the order total can
        // grow after the intent was created. Reusing the stored pay URL then pays the old amount.
        public function testWoo1DoesNotReuseAPayUrlCreatedForAnotherTotal(): void
        {
            $order = new \WC_Order(77, '2000.00', 'USD', 'wc_order_reused');
            $order->set_payment_method('orcarail');
            $order->update_meta_data(\WC_OrcaRail_API::META_INTENT_ID, 'pi_old');
            $order->update_meta_data(\WC_OrcaRail_API::META_PAY_URL, 'https://pay.orcarail.com/old-5-dollar-intent');
            $GLOBALS['orcarail_test_orders'] = [77 => $order];

            $gateway = new \WC_Gateway_OrcaRail();
            try {
                $result = $gateway->process_payment(77);
            } catch (\Throwable) {
                $result = ['result' => 'failure'];
            }

            self::assertNotSame('https://pay.orcarail.com/old-5-dollar-intent', $result['redirect'] ?? null);
        }

        // WOO-1: when no intent is stored yet, an event naming an order id but no order key matched.
        public function testWoo1WebhookMetadataMustCarryTheOrderKey(): void
        {
            $order = new \WC_Order(99, '10.00', 'USD', 'wc_order_abc');
            $matches = new \ReflectionMethod(\WC_OrcaRail_Webhook_Handler::class, 'metadata_matches_order');

            self::assertFalse($matches->invoke(null, ['woocommerce_order_id' => '99'], $order));
            self::assertTrue($matches->invoke(null, [
                'woocommerce_order_id' => '99',
                'woocommerce_order_key' => 'wc_order_abc',
            ], $order));
        }

        // WOO-2: bin/build-zip.sh copied the whole folder minus a blocklist, so local files such as
        // .env.wordpress.generated (API key and secret) went into the release zip.
        public function testWoo2ReleaseZipContainsNoLocalSecretsOrDevFiles(): void
        {
            $root = sys_get_temp_dir() . '/orcarail-zip-' . bin2hex(random_bytes(4));
            mkdir($root . '/bin', 0777, true);
            mkdir($root . '/includes');
            mkdir($root . '/.real-flow-logs');
            mkdir($root . '/e2e');
            mkdir($root . '/stub-bin');
            copy(dirname(__DIR__) . '/bin/build-zip.sh', $root . '/bin/build-zip.sh');
            file_put_contents($root . '/orcarail-woocommerce.php', "<?php\n/*\n * Version: 9.9.9\n */\n");
            file_put_contents($root . '/includes/class-x.php', "<?php\n");
            file_put_contents($root . '/.env.wordpress.generated', "ORCARAIL_API_SECRET=fake-secret\n");
            file_put_contents($root . '/.env.example', "ORCARAIL_API_KEY=\n");
            file_put_contents($root . '/.real-flow-logs/run.log', "log\n");
            file_put_contents($root . '/e2e/spec.ts', "test\n");
            file_put_contents($root . '/docker-compose.yml', "services: {}\n");
            file_put_contents($root . '/stub-bin/composer', "#!/bin/sh\nexit 0\n");
            chmod($root . '/stub-bin/composer', 0755);

            exec(
                'PATH=' . escapeshellarg($root . '/stub-bin') . ':"$PATH" bash '
                    . escapeshellarg($root . '/bin/build-zip.sh') . ' 2>&1',
                $output,
                $exit,
            );
            self::assertSame(0, $exit, implode("\n", $output));
            exec('unzip -Z1 ' . escapeshellarg($root . '/dist/orcarail-woocommerce-9.9.9.zip'), $files);

            $leaked = array_values(array_filter(
                $files,
                static fn (string $f) => (bool) preg_match('#/(\.env[^/]*|\.real-flow-logs/.*|e2e/.*|docker-compose\.yml)$#', $f),
            ));
            exec('rm -rf ' . escapeshellarg($root));

            self::assertSame([], $leaked);
            self::assertContains('orcarail-woocommerce/includes/class-x.php', $files);
        }
    }
}
