=== Bitcoin Payment for Contact Form 7 ===
Contributors: coinsnap
Tags: bitcoin, lightning, contact form 7, payments, btcpay
Requires at least: 6.2
Tested up to: 7.0.7
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin and Lightning in Contact Form 7 for donations, products, bookings, downloads, services, registrations and micropayments.

== Description ==

= Add Bitcoin and Lightning payments to Contact Form 7 =

Coinsnap for Contact Form 7 turns existing Contact Form 7 forms into lightweight Bitcoin checkout experiences.

Add Bitcoin payments to selected forms without WooCommerce or another e-commerce system. Use them for donations, physical products, digital downloads, bookings, registrations, consultations, premium submissions, paid support requests, newsletter sign-ups or small anti-spam payments.

Payments can be made with **Bitcoin via Lightning or on-chain** using Coinsnap or your own BTCPay Server.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

= Key features =

* Accept **Bitcoin and Lightning payments** in Contact Form 7
* Support for **Lightning and Bitcoin on-chain**
* Add Bitcoin payments only to selected forms
* Suitable for donations, products, bookings, registrations, downloads and services
* No WooCommerce or full shop system required
* Works alongside other payment methods such as Stripe
* Support for **Coinsnap** and **BTCPay Server**
* BTCPay Server setup wizard
* Custom redirect after successful payment
* Configurable checkout texts, payment-method name and icon/logo
* Optional **Bitcoin discount**
* Payment-first and form-submission-after-payment workflows
* Coinsnap payments overview in WordPress
* Micropayment and anti-spam use cases
* No own Lightning node required when using Coinsnap
* Non-custodial / self-custody payment model
* Optional bank settlement through supported Coinsnap partners
* Open source and free to install

= Typical use cases =

Use Coinsnap for Contact Form 7 for:

* simple Bitcoin donation forms
* lightweight product sales without a shop system
* digital downloads
* event registrations
* bookings and reservations
* consultations and meetings
* paid support or contact requests
* newsletter sign-ups with a small fee
* premium submissions
* paid access to download links
* Lightning micropayments
* anti-spam form submissions

= How it works =

1. Install and activate Contact Form 7.
2. Install and activate Coinsnap for Contact Form 7.
3. Create or edit a Contact Form 7 form.
4. Open the **Coinsnap** tab.
5. Enable Bitcoin payments for the form.
6. Select Coinsnap or BTCPay Server.
7. Configure currency, payment settings and redirect destination.
8. Add the Coinsnap form tag.
9. Save and publish the form.
10. Make a test Bitcoin payment.

= Payment tag =

Place the following form tag inside your Contact Form 7 form where the Bitcoin payment action should appear:

[coinsnap]

= Coinsnap or BTCPay Server =

The add-on supports:

* **Coinsnap** — accept Bitcoin without operating your own Bitcoin or Lightning payment infrastructure.
* **BTCPay Server** — connect your own self-hosted BTCPay Server.

= Form submission after payment =

Current releases support **form submission after payment**.

This allows the Contact Form 7 submission to be processed only after the Bitcoin payment has completed. The plugin also records the submission-after-payment status in its payments table.

= Bitcoin discount =

The current Coinsnap Contact Form 7 product supports an optional **Bitcoin discount**.

A fixed or percentage-based discount can be used to encourage visitors to choose Bitcoin when several payment methods are available.

= Micropayments and anti-spam =

Lightning makes small payments practical.

This can be used for paid newsletter sign-ups, comments, support requests, premium submissions or a small anti-spam fee on contact forms.

= Settlement =

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be automatically converted and settled to a bank account.

= Fees =

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin payment.

= More information =

* Product page: https://coinsnap.io/wp-add-ons/contact-form-7/
* User Guide: https://coinsnap.io/user-guides/contact-form-7-bitcoin-user-guide/
* WordPress Plugin Directory: https://wordpress.org/plugins/coinsnap-for-contact-form-7/
* Demo: https://contactform7.coinsnap.org/
* GitHub: https://github.com/Coinsnap/Coinsnap-for-Contactform7
* Coinsnap Wallet: https://coinsnap.io/wallet/
* Coinsnap API: https://docs.coinsnap.io/
* Coinsnap account: https://app.coinsnap.io/register

== Installation ==

1. Install and activate **Contact Form 7**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin Payment for Contact Form 7**.
4. Install and activate the Coinsnap add-on.
5. Open the Contact Form 7 form you want to monetize.
6. Open the **Coinsnap** tab.
7. Enable Bitcoin payments and connect Coinsnap or BTCPay Server.
8. Add the [coinsnap] form tag.
9. Save the form.
10. Make a test Bitcoin payment.

