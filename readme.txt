=== OrcaRail for WooCommerce ===
Contributors: orcarail
Tags: woocommerce, payments, crypto, orcarail, cryptocurrency
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept crypto payments on WooCommerce with OrcaRail hosted checkout.

== Description ==

OrcaRail for WooCommerce redirects shoppers to OrcaRail hosted checkout to pay with crypto, then reconciles the WooCommerce order from verified webhooks and a signed return URL.

= Features =

* One-time crypto payments via OrcaRail Payment Intents
* Classic checkout and Checkout Blocks support
* Verified webhooks (`X-Webhook-Signature`)
* Idempotent order status updates
* HPOS / custom order tables compatible
* Fixed merchant-selected token and network

= Not supported in 1.0 =

* Refunds through WooCommerce
* WooCommerce Subscriptions / saved payment methods
* Express wallets (Apple Pay / Google Pay)

= Setup =

1. Install and activate WooCommerce.
2. Install this plugin (official ZIP includes Composer vendor).
3. Open **WooCommerce → Settings → Payments → OrcaRail**.
4. Enter API key, API secret, webhook signing secret, token ID, and network ID.
5. In the OrcaRail dashboard, set the API key webhook URL to the URL shown in the settings description (`/?wc-api=wc_gateway_orcarail&orcarail=webhook`).
6. To test first, open **Go to sandbox** in the OrcaRail dashboard, create a sandbox API key (`ak_test_…`), fill the **Test** fields (key, secret, webhook secret, testnet token and network IDs) and enable **Test mode**. Checkout then runs on testnets with no real funds; an admin notice reminds you while it is on. Disable test mode to go live.

== Installation ==

1. Upload the `orcarail-woocommerce` folder to `/wp-content/plugins/`, or install the release ZIP from GitHub.
2. Activate the plugin through the Plugins screen.
3. Configure OrcaRail under WooCommerce → Settings → Payments.

== Frequently Asked Questions ==

= Does the browser return prove payment? =

No. The return endpoint retrieves the Payment Intent from the OrcaRail API before updating the order. Webhooks are the primary source of truth.

= How do I test without real funds? =

Enable **Test mode** with the keys of your OrcaRail sandbox organization (`ak_test_…`). Test payments use testnets and can be simulated on the payment page without a wallet. A live store ignores sandbox webhook events, and a store in test mode ignores live ones.

= Can customers choose any token? =

Not in 1.0. The merchant configures one token ID and one network ID in gateway settings.

== Changelog ==

= Unreleased =
* Test mode: separate sandbox (ak_test_) credentials, testnet token and network, admin notice, key-prefix validation on save, and webhook livemode checks.

= 1.0.1 =
* Dependencies now install from public Packagist; no private registry credentials required.

= 1.0.0 =
* Initial release: hosted one-time checkout, webhooks, Blocks, HPOS.
