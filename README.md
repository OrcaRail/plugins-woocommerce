# OrcaRail for WooCommerce

Accept crypto payments on your WooCommerce store using [OrcaRail](https://orcarail.com) hosted checkout and the official [`orcarail/orcarail-php`](https://github.com/OrcaRail/sdks-php) SDK.

Repository: https://github.com/OrcaRail/plugins-woocommerce

## Requirements

- PHP 8.1+
- WordPress 6.4+
- WooCommerce 8.0+
- OrcaRail API key + secret with a webhook signing secret

## Install

Use a [GitHub release ZIP](https://github.com/OrcaRail/plugins-woocommerce/releases). Release artifacts include a production `vendor/` tree so merchants do not need Composer.

1. Upload and activate the plugin.
2. Go to **WooCommerce → Settings → Payments → OrcaRail**.
3. Fill in API key, API secret, webhook signing secret, token ID, and network ID.
4. Point your OrcaRail API key webhook URL at:

   `https://your-store.example/?wc-api=wc_gateway_orcarail&orcarail=webhook`

## How it works

```text
Checkout → create+confirm Payment Intent (PHP SDK)
        → redirect to OrcaRail pay_url
        → shopper pays crypto
        → webhook / return reconcile Woo order
```

- **Webhook**: verifies `X-Webhook-Signature` with `OrcaRail\Webhook` on the raw body.
- **Return URL**: server-side `paymentIntents->retrieve()` before changing order status. Browser redirect alone is never trusted as payment proof.
- **Retries**: if an order already has an intent + pay URL and is unpaid, checkout reuses them instead of creating duplicates.

## Supported / not supported (1.0)

| Supported | Not supported |
| --- | --- |
| One-time products | Refunds via WooCommerce |
| Classic + Checkout Blocks | Subscriptions / saved methods |
| HPOS | Express wallets |

## Development

`orcarail/orcarail-php` is published on OrcaRail's [Private Packagist](https://repo.packagist.com/orcarail/) org, which also mirrors packagist.org. Authenticate once before installing:

```bash
composer config --global --auth http-basic.repo.packagist.com token <YOUR_TOKEN>
```

```bash
cd plugins-woocommerce
composer install
composer test
composer analyse
composer build-zip
```

CI reads the same credentials from a `COMPOSER_AUTH` repository secret, for example:

```json
{"http-basic":{"repo.packagist.com":{"username":"token","password":"<YOUR_TOKEN>"}}}
```

Release ZIPs ship with a production `vendor/` tree (SDK included), so merchants do not need Composer.

## Local real-flow + Docker Woo

Smoke the gateway against the host OrcaRail real-flow stack (pg-mem API on `:3000`, pay on `:5174`) and a local WordPress/Woo store on `:8088`. Webhooks use a **loopback URL** — no public tunnel. Cloud OrcaRail cannot reach this URL; only the local API can.

```bash
# From the OrcaRail monorepo (plugins-woocommerce as sibling of api/).
cd plugins-woocommerce
composer install   # needs Private Packagist auth (see above)
composer test:wordpress
# or: bash bin/run-woo-real-flow.sh
```

What the script does:

1. Ensures Redis + MailDev (`api` compose), then starts combined backend + pay if not already up (skips full `prepare-real-flow.sh` on-chain payer preflight — simulate path only)
2. Seeds API key with webhook `http://127.0.0.1:8088/?wc-api=wc_gateway_orcarail&orcarail=webhook` (override via `ORCARAIL_SEED_WEBHOOK_URL`) and ensures `API_KEY_WEBHOOK_SECRET_ENCRYPTION_KEY` for HMAC signing
3. `docker compose -p orcarail-woo` WordPress + MariaDB + plugin bind-mount
4. Configures gateway from `demo/.env.local` + `POST /api/__real-flow/configure-woo`
5. WP-CLI: `process_payment` → `__real-flow/simulate` → assert local webhook / signed fallback → return URL idempotency

Useful env:

| Variable | Default | Meaning |
| --- | --- | --- |
| `WORDPRESS_PORT` / `WORDPRESS_URL` | `8088` / `http://127.0.0.1:8088` | Host-published shop URL |
| `ORCARAIL_API_BASE_URL` | `http://host.docker.internal:3000/api/v1` | Gateway → host API (inside container) |
| `ORCARAIL_KEEP_WOO` | `0` | Set `1` to leave WP stack up after the run |
| `ORCARAIL_ROOT` | parent of this package | Monorepo root for real-flow scripts |

Manual steps (if you already have real-flow up):

```bash
cp .env.example .env   # optional overrides
bash bin/woo-up.sh
bash bin/woo-configure-from-real-flow.sh
```

## License

MIT — see [LICENSE](LICENSE).