== Frequently Asked Questions ==

= Can Contact Form 7 accept Bitcoin payments? =

Yes. Coinsnap for Contact Form 7 adds Bitcoin and Lightning payments directly to selected Contact Form 7 forms.

= Do I need WooCommerce? =

No. The integration works independently and does not require an e-commerce plugin.

= Can customers pay with both Lightning and Bitcoin on-chain? =

Yes. The integration supports both Lightning and Bitcoin on-chain.

= Can Bitcoin be offered alongside Stripe? =

Yes. Bitcoin can be used alongside other payment methods or as a standalone option.

= Can I use my own BTCPay Server? =

Yes. BTCPay Server is supported as an alternative to Coinsnap.

= How do I add Bitcoin payment to a form? =

Place the [coinsnap] form tag inside the Contact Form 7 form.

= Can the form be submitted only after payment? =

Yes. Current releases support form submission after payment.

= Does the plugin support a Bitcoin discount? =

Yes. The current Coinsnap product documentation includes an optional fixed or percentage-based Bitcoin discount.

= Can I use the plugin for micropayments or anti-spam? =

Yes. Lightning is suitable for low-value paid interactions and small anti-spam fees.

= Do I need my own Lightning node? =

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

= Does Coinsnap hold my Bitcoin? =

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

= Can payments be settled to a bank account? =

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be converted and settled to a bank account.

= What does Coinsnap cost? =

Coinsnap charges a 1% transaction fee.

== Screenshots ==

1. Install Bitcoin Payment for Contact Form 7 from the WordPress Plugin Directory
2. Contact Form 7 form editor
3. Coinsnap settings tab
4. Select Coinsnap or BTCPay Server
5. BTCPay Server setup wizard
6. Configure currency and redirect
7. Add the Coinsnap form tag
8. Contact Form 7 Bitcoin payment form
9. Bitcoin and Lightning payment QR code
10. Successful payment and form submission
11. Coinsnap payments table

== Changelog ==

= 1.5.3 :: 2026-05-23 =
* Compatibility with WordPress 7.0 is tested.
* Compatibility with Contact Form 7 6.1.6 is tested.

= 1.5.2 :: 2026-03-19 =
* Updated webhook payload handler.
* Updated currency rates handler functionality.

= 1.5.1 :: 2026-03-18 =
* Added form submission after payment status in payments table.

= 1.5.0 :: 2026-03-17 =
* Added form submission after payment functionality.
* Compatibility with Contact Form 7 6.1.5 is tested.
* Compatibility with WordPress 6.9.4 is tested.
* Updated plugin description.

= 1.4.3 :: 2025-12-28 =
* Updated PHP files protection.

= 1.4.2 :: 2025-12-05 =
* Updated Coinsnap Store client data handling.
* Compatibility with WordPress 6.9 is tested.
* Compatibility with Contact Form 7 6.1.4 is tested.

= 1.4.1 :: 2025-10-15 =
* Added handling for currencies not supported by BTCPay Server.
* Added custom redirect after payment.
* Compatibility with Contact Form 7 6.1.2 is tested.

= 1.4.0 :: 2025-08-10 =
* Updated BTCPay Server minimum payment calculations.
* Updated BTCPay Server events and webhook handling.
* Compatibility with Contact Form 7 6.1.1 is tested.

= 1.3.2 :: 2025-07-21 =
* Improved webhook creation.
* Added nonce validation to the BTCPay Server setup flow.
* Improved API-key generation.

= 1.3.1 :: 2025-06-27 =
* Fixed webhook creation.
* Compatibility with Contact Form 7 6.1 is tested.

= 1.3.0 :: 2025-06-25 =
* Improved BTCPay Server setup callback handling.
* Added webhook data storage and webhook-secret validation.

= 1.2.0 :: 2025-06-03 =
* Added BTCPay Server as a Bitcoin payment gateway.
* Added the BTCPay Server setup wizard.
* Added minimum-payment validation.
* Compatibility with Contact Form 7 6.0.6 is tested.
* Compatibility with WordPress 6.8 is tested.

= 1.1.0 :: 2025-04-17 =
* Added automatic redirect after payment.
* Added minimum-payment validation.

= 1.0.4 :: 2025-02-09 =
* Added Contact Form 7 identification to the Coinsnap server connection status.

= 1.0.3 :: 2024-12-25 =
* Fixed variable type mismatches in exception handling.

= 1.0.2 :: 2024-12-10 =
* Fixed ConnectException handling.

= 1.0.1 :: 2024-11-10 =
* Updated plugin description and installation instructions.
* Updated connection exception and connection-check handling.

= 1.0.0 :: 2024-07-08 =
* Initial release.
