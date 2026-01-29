# Kaspa Payments Gateway for WooCommerce

Accept **Kaspa (KAS)** cryptocurrency payments in WooCommerce with automatic order confirmation and real-time verification. Built with security and simplicity in mind, using KPUB (Extended Public Key) watch-only wallets for non-custodial payment processing.

**Note:** This plugin is not officially affiliated with, endorsed by, or connected to Kaspa, WooCommerce, or their respective owners.

## Features

- **KPUB watch-only wallet** — No private keys stored; secure, non-custodial payments
- **Automatic payment detection** — Real-time monitoring via Kaspa API
- **Unique address per order** — Dedicated payment address for each order
- **Real-time exchange rates** — USD → KAS via CoinGecko API
- **QR code support** — Easy scanning at checkout
- **Classic & block checkout** — Works with both WooCommerce checkout styles

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- A Kaspa wallet (e.g. [Kaspium](https://kaspium.io)) with KPUB export

## Installation

1. **From source (this repo)**  
   The plugin code lives in the `trunk/` directory. Either:
   - Download a [release](https://github.com/jacoborbach/kaspa-plugin-svn/releases) and upload the zip via **Plugins → Add New → Upload**, or  
   - Copy the contents of `trunk/` into `wp-content/plugins/kaspa-payments-gateway-woocommerce/`.

2. Activate the plugin under **Plugins** in WordPress.

3. Configure under **WooCommerce → Settings → Payments** and **Kaspa → Wallet Setup** (import your KPUB from Kaspium).

Full setup and FAQ: see [readme.txt](trunk/readme.txt) in `trunk/`.

## Repository layout

- **`trunk/`** — Current plugin source (use this for development and installation).
- **`tags/`** — Versioned releases (e.g. `1.0.3`) for reference and packaging.

## License

GPL v2 or later. See [trunk/LICENSE](trunk/LICENSE).

## Links

- [Plugin site](https://kaspawoo.com/)
- [Author](https://github.com/jacoborbach)
