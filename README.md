# Coinsnap for Contact Form 7

![Coinsnap for Contact Form 7](https://resources.coinsnap.org/products/contact-form-7/images/banner-772x250.png)

**Bitcoin payment add-on for Contact Form 7 — accept Bitcoin and Lightning in forms for donations, products, bookings, registrations, downloads, services, support requests and micropayments.**

Coinsnap for Contact Form 7 turns existing Contact Form 7 forms into lightweight Bitcoin checkout experiences.

You can add Bitcoin payments to selected forms without WooCommerce or another e-commerce system. This makes Contact Form 7 useful for donations, physical products, digital downloads, appointments, event registrations, premium submissions, paid support requests, newsletter sign-ups and small anti-spam payments.

Payments can be made with **Bitcoin via Lightning or on-chain** using **Coinsnap** or your own **BTCPay Server**.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in Contact Form 7
- Support for **Lightning and Bitcoin on-chain**
- Add Bitcoin payments only to the forms where they are needed
- Suitable for donations, products, bookings, registrations, downloads, services and paid interactions
- No WooCommerce or full shop system required
- Works alongside other payment methods such as Stripe
- Support for **Coinsnap** and **BTCPay Server**
- BTCPay Server setup wizard
- Custom redirect after successful payment
- Configurable checkout texts, payment-method name and icon/logo
- Optional **Bitcoin discount** as a fixed amount or percentage
- Payment-first and form-submission-after-payment workflows
- Coinsnap payment overview in WordPress
- Micropayment and anti-spam use cases
- No own Lightning node required when using Coinsnap
- **Non-custodial / self-custody** payment model
- Optional settlement to a bank account through supported Coinsnap partners
- Open source and free to install

## Current public release

The current WordPress.org release is **1.5.3**.

WordPress.org currently lists:

- WordPress **6.2 or newer**
- PHP **7.4 or newer**
- Tested up to **WordPress 7.0.7**
- Contact Form 7 compatibility tested up to **6.1.6**

> **Developer note:** the current GitHub `main` branch still identifies the plugin as version **1.5.2**, tested with WordPress 6.9 and Contact Form 7 6.1.5. The repository code metadata and public WordPress.org release should be synchronized separately.

## Typical use cases

Coinsnap for Contact Form 7 can be used for:

- simple Bitcoin donation forms
- lightweight product sales without a shop system
- digital downloads
- event registrations
- bookings and reservations
- consultations and meetings
- paid support or contact requests
- newsletter sign-ups with a small fee
- premium submissions
- paid access to download links or return pages
- low-value Lightning micropayments
- anti-spam form submissions

## How it works

1. Install and activate **Contact Form 7**.
2. Install and activate **Coinsnap for Contact Form 7**.
3. Create or edit a Contact Form 7 form.
4. Open the **Coinsnap** tab in the form settings.
5. Enable Bitcoin payments for that form.
6. Select **Coinsnap** or **BTCPay Server**.
7. Configure currency, payment settings and redirect destination.
8. Add the Coinsnap form tag where the payment action should appear.
9. Publish the form.
10. Make a test Bitcoin payment before going live.

## Payment tag

The current Coinsnap product documentation uses the Contact Form 7 form tag:

`[coinsnap]`

Place it inside the Contact Form 7 form at the position where the Bitcoin payment action should appear.

## Coinsnap or BTCPay Server

The add-on supports two payment-provider modes:

- **Coinsnap** — for website owners who want to accept Bitcoin without operating their own Bitcoin or Lightning payment infrastructure.
- **BTCPay Server** — for users who want to connect their own self-hosted BTCPay Server.

## Payment and form-submission flow

The plugin supports workflows where the Bitcoin payment and Contact Form 7 submission are coordinated.

Current releases include **form submission after payment** functionality and show the corresponding submission-after-payment status in the Coinsnap payments table.

This is useful when a form should only be processed after a successful Bitcoin payment.

## Bitcoin discount

The current Coinsnap Contact Form 7 product page supports an optional **Bitcoin discount**.

Merchants can configure a fixed or percentage-based discount to encourage visitors to choose Bitcoin when multiple payment methods are available.

## Micropayments and anti-spam

Lightning makes very small payments practical.

This enables paid interactions such as newsletter sign-ups, comments, support requests or form submissions. A small Lightning fee can also create an economic barrier for automated spam while remaining inexpensive for a legitimate user.

## Settlement

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a **non-custodial / self-custody** approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be automatically converted and settled to a bank account.

## Fees

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin payment itself.

## Installation

### Install from WordPress.org

1. Install and activate **Contact Form 7**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin Payment for Contact Form 7**.
4. Install and activate the Coinsnap add-on.
5. Open the Contact Form 7 form you want to monetize.
6. Open the **Coinsnap** tab.
7. Enable Bitcoin payments and connect Coinsnap or BTCPay Server.
8. Add the `[coinsnap]` form tag.
9. Save and publish the form.
10. Make a test Bitcoin payment.

### Install from GitHub

1. Download this repository as a ZIP archive.
2. Upload it through **Plugins → Add New → Upload Plugin**.
3. Install and activate the add-on.
4. Make sure Contact Form 7 is installed and active.
5. Configure Coinsnap in the Contact Form 7 form settings.

## Resources

- [Coinsnap for Contact Form 7 product page](https://coinsnap.io/wp-add-ons/contact-form-7/)
- [Contact Form 7 Bitcoin User Guide](https://coinsnap.io/user-guides/contact-form-7-bitcoin-user-guide/)
- [WordPress Plugin Directory](https://wordpress.org/plugins/coinsnap-for-contact-form-7/)
- [Contact Form 7 demo](https://contactform7.coinsnap.org/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### Can Contact Form 7 accept Bitcoin payments?

Yes. Coinsnap for Contact Form 7 adds Bitcoin and Lightning payments directly to selected Contact Form 7 forms.

### Do I need WooCommerce?

No. The integration works independently and does not require an e-commerce plugin.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. The integration supports both Lightning and Bitcoin on-chain.

### Can Bitcoin be offered alongside Stripe?

Yes. Bitcoin can be added alongside existing payment methods or used as a standalone payment option.

### Can I use my own BTCPay Server?

Yes. BTCPay Server is supported as an alternative to Coinsnap.

### How do I add the Bitcoin payment action to a form?

Place the `[coinsnap]` form tag inside the Contact Form 7 form.

### Can the form be submitted only after payment?

Yes. Current releases include form-submission-after-payment functionality.

### Does the plugin support a Bitcoin discount?

Yes. The current Coinsnap product page documents an optional fixed or percentage-based Bitcoin discount.

### Can I use the plugin for micropayments or anti-spam?

Yes. Lightning is suitable for low-value payments, including paid submissions and small anti-spam fees.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Does Coinsnap hold my Bitcoin?

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

### Can payments be settled to a bank account?

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be automatically converted and settled to a bank account.

### What does Coinsnap cost?

Coinsnap charges a 1% transaction fee.

## Changelog

### 1.5.3 — 2026-05-23
- Compatibility with WordPress 7.0 tested.
- Compatibility with Contact Form 7 6.1.6 tested.

### 1.5.2 — 2026-03-19
- Updated webhook payload handling.
- Updated currency-rate handling.

### 1.5.1 — 2026-03-18
- Added form-submission-after-payment status to the payments table.

### 1.5.0 — 2026-03-17
- Added form submission after payment functionality.
- Compatibility with Contact Form 7 6.1.5 tested.
- Compatibility with WordPress 6.9.4 tested.
- Updated plugin description.

### 1.4.3 — 2025-12-28
- Updated PHP file protection.

### 1.4.2 — 2025-12-05
- Updated Coinsnap Store client data handling.
- Compatibility with WordPress 6.9 tested.
- Compatibility with Contact Form 7 6.1.4 tested.

### 1.4.1 — 2025-10-15
- Added handling for currencies not supported by BTCPay Server.
- Added custom redirect after payment.
- Compatibility with Contact Form 7 6.1.2 tested.

### 1.4.0 — 2025-08-10
- Updated BTCPay Server minimum-payment calculations.
- Updated BTCPay Server events and webhook handling.
- Compatibility with Contact Form 7 6.1.1 tested.

### 1.3.2 — 2025-07-21
- Improved webhook creation.
- Added nonce validation to the BTCPay Server setup flow.
- Improved API-key generation.

### 1.3.1 — 2025-06-27
- Fixed webhook creation.
- Compatibility with Contact Form 7 6.1 tested.

### 1.3.0 — 2025-06-25
- Improved BTCPay Server setup callback handling.
- Added webhook data storage and webhook-secret validation.

### 1.2.0 — 2025-06-03
- Added BTCPay Server as a payment gateway.
- Added the BTCPay Server setup wizard.
- Added minimum-payment validation.
- Compatibility with Contact Form 7 6.0.6 tested.
- Compatibility with WordPress 6.8 tested.

### 1.1.0 — 2025-04-17
- Added automatic redirect after payment.
- Added minimum-payment validation.

### 1.0.4 — 2025-02-09
- Added Contact Form 7 identification to the Coinsnap server connection status.

### 1.0.3 — 2024-12-25
- Fixed variable type mismatches in exception handling.

### 1.0.2 — 2024-12-10
- Fixed ConnectException handling.

### 1.0.1 — 2024-11-10
- Updated plugin description and installation instructions.
- Updated connection exception and connection-check handling.

### 1.0.0 — 2024-07-08
- Initial release.
