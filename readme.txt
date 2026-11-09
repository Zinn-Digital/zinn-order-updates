=== Zinn® Order Updates ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-order-updates
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: woocommerce, sms, whatsapp, order notifications, order status
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zinn® Campaigns sends your store's SMS and WhatsApp order updates in your store's name.

== Description ==

**This plugin connects your store to Zinn® Campaigns, paid from your Zinn® credit, so your customers can get their order updates by SMS and WhatsApp, sent under your store's own name.**

= What it does =

* **Connects in one click.** Press Connect, sign in to Zinn® (or create an account), approve the store, and you come straight back. You never paste a key, and the store's token is kept out of sight.
* **Disconnects from either side.** Press Disconnect here, or remove the store in your Zinn® dashboard; the store stops at once.

== Installation ==

1. Install and activate the plugin.
2. Open the Zinn® Order Updates screen in the WordPress admin menu and press Connect.
3. Approve the store in Zinn®. You come back to WordPress connected.

== External services ==

This plugin connects to the Zinn® API at `https://api.zinndigital.com`, run by Zinn Digital® Ltd. Terms: https://zinndigital.com/legal/terms — Privacy: https://zinndigital.com/legal/privacy

It sends nothing until you press Connect. Then:

* **Connect** (your browser opens `https://app.zinndigital.com/connect/store`, once, when you press it): the store address, the address of this plugin's admin page to come back to, your admin language, a random value that ties the answer to this browser, and a one-way hash of a secret the plugin keeps (PKCE). You sign in and approve there.
* **Token** (`POST /v1/store-connect/token`, once, when you come back): the one-time code from Zinn®, the secret behind the hash, the store address, a random id the plugin made for this install, the plugin, WordPress, WooCommerce and PHP versions, whether the site is a multisite, and which supported store plugins are active.
* **Status** (`GET /v1/store-connect/status`, at most every five minutes while you have the Order Updates screen open): the plugin and WordPress versions and which supported store plugins are active. The answer is the connection's state.
* **Disconnect** (`DELETE /v1/store-connect/connection`, when you press it): a request that revokes the store's token.

== Frequently Asked Questions ==

= Do I need a Zinn® account? =

Yes. Press Connect and you can create one on the way.

= Does the plugin send my customers' data anywhere yet? =

No. This version only connects the store. Nothing about your orders or customers is sent.

== Changelog ==

= 0.1.0 =
* One-click connect to Zinn® Campaigns, connection status and disconnect.

== Upgrade Notice ==

= 0.1.0 =
First release.
