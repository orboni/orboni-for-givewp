=== Orboni for GiveWP ===
Contributors: orboni
Tags: crypto, cryptocurrency, donations, usdt, bitcoin
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take donations in USDT, USDC, BTC, ETH and other coins, paid straight to your own wallets. Non-custodial: Orboni never holds your money.

== Description ==

Orboni adds "Crypto" to your GiveWP donation forms. The donor presses Donate, picks a coin on Orboni's payment page and pays from any wallet or exchange. The money goes straight to the wallet addresses you set in your Orboni dashboard; Orboni never receives, holds or forwards it. When the payment is final on the chain, the donation is completed by itself and GiveWP sends its receipt.

**What donors can give with**

* **USDT** on TRON, Ethereum, BNB Chain, Solana, TON, Polygon, Arbitrum, Optimism and Avalanche
* **USDC** on Ethereum, Base, Solana, Polygon, Arbitrum, Optimism, Avalanche, BNB Chain and Stellar
* **DAI** and **PYUSD**
* **BTC**, **ETH**, **BNB**, **SOL**, **TRX**, **POL**, **AVAX**, **LTC**, **DOGE**, **BCH**, **XRP**, **XLM** and TON's **GRAM**

The payment page offers only the coins you have set a wallet for in the Orboni dashboard.

**How it works**

* The donor picks "Crypto" on the form and is sent to Orboni's payment page, in their language, with the donation's amount in your site's currency.
* The page shows the exact amount in the chosen coin, an address and a QR code, and follows the transfer on the chain.
* Orboni tells your site with a signed webhook, and the donation moves on: Processing while the payment confirms, then Complete once it is final.
* Without a webhook the plugin still reads the payment back when the donor returns to your site, and checks again by itself a few minutes later.
* A donor who presses "Cancel and go back" on the payment page is back on your form, the page is closed and the donation cancelled.

**Built for real donations**

* Underpaid, overpaid, late and reversed payments are written on the donation as notes, and the donation waits in Processing for you instead of being counted by mistake.
* GiveWP's test mode uses your Orboni test key: try the whole flow, simulate a payment, and switch test mode off to take real donations.
* Works with the visual form builder's forms and with GiveWP's option-based forms.

Your site can take donations in US dollars, euros, pounds, Swiss francs, złoty, Czech koruna, forints, lei, Danish, Swedish and Norwegian kroner, Icelandic krónur, lira, yen, won, yuan, Hong Kong and Singapore dollars, rupees, rupiah, ringgit, pesos (Philippine and Mexican), baht, shekels, Australian, New Zealand and Canadian dollars, reais, rand, hryvnias, Argentine and Chilean pesos, dirhams, riyals, Taiwan dollars and đồng. Orboni turns the amount into dollars at the rate of the day (the European Central Bank's, or the market's for a currency the ECB does not publish), and the donor pays that in the coin they pick. A payment is worth at least $1.

One-time donations only: a coin cannot be charged again by itself, so GiveWP takes recurring donations with your other gateways.

== Installation ==

1. Install and activate the plugin (GiveWP 3.0 or later must be active).
2. Sign up at [orboni.com](https://orboni.com), add a project and set the wallet addresses you want to be paid to.
3. In the Orboni dashboard, under Developers → API keys, copy the test secret key (`sk_test_…`) and the live one (`sk_live_…`).
4. In WordPress, go to Donations → Settings → Payment Gateways → Orboni and paste them. Then tick "Orboni (crypto)" under Donations → Settings → Payment Gateways → Gateways, for the visual builder's forms and the option-based ones.
5. In the Orboni dashboard, under Developers → Webhooks, add an endpoint with the address shown on the plugin's settings (`https://your-site.example/wp-json/orboni-givewp/v1/webhook`), once in test mode and once live, and paste each signing secret (`whsec_…`) into its field.
6. With GiveWP's test mode on, give a test donation. Once it works, switch test mode off.

== Frequently Asked Questions ==

= Does Orboni hold the money? =

No. Donors pay to the wallet addresses you set in your dashboard, and the money is yours from the moment it is on the chain. Orboni watches the chain and tells your site; it never has your keys or your coins.

= My site is not in US dollars. =

Orboni takes 37 currencies, listed above: the payment page shows the donation in your site's currency, and the donor pays its worth at the rate of the day. With a currency outside the list crypto is not offered on your forms, and the settings page says so.

= What happens if a donor sends too little or too much? =

Too little: the payment page asks for the rest, and the donation waits in Processing with a note. If the page expires part paid, change the donation's amount to what arrived and complete it, or send the money back. Too much: the donation is completed, and a note says how much arrived in all.

= Can donors give every month? =

Not with crypto: a coin payment is made by the donor each time, so GiveWP takes recurring donations with your other gateways.

= How do refunds work? =

The money is in your wallet, so you send a refund from there, then mark the donation Refunded in GiveWP and record the refund in the Orboni dashboard.

= Where are the logs? =

Turn on "Debug log" in the plugin's settings, then look under Donations → Tools → Logs for the source "orboni". Errors are logged even with it off. Keys and signing secrets are never logged.

= Can I use one Orboni project for several sites? =

Yes. Each site adds its own webhook endpoint, and each one acts only on its own donations.

== External services ==

This plugin connects to Orboni (https://orboni.com), the payment service it exists for. Without it the plugin does nothing.

* **When a donor presses Donate with "Crypto"**, the plugin sends Orboni the donation's amount and currency, the form's title, the donation's number, your site's name, the donor's email address, the donor's language, the addresses to return the donor to, the donation's and the form's ids and a short hash of your site's address (to tell your sites apart). Orboni uses them to draw the payment page and to send the donor a receipt.
* **When the donor returns or cancels, when a webhook arrives, and in a few scheduled checks after the payment page is opened and after it expires**, the plugin asks Orboni for the payment's status by its session id; when the donor cancels with nothing paid, it asks Orboni to close the page.
* **Orboni sends your site webhooks** about the payment to `/wp-json/orboni-givewp/v1/webhook`, signed with your endpoint's secret.
* The donor pays on Orboni's own payment page at `https://orboni.com/pay/…`.

Every request carries your Orboni secret key. Orboni's [Terms of Service](https://orboni.com/page/terms-of-service) and [Privacy Policy](https://orboni.com/page/privacy-policy) apply.

== Screenshots ==

1. "Crypto" on a donation form.
2. Orboni's payment page: the coin, the amount, the address and its QR code.
3. The plugin's settings in Donations → Settings → Payment Gateways → Orboni.
4. A donation paid with crypto, with Orboni's notes.

== Changelog ==

= 1.0.0 =
* First release: donations in 37 currencies, GiveWP's test mode with Orboni's test key, signed webhooks, read-backs without a webhook.
