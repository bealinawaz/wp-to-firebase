# WPW to Firebase

Sync WooCommerce products and orders and WordPress users to a Firebase Realtime Database, in the background and as they change.

[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/wpw-to-firebase)](https://wordpress.org/plugins/wpw-to-firebase/)
![Tested up to WordPress 7.1](https://img.shields.io/badge/WordPress-7.1-blue)
![PHP 7.4 to 8.5](https://img.shields.io/badge/PHP-7.4%E2%80%938.5-777bb4)

**Install from WordPress.org:** https://wordpress.org/plugins/wpw-to-firebase/

## What it syncs

| Data | Fields | Default |
| --- | --- | --- |
| WooCommerce products | Name, prices, stock, images, gallery, categories, tags, attributes, variations | On |
| WooCommerce orders | Status, totals, line items, shipping lines, billing and shipping details | Off (personal data) |
| WordPress users | Email, display name, first and last name, roles, registration date | Off (personal data) |

Passwords, password reset keys, and other credentials are never sent.

## How it works

- **Full sync:** click **Sync now**, or schedule an hourly, twice-daily, or daily sync. Items are sent in the background through WP-Cron, 50 at a time.
- **Live updates:** creating, updating, trashing, or deleting a product, order, or user reaches Firebase a few seconds later. Repeated saves of the same item are merged into one update.
- **Authentication:** a Google service account signs a JSON Web Token, which is exchanged for an OAuth access token that expires after an hour. The private key is never displayed after saving.
- **No work on page loads:** nothing runs during normal front-end requests.
- **WooCommerce HPOS:** compatible with High-Performance Order Storage and classic post storage.

## Data structure

Records are written under a configurable root path (default `wordpress`). Keys are prefixed by type, so Firebase never converts a collection of sequential IDs into an array:

```
wordpress/
  products/product_42
  orders/order_1057
  users/user_7
```

Each record also contains its WordPress ID in the `id` field.

## Setup

1. Install and activate the plugin.
2. In the [Firebase console](https://console.firebase.google.com/), create a Realtime Database and copy its URL.
3. Open **Project settings → Service accounts** and click **Generate new private key**.
4. In WordPress, go to **Settings → WPW to Firebase**, paste the database URL and the full contents of the key file, choose what to sync, and save.
5. Click **Test connection**, then **Sync now**.

## External services

The plugin sends data to Google services only after you configure it:

- **Google OAuth 2.0 token service** (`https://oauth2.googleapis.com/token`) exchanges your signed service account credentials for a short-lived access token.
- **Firebase Realtime Database** (your database URL) receives the products, orders, and users you choose to sync.

See the [Firebase terms](https://firebase.google.com/terms), [Google Cloud terms](https://cloud.google.com/terms), and [Google privacy policy](https://policies.google.com/privacy).

## Project structure

```
wpw-to-firebase.php                 Plugin header and bootstrap
includes/class-wpwtf-settings.php   Settings, validation, service account storage
includes/class-wpwtf-firebase.php   OAuth token and Realtime Database REST client
includes/class-wpwtf-exporter.php   Builds product, order, and user records
includes/class-wpwtf-sync.php       Background batches and live updates via WP-Cron
includes/class-wpwtf-admin.php      Settings screen, connection test, manual sync
uninstall.php                       Removes settings, credentials, and scheduled events
readme.txt                          WordPress.org readme
```

## Requirements

- WordPress 6.2 or later (tested up to 7.1)
- PHP 7.4 or later (tested up to 8.5)
- WooCommerce 7.1 or later for products and orders (tested up to 11.2)
- PHP OpenSSL extension

## License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
