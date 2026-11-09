=== Zinn® Order Updates ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-order-updates
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: woocommerce, sms, whatsapp, order notifications, order status
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zinn® Campaigns sends your store's SMS and WhatsApp order updates in your store's name.

== Description ==

**This plugin connects your store to Zinn® Campaigns, paid from your Zinn® credit, so your customers can get their order updates by SMS and WhatsApp, sent under your store's own name.**

= What it does =

* **Connects in one click.** Press Connect, sign in to Zinn® (or create an account), approve the store, and you come straight back. You never paste a key, and the store's token is kept out of sight.
* **Disconnects from either side.** Press Disconnect here, or remove the store in your Zinn® dashboard; the store stops at once.
* **Help inside the plugin.** Ask the Zinn® AI first; if that does not solve it, send a bug report, a question, feedback or a feature request to the Zinn® team, and follow the replies under Order Updates → Help → My tickets. A badge on the menu shows tickets the team answered.
* **Diagnostics only with your consent.** Nothing is sent until you agree on the Help screen. Each diagnostics block is shown in full and sent only when you tick it; the store's token is never one of them.
* **Remembers each order's language.** At checkout the plugin stores the shopper's language on the order (from Tranzly, WPML, Polylang, TranslatePress, the shopper's account or the site language), so order updates can be sent in the language they bought in.

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

The Token request also reports the store's languages (at most 40 characters of language codes).

Help (Order Updates → Help) sends nothing until you press "Agree and continue" there; who agreed and when is recorded on your site. Then:

* **Ask the Zinn® AI** (`POST /v1/public/support/deflect`, when you press Ask): your question, your admin language and the name of this plugin. The answer and matching help articles come back. **That solved it** (`POST /v1/public/support/deflect/{id}/solved`) records only that the answer helped.
* **Send to Zinn® support** (when you press it): the kind of request, the subject, your message, your admin language, a reply e-mail address if you typed one, and only the diagnostics blocks you ticked (each shown in full on the screen first: versions, active theme, installed plugins, connection state; never the token). A connected store sends it with its token (`POST /v1/store-connect/support/tickets`); a store that is not connected sends it with your e-mail address (`POST /v1/plugin-support/tickets`).
* **My tickets** (`GET /v1/store-connect/support/tickets`, when a connected store opens Help, and at most every ten minutes for the menu badge): reads your tickets and the team's latest reply. **Send reply** (`POST /v1/store-connect/support/tickets/{reference}/replies`) sends your reply.

Withdraw your consent on the Help screen at any time; nothing is sent again until you agree again.

== Frequently Asked Questions ==

= Do I need a Zinn® account? =

Yes. Press Connect and you can create one on the way.

= Does the plugin send my customers' data anywhere yet? =

No. This version connects the store and stores each new order's language on the order, on your site. Nothing about your orders or customers is sent. A support ticket carries only what you type and the diagnostics blocks you tick.

== Changelog ==

= 0.2.1 =
* Help: the menu badge updates on the same page load as My tickets, and diagnostics keep the site address intact while still masking phone numbers.

= 0.2.0 =
* Help: ask the Zinn® AI, send a ticket with consent-gated diagnostics, follow replies in My tickets, with a menu badge.
* Detects Tranzly and Page Builder Sandwich, reports the store's languages, and stores each order's language at checkout.

= 0.1.0 =
* One-click connect to Zinn® Campaigns, connection status and disconnect.

== Upgrade Notice ==

= 0.2.0 =
Help inside the plugin, and each order remembers its shopper's language.

= 0.1.0 =
First release.
