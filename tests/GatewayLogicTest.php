<?php

declare(strict_types=1);

namespace OrcaRail\WooCommerce\Tests;

use OrcaRail\Webhook;
use OrcaRail\Exception\SignatureVerificationException;
use PHPUnit\Framework\TestCase;

final class GatewayLogicTest extends TestCase
{
    public function testFormatsAmountAndBuildsIntentMetadata(): void
    {
        $order = new \WC_Order(99, '10.5', 'EUR', 'wc_order_abc', 'payer@example.com');
        self::assertSame('10.50', \WC_OrcaRail_API::format_amount($order));

        $params = \WC_OrcaRail_API::build_intent_params(
            $order,
            ['token_id' => 'tok-1', 'network_id' => 'net-1'],
            'https://shop.example/return',
            'https://shop.example/cancel',
        );

        self::assertSame('10.50', $params['create']['amount']);
        self::assertSame('eur', $params['create']['currency']);
        self::assertSame('tok-1', $params['create']['tokenId']);
        self::assertSame('net-1', $params['create']['networkId']);
        self::assertSame(['crypto'], $params['create']['payment_method_types']);
        self::assertSame('99', $params['create']['metadata']['woocommerce_order_id']);
        self::assertSame('wc_order_abc', $params['create']['metadata']['woocommerce_order_key']);
        self::assertSame('payer@example.com', $params['create']['payerEmail']);
        self::assertSame('https://shop.example/return', $params['confirm']['return_url']);
    }

    public function testStatusFromEventType(): void
    {
        self::assertSame('completed', \WC_OrcaRail_Order_Handler::status_from_event_type('payment_intent.completed'));
        self::assertSame('processing', \WC_OrcaRail_Order_Handler::status_from_event_type('payment_intent.processing'));
        self::assertSame('canceled', \WC_OrcaRail_Order_Handler::status_from_event_type('payment_intent.canceled'));
        self::assertSame(
            'requires_payment_method',
            \WC_OrcaRail_Order_Handler::status_from_event_type('payment_intent.requires_payment_method')
        );
        self::assertNull(\WC_OrcaRail_Order_Handler::status_from_event_type('subscription.created'));
    }

    public function testReconcileCompletedIsIdempotentAndRejectsIntentMismatch(): void
    {
        $order = new \WC_Order();
        self::assertTrue(\WC_OrcaRail_Order_Handler::reconcile($order, 'pi_1', 'completed'));
        self::assertTrue($order->payment_complete_called);
        self::assertSame('pi_1', $order->payment_complete_txn);
        self::assertSame('pi_1', $order->get_meta(\WC_OrcaRail_API::META_INTENT_ID));

        $order->payment_complete_called = false;
        self::assertTrue(\WC_OrcaRail_Order_Handler::reconcile($order, 'pi_1', 'completed'));
        self::assertFalse($order->payment_complete_called);

        self::assertFalse(\WC_OrcaRail_Order_Handler::reconcile($order, 'pi_other', 'completed'));
    }

    public function testReconcileProcessingAndCanceled(): void
    {
        $processing = new \WC_Order();
        self::assertTrue(\WC_OrcaRail_Order_Handler::reconcile($processing, 'pi_2', 'processing'));
        self::assertTrue($processing->has_status('on-hold'));

        $canceled = new \WC_Order();
        self::assertTrue(\WC_OrcaRail_Order_Handler::reconcile($canceled, 'pi_3', 'canceled'));
        self::assertTrue($canceled->has_status('cancelled'));

        $failed = new \WC_Order();
        self::assertTrue(\WC_OrcaRail_Order_Handler::reconcile($failed, 'pi_4', 'requires_payment_method'));
        self::assertTrue($failed->has_status('failed'));
    }

    public function testDuplicateCheckoutReuseCondition(): void
    {
        $order = new \WC_Order();
        $order->update_meta_data(\WC_OrcaRail_API::META_INTENT_ID, 'pi_reuse');
        $order->update_meta_data(\WC_OrcaRail_API::META_PAY_URL, 'https://pay.orcarail.com/x');

        $intent = (string) $order->get_meta(\WC_OrcaRail_API::META_INTENT_ID, true);
        $url = (string) $order->get_meta(\WC_OrcaRail_API::META_PAY_URL, true);
        self::assertTrue($intent !== '' && $url !== '' && !$order->is_paid());
    }

    public function testWebhookSignatureRejectsTampering(): void
    {
        $payload = '{"type":"payment_intent.completed","data":{"object":{"id":"pi_1","metadata":{"woocommerce_order_id":"42","woocommerce_order_key":"wc_order_testkey"}}},"created":1}';
        $signature = hash_hmac('sha256', $payload, 'whsec');

        $event = Webhook::constructEvent($payload, $signature, 'whsec');
        self::assertSame('payment_intent.completed', $event->type);
        self::assertSame('pi_1', $event->data->object->id);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($payload . ' ', $signature, 'whsec');
    }

    public function testMetadataMismatchDetection(): void
    {
        $order = new \WC_Order(42, '1.00', 'USD', 'wc_order_testkey');
        $good = ['woocommerce_order_id' => '42', 'woocommerce_order_key' => 'wc_order_testkey'];
        $badId = ['woocommerce_order_id' => '99', 'woocommerce_order_key' => 'wc_order_testkey'];
        $badKey = ['woocommerce_order_id' => '42', 'woocommerce_order_key' => 'other'];

        self::assertTrue($this->metadataMatches($good, $order));
        self::assertFalse($this->metadataMatches($badId, $order));
        self::assertFalse($this->metadataMatches($badKey, $order));
    }

    /**
     * Mirrors WC_OrcaRail_Webhook_Handler::metadata_matches_order without WP globals.
     *
     * @param array<string, string> $meta
     */
    private function metadataMatches(array $meta, \WC_Order $order): bool
    {
        if (($meta['woocommerce_order_id'] ?? null) !== null
            && (string) $order->get_id() !== $meta['woocommerce_order_id']) {
            return false;
        }
        if (($meta['woocommerce_order_key'] ?? null) !== null
            && !hash_equals($order->get_order_key(), $meta['woocommerce_order_key'])) {
            return false;
        }
        return true;
    }
}
