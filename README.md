# WCS Price Sync with Renewal Buffer

**Plugin Name:** WCS Price Sync with Renewal Buffer  
**Stable tag:** 2.0  
**Requires at least:** WordPress 5.5, WooCommerce 8.0, WooCommerce Subscriptions 5.0  
**Tested up to:** WordPress 6.7, WooCommerce 9.5, WooCommerce Subscriptions 6.8  
**Requires PHP:** 7.4  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Automatically syncs subscription line item prices to the current product price **only when safe** — i.e. only when there is still enough time before the next renewal (earlier than the period defined by the built-in Renewal timing setting).

Prevents price changes from applying after renewal reminder emails have already been sent (or are about to be sent), avoiding customer confusion and support tickets.

## Why this plugin?

WooCommerce Subscriptions does **not** automatically update existing subscription prices when you change a product's price.

Many store owners want existing subscriptions to gradually move to new pricing — but **not** if the renewal is imminent and a reminder has already gone out (or is about to).

This plugin adds that safety buffer logic.

## Features

- When you update & save a subscription product → automatically updates **active** subscriptions using that product
- Skips update if the next payment date is within the buffer window (e.g. 7 days)
- Respects your existing **renewal reminder timing** (set buffer = your reminder days)
- Includes a **bulk action** in Subscriptions list table: "Sync Prices (with Buffer)"
- Adds order note when price is updated
- Lightweight — no cron jobs, no extra tables
- Safe double-save prevention (transient lock)

## Installation

1. Download the plugin ZIP
2. In WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin
4. Go to **WooCommerce → Settings → Subscriptions**
5. Set **Price Update Buffer Days** to match your renewal reminder timing (e.g. `7` if reminders go out 7 days before renewal)

Done.

## Screenshots

(You can add 2–4 screenshots later: settings field, bulk action dropdown, subscription note example, product edit page)

1. Buffer setting in Subscriptions settings
2. Bulk sync action in subscriptions list
3. Example order note after sync

## Changelog

### 1.0 – 2026-01-xx
* Initial public release
* Automatic price sync on product save (with buffer check)
* Bulk manual sync action
* Admin notices for missing dependencies
* Order notes on update

## Frequently Asked Questions

**Does it work with variable subscriptions?**  
Currently only simple subscription products are fully supported (pulls `$product->get_price()`). Variable support would require choosing which variation — PRs welcome.


**Why isn't the price updating even when outside buffer?**  
Make sure:
- The product is marked as a **Simple** Subscription product
- The subscription is **active**
- The subscription contains that exact product ID
- You're editing and **saving** the product (not just changing price in quick edit)

**I want to force-update even inside buffer — can I?**  
Not currently.

## Contributing

Found a bug? Have an idea?  
→ Issues and Pull Requests welcome.

## Author

**Frey Mansikkaniemi / Invite Services**  
https://invite.hk

## License

GPLv2 or later — same as WooCommerce & WordPress.