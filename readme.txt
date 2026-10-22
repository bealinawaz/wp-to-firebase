=== WP to Firebase – Sync WooCommerce & WordPress Data ===
Contributors: imalinawaz
Donate link: https://www.buymeacoffee.com/bealinawaz
Tags: firebase, woocommerce, realtime database, sync, mobile app
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products and orders and WordPress users to a Firebase Realtime Database, in the background and as they change.

== Description ==

WP to Firebase keeps a Firebase Realtime Database in step with your WordPress site. Use it to power a mobile app, a dashboard, or any service that reads from Firebase, without writing your own export code.

**What it syncs**

* **WooCommerce products:** name, prices, stock, images, categories, tags, attributes, and variations.
* **WooCommerce orders:** status, totals, line items, shipping lines, and billing and shipping details.
* **WordPress users:** email, display name, first and last name, roles, and registration date.

You choose which of these to sync. Products are on by default. Users and orders contain personal data, so they stay off until you turn them on.

**How it works**

* **Full sync:** click "Sync now", or schedule an hourly, twice-daily, or daily sync. Items are sent in the background, 50 at a time, so your site stays fast.
* **Live updates:** when a product, order, or user is created, updated, or deleted, the change reaches Firebase a few seconds later.
* **Secure authentication:** the plugin signs in with a Google service account and short-lived access tokens. Your private key is never shown again after you save it.
* **Safe by design:** passwords, password reset keys, and other credentials are never sent. Nothing runs during normal page loads.
* **Compatible with WooCommerce High-Performance Order Storage (HPOS).**

**Data structure**

Records are written under the path you choose (default `wordpress`), with keys prefixed by type:

`wordpress/products/product_42`
`wordpress/orders/order_1057`
`wordpress/users/user_7`

**Custom development**

Need extra fields, Cloud Firestore, a different data structure, or a mobile app built on this data? The plugin is built and maintained by Ali Nawaz, a WordPress and full stack developer. [Get in touch for custom work](https://bealinawaz.com/contact-me/?utm_source=wordpress.org&utm_medium=plugin-readme&utm_campaign=wpw-to-firebase).

== External services ==

This plugin connects to two Google services to write your data to Firebase. Nothing is sent until you add a database URL and a service account key and enable at least one type of data.

**Google OAuth 2.0 token service** (`https://oauth2.googleapis.com/token`)
Used to exchange your service account credentials for a short-lived access token. The plugin sends a request signed with your service account's private key, containing the service account email and the requested permissions. This happens when a sync runs and no valid token is cached, at most about once an hour.

**Firebase Realtime Database** (your database URL, ending in `firebaseio.com` or `firebasedatabase.app`)
Receives the data you choose to sync: product details, order details including customer names, email addresses, phone numbers, and billing and shipping addresses, and user details including email addresses, names, and roles. Data is sent during a full sync, on your chosen schedule, and a few seconds after an item changes if live updates are on.

Both services are provided by Google.
Firebase terms of service: https://firebase.google.com/terms
Google Cloud terms of service: https://cloud.google.com/terms
Google privacy policy: https://policies.google.com/privacy

== Installation ==

1. Install and activate the plugin.
2. In the [Firebase console](https://console.firebase.google.com/), create a Realtime Database and copy its URL.
3. In the Firebase console, open **Project settings → Service accounts** and click **Generate new private key**. A JSON file downloads.
4. In WordPress, go to **Settings → WP to Firebase**. Paste the database URL and the full contents of the JSON file, choose what to sync, and save.
5. Click **Test connection**, then **Sync now**.

== Frequently Asked Questions ==

= Is it safe to store the service account key in WordPress? =

The key is stored in your WordPress database, like other plugin credentials, and is never displayed after saving. Anyone with access to your database could read it, so keep your site and database secure. You can remove the key at any time from the settings page, and you can revoke it in the Google Cloud console.

= Does the plugin send passwords? =

No. Passwords, password reset keys, and other credentials are never sent. User records contain only email, names, roles, and registration date.

= Why did the full sync not finish straight away? =

The sync runs through WP-Cron, which runs when your site receives visits. On a quiet site, or a site with WP-Cron disabled, it continues as soon as cron runs. For large stores, configure a real server cron job for WP-Cron.

= Does deleting an item in WordPress remove it from Firebase? =

Yes, when live updates are on. Trashing or deleting a product, order, or user removes its record from Firebase a few seconds later.

= Why are keys prefixed with product_, order_, and user_? =

Firebase treats objects with sequential numeric keys as arrays, which makes data harder to read and update. The prefix keeps every record addressable by its key. Each record also includes its WordPress ID in the `id` field.

= Does it work without WooCommerce? =

Yes, for users. Product and order syncing requires WooCommerce.

= Can you customize the plugin for my project? =

Yes. For custom fields, other Firebase services, or a full integration with your app, [contact Ali Nawaz](https://bealinawaz.com/contact-me/?utm_source=wordpress.org&utm_medium=plugin-readme&utm_campaign=wpw-to-firebase).

== Changelog ==

= 2.0.1 =
* Renamed the plugin to WP to Firebase. Settings are unchanged.
* Added a plugin icon and banner.

= 2.0.0 =
* Rebuilt from the ground up.
* New: full sync in the background, in batches of 50, with an optional schedule.
* New: live updates when products, orders, and users change or are deleted.
* New: choose which data to sync. Users and orders are off by default.
* New: secure service account authentication with short-lived access tokens.
* New: product variations, gallery images, and order line items.
* New: support for WooCommerce High-Performance Order Storage.
* Security: passwords and password reset keys are never sent.
* Performance: nothing runs during normal page loads.

= 1.0 =
* Initial version.

== Upgrade Notice ==

= 2.0.1 =
The plugin is now called WP to Firebase. No action needed; your settings are kept.

= 2.0.0 =
Complete rebuild with background syncing, live updates, and secure service account authentication. Add a service account key in Settings → WP to Firebase after updating.
