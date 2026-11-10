=== Zinn® Order Updates ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-order-updates
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: woocommerce, sms, whatsapp, order notifications, order status
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.8
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
* **A sign-up form on any page.** Add the "Zinn® SMS and WhatsApp sign-up" block (in the Zinn Digital® category of the block inserter, also inside Page Builder Sandwich) or the `[zinn_ou_opt_in channel="sms"]` shortcode (`sms`, `whatsapp` or `both`). Visitors give their mobile number and tick an unticked box to get offers and news; it is a marketing opt-in only and never signs anyone up for order updates. With Page Builder Sandwich Pro forms, add the "Zinn® SMS offers consent" or "Zinn® WhatsApp offers consent" field next to a phone field and a ticked box records the same opt-in.
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

Order updates by SMS: checkout shows one unticked box ("Send me order updates by SMS to my phone number"), using the billing phone. Nothing about an order is sent unless the shopper ticks it. For an order where they did:

* **Consent** (`POST /v1/store-connect/consents`, once, before the order's first update): the billing phone, the wording shown, its version and language, and when the box was ticked. It is the proof of the shopper's consent.
* **Order event** (`POST /v1/store-connect/events`, when the order's status changes to processing, on hold, completed, cancelled, refunded or failed): the order number, the new status, the shopper's first name, billing phone and checkout language, the store name, the order total and currency (and the refunded amount, or the payment link for a failed payment). Zinn® Campaigns sends the text in the shopper's language; Telnyx delivers it as our sub-processor.

Sign-up form (the block, the shortcode, or our consent field in a Page Builder Sandwich Pro form): nothing is sent unless a visitor types a mobile number and ticks the box. Then:

* **Consent** (`POST /v1/store-connect/consents`, once, when they send the form): the mobile number, the channel (SMS or WhatsApp), the wording shown, its version and language, the page it was on, and when. It is the proof of their marketing consent. The visitor's IP address is never sent; a one-way hash of it is kept on your site for ten minutes to stop repeated sign-ups.

My Account "Messages" tab (and the "Zinn® message settings" block): only for a signed-in shopper, and only for a number that is on one of their own orders (any other number shows nothing and cannot be changed; only texts about their own orders are listed). Then:

* **Shopper read** (`POST /v1/store-connect/shopper`, when the panel opens): that shopper's phone number. The answer is their consent settings for your store and the last 20 texts your store sent them; nothing from any other store is shown.
* **Consent** (`POST /v1/store-connect/consents`, when they save): only the settings they changed, with the wording shown and its language. Stop all messages withdraws every one. Changing the number withdraws the old number's consents.

Help (Order Updates → Help) sends nothing until you press "Agree and continue" there; who agreed and when is recorded on your site. Then:

* **Ask the Zinn® AI** (`POST /v1/public/support/deflect`, when you press Ask): your question, your admin language and the name of this plugin. The answer and matching help articles come back. **That solved it** (`POST /v1/public/support/deflect/{id}/solved`) records only that the answer helped.
* **Send to Zinn® support** (when you press it): the kind of request, the subject, your message, your admin language, a reply e-mail address if you typed one, and only the diagnostics blocks you ticked (each shown in full on the screen first: versions, active theme, installed plugins, connection state; never the token). A connected store sends it with its token (`POST /v1/store-connect/support/tickets`); a store that is not connected sends it with your e-mail address (`POST /v1/plugin-support/tickets`).
* **My tickets** (`GET /v1/store-connect/support/tickets`, when a connected store opens Help, and at most every ten minutes for the menu badge): reads your tickets and the team's latest reply. **Send reply** (`POST /v1/store-connect/support/tickets/{reference}/replies`) sends your reply.

Withdraw your consent on the Help screen at any time; nothing is sent again until you agree again.

== Frequently Asked Questions ==

= Do I need a Zinn® account? =

Yes. Press Connect and you can create one on the way.

= Does the plugin send my customers' data anywhere? =

Only for a shopper who ticks the "Send me order updates by SMS" box at checkout, and only once the store is connected: their consent, then each status change of that order (first name, billing phone, language, order number, status, total and the link to their order), so Zinn® Campaigns can text them. Orders without the tick send nothing. A support ticket carries only what you type and the diagnostics blocks you tick.

== Changelog ==

= 0.2.8 =
* My Account "Messages" tab: its web address is now in your store's own language for every shopper, so on a multilingual store a shopper browsing in another language no longer gets "page not found" from the tab.

= 0.2.7 =
* My Account "Messages" tab: a signed-in shopper sees the number your store texts, switches order updates by SMS, offers by SMS and offers on WhatsApp on or off, can stop every message at once, and sees the last 20 texts your store sent them. Only a setting they change is recorded, so the consent they gave at checkout stays as proof. Changing the number moves their choices to the new one.
* "Zinn® message settings" block: the same panel on any page you choose.

= 0.2.6 =
* WhatsApp button block: a "Chat on WhatsApp" button that links your store's own WhatsApp Business number, shown only when one is connected; it can hide itself from shoppers who already get order updates. Page Builder Sandwich Pro pages can read "opted in", the channel and the WhatsApp link as dynamic data.
* Order updates by SMS: one checkout now sends one text (a failed payment is still texted, so the shopper can pay again); the order link is added only when it is short and on your own site, and the text names your store rather than its web address.

= 0.2.5 =
* Sign-up form on any page: the Zinn® SMS and WhatsApp sign-up block (Zinn Digital® category, also in Page Builder Sandwich) and the [zinn_ou_opt_in] shortcode record a marketing opt-in in Zinn® Campaigns; with Page Builder Sandwich Pro forms, our consent field does the same. Unticked by default, marketing only.

= 0.2.4 =
* Order updates by SMS: the order-received and order-complete texts end with a link to the shopper's order, and the pay-again link opens in the language they bought in (Tranzly, WPML, Polylang or TranslatePress). A guest's link opens their order without a login.

= 0.2.3 =
* Order updates by SMS: an unticked checkout box (classic and block checkout); when a shopper ticks it, each status change is texted to them in their language by Zinn® Campaigns. Orders without the tick send nothing.

= 0.2.2 =
* The store's languages (Tranzly, WPML or Polylang) now reach Zinn® Campaigns on every heartbeat, all of them, not only the first 40 characters.

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
