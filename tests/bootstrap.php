<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');
define('ORCARAIL_WC_VERSION', '1.0.0');
define('ORCARAIL_WC_PLUGIN_FILE', dirname(__DIR__) . '/orcarail-woocommerce.php');
define('ORCARAIL_WC_PLUGIN_PATH', dirname(__DIR__));
define('ORCARAIL_WC_PLUGIN_URL', 'https://example.com/wp-content/plugins/orcarail-woocommerce');

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Minimal WC_Order stand-in for unit tests.
 */
class WC_Order
{
    private int $id;
    private string $currency;
    private string $total;
    private string $order_key;
    private string $order_number;
    private string $email;
    private string $payment_method = 'orcarail';
    private string $status = 'pending';
    /** @var array<string, string> */
    private array $meta = [];
    /** @var list<string> */
    public array $notes = [];
    public bool $payment_complete_called = false;
    public ?string $payment_complete_txn = null;

    public function __construct(
        int $id = 42,
        string $total = '19.99',
        string $currency = 'USD',
        string $key = 'wc_order_testkey',
        string $email = 'buyer@example.com',
    ) {
        $this->id = $id;
        $this->total = $total;
        $this->currency = $currency;
        $this->order_key = $key;
        $this->order_number = (string) $id;
        $this->email = $email;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_currency(): string
    {
        return $this->currency;
    }

    public function get_order_key(): string
    {
        return $this->order_key;
    }

    public function get_order_number(): string
    {
        return $this->order_number;
    }

    public function get_billing_email(): string
    {
        return $this->email;
    }

    public function get_payment_method(): string
    {
        return $this->payment_method;
    }

    public function set_payment_method(string $method): void
    {
        $this->payment_method = $method;
    }

    public function get_meta(string $key, bool $single = true): string
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, string $value): void
    {
        $this->meta[$key] = $value;
    }

    public function is_paid(): bool
    {
        return in_array($this->status, ['processing', 'completed'], true)
            || $this->payment_complete_called;
    }

    /**
     * @param list<string>|string $statuses
     */
    public function has_status(array|string $statuses): bool
    {
        $statuses = (array) $statuses;
        return in_array($this->status, $statuses, true);
    }

    public function update_status(string $status, string $note = ''): void
    {
        $this->status = $status;
        if ($note !== '') {
            $this->notes[] = $note;
        }
    }

    public function payment_complete(?string $txn = ''): void
    {
        $this->payment_complete_called = true;
        $this->payment_complete_txn = $txn;
        $this->status = 'processing';
    }

    public function add_order_note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function save(): void
    {
    }

    public function get_status(): string
    {
        return $this->status;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-logger.php';
require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-api.php';
require_once ORCARAIL_WC_PLUGIN_PATH . '/includes/class-wc-orcarail-order-handler.php';
