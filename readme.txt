=== Coinsnap Bitcoin + Lightning payment plug-in 1.0.0 for Contact Form 7 ===
Contributors: coinsnap
Tags:  Coinsnap, Contact Form 7, Bitcoin, Lightning
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Coinsnap payment plug-in is intended for ContactForm7 to accept Bitcoin and Lightning payments via Coinsnap payment gateway.

== Description ==

[Coinsnap](https://coinsnap.io/en/) for Contact Form 7 allows you to process Bitcoin Lightning payments over the Lightning network https://app.coinsnap.io/. 
With the Coinsnap Bitcoin-Lightning payment plugin for Contact Form 7 you only need a Lightning wallet with a Lightning address to accept Bitcoin Lightning payments on your Wordpress site.

* WooCommerce Coinsnap Demo Store: https://contactform7.coinsnap.org/
* Blog Article: https://coinsnap.io/en/coinsnap-for-contact-form-7/
* WordPress: https://wordpress.org/plugins/coinsnap-for-contactform7/
* GitHub: https://github.com/Coinsnap/Coinsnap-for-Contactform7


= Bitcoin and Lightning payments in Contact Form 7 with Coinsnap =

With Contact Form 7, website owners can create forms with different fields such as name, email, subject, message, etc. and embed them in their pages or posts. It is highly customizable and allows users to change the look and feel of their contact forms.

With the Coinsnap Bitcoin Lightning payment processing plugin you can immediately accept Bitcoin Lightning payments on your site. You don’t need your own Lightning node or any other technical requirements.

Simply register on [Coinsnap](https://app.coinsnap.io/register), enter your own Lightning address and install the Coinsnap payment module in your wordpress backend. Add your store ID and your API key which you’ll find in your Coinsnap account, and your customers can pay you with Bitcoin Lightning right away!

= Features: =

* **All you need is a Lightning Wallet with a Lightning address. [Here you can find an overview of the matching Lightning Wallets](https://coinsnap.io/en/lightning-wallet-with-lightning-address/)**

* **Accept Bitcoin and Lightning payments** in your online store **without running your own technical infrastructure.** You do not need your own server, nor do you need to run your own Lightning Node.

* **Quick and easy registration at Coinsnap**: Just enter your email address and your Lightning address – and you are ready to integrate the payment module and start selling for Bitcoin Lightning. You will find the necessary IDs and Keys here, too.

* **100% protected privacy**:
    * We do not collect personal data.
    * For the registration you only need an e-mail address, which we will also use to inform you when we have received a payment.
    * No other personal information is required as long as you request a withdrawal to a Lightning address or Bitcoin address.

* **Only 1 % fees!**:
    * No basic fee, no transaction fee, only 1% on the invoice amount with referrer code.
    * Without referrer code the fee is 1.25%.
    * Get a referrer code from our partners and customers and save 0.25% fee.

* **No KYC needed**:
    * Direct, P2P payments (instantly to your Lightning wallet)
    * No intermediaries and paperwork
    * Transaction information is only shared between you and your customer

* **Sophisticated merchant’s admin dashboard in Coinsnap:**:
    * See all your transactions at a glance
    * Follow-up on individual payments
    * See issues with payments
    * Export reports

* **A Bitcoin payment via Lightning offers significant advantages**:
    * Lightning **payments are executed immediately.**
    * Lightning **payments are credited directly to the recipient.**
    * Lightning **payments are inexpensive.**
    * Lightning **payments are guaranteed.** No chargeback risk for the merchant.
    * Lightning **payments can be used worldwide.**
    * Lightning **payments are perfect for micropayments.**

* **Multilingual interface and support**: We speak your language


= Documentation: =

* [Coinsnap API (1.0) documentation](https://docs.coinsnap.io/)
* [Frequently Asked Questions](https://coinsnap.io/en/faq/) 
* [Terms and Conditions](https://coinsnap.io/en/general-terms-and-conditions/)
* [Privacy Policy](https://coinsnap.io/en/privacy/)


== Installation ==

### 1. Install the Coinsnap Contact Form 7 plug-in from the WordPress directory. ###

The Coinsnap Contact Form 7 plug-in can be searched and installed in the WordPress plugin directory.

In your WordPress instance, go to the Plugins > Add New section.
In the search you enter Coinsnap and get as a result the Coinsnap Contact Form 7 plug-in displayed.

Then click Install.

After successful installation, click Activate and then you can start setting up the plugin.

### 1.1. Add plugin ###

If you don’t want to install add-on directly via plugin, you can download Coinsnap Contact Form 7 plug-in from Coinsnap Github page or from WordPress directory and install it via “Upload Plugin” function:

Navigate to Plugins > Add Plugins > Upload Plugin and Select zip-archive downloaded from Github.

Click “Install now” and Coinsnap Contact Form 7 plug-in will be installed in WordPress.

After you have successfully installed the plugin, you can proceed with the connection to Coinsnap payment gateway.

### 1.2. Configure Coinsnap Contact Form 7 plug-in ###

After the Coinsnap Contact Form 7 plug-in is installed and activated, a notice appears that the plugin still needs to be configured.

### 1.3. Deposit Coinsnap data ###

* Navigate to Contact > Contact Forms > Edit > select Coinsnap
* Enter Amount Field Name, Name Field Name, E-Mail Field Name, Store ID , API Key and Success URL
* Click "Save"

If you don’t have a Coinsnap account yet, you can do so via the link shown: Coinsnap Registration

### 2. Create Coinsnap account ####

### 2.1. Create a Coinsnap Account ####

Now go to the Coinsnap website at: https://app.coinsnap.io/register and open an account by entering your email address and a password of your choice.

If you are using a Lightning Wallet with Lightning Login, then you can also open a Coinsnap account with it.

### 2.2. Confirm email address ####

You will receive an email to the given email address with a confirmation link, which you have to confirm. If you do not find the email, please check your spam folder.

Then please log in to the Coinsnap backend with the appropriate credentials.

### 2.3. Set up website at Coinsnap ###

After you sign up, you will be asked to provide two pieces of information.

In the Website Name field, enter the name of your online store that you want customers to see when they check out.

In the Lightning Address field, enter the Lightning address to which the Bitcoin and Lightning transactions should be forwarded.

A Lightning address is similar to an e-mail address. Lightning payments are forwarded to this Lightning address and paid out. If you don’t have a Lightning address yet, set up a Lightning wallet that will provide you with a Lightning address.

For more information on Lightning addresses and the corresponding Lightning wallet providers, click here:
https://coinsnap.io/lightning-wallet-mit-lightning-adresse/

### 3. Connect Coinsnap account with Contact Form 7 plug-in ###

### 3.1. Contact Form 7 Coinsnap Settings ###

* Navigate to Contact > Contact Forms > Edit > select Coinsnap
* Enter Amount Field Name, Name Field Name, E-Mail Field Name, Store ID , API Key and Success URL
* Click "Save"

### 4. Test payment ###

### 4.1. Test payment in Contact Form 7 ###

After all the settings have been made, a test payment should be made.

We make a real donation payment in our test Contact Form 7 site.

### 4.2. Bitcoin + Lightning payment page ###

The Bitcoin + Lightning payment page is now displayed, offering the payer the option to pay with Bitcoin or also with Lightning. Both methods are integrated in the displayed QR code.

# Upgrade Notice #

Follow updates on plugin's GitHub page:
https://github.com/Coinsnap/Coinsnap-for-Contactform7

# Frequently Asked Questions #

Plugin's page on Coinsnap website: https://coinsnap.io/en/

== Screenshots ==

1. Contact Form 7 Plugin
2. Plugin downloading from Github repository
3. Manual plugin installation
4. Plugin settings
5. Contact Form 7 form constructor
6. Contact Form 7 form interface
7. QR code on the Bitcoin payment page
  
# Changelog #
= 1.0.0 :: 2024-07-08 =
* Initial release. 
