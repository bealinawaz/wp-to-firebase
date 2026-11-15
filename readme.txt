=== WP to Firebase – Sync WooCommerce & WordPress to Firestore and Realtime Database ===
Contributors: imalinawaz
Donate link: https://www.buymeacoffee.com/bealinawaz
Tags: firebase, firestore, woocommerce, mobile app, sync
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products and orders and WordPress users to Cloud Firestore or a Firebase Realtime Database, in the background and as they change.

== Description ==

WP to Firebase keeps Cloud Firestore or a Firebase Realtime Database in step with your WordPress site. Use it to power a mobile app built with Flutter, FlutterFlow, or React Native, a dashboard, or any service that reads from Firebase, without writing your own export code.

**New in 2.1: Cloud Firestore, free.** Firebase recommends Cloud Firestore for new apps, and FlutterFlow works with Firestore. Each product, order, and user becomes a document in a `products`, `orders`, or `users` collection. Items that have not changed since the last sync are skipped, so scheduled syncs stay inside Firestore's free daily write quota.

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

In Cloud Firestore, each item is a document in its own collection, with an optional collection prefix:

`products/product_42`
`orders/order_1057`
`users/user_7`

Numbers, true/false values, lists, and nested details such as billing addresses and line items are stored with their proper Firestore types, so you can query and sort them.

In the Realtime Database, records are written under the path you choose (default `wordpress`), with keys prefixed by type:

`wordpress/products/product_42`
`wordpress/orders/order_1057`
`wordpress/users/user_7`

**Custom development**

Need extra fields, posts and custom post types, push notifications, a different data structure, or a mobile app built on this data? The plugin is built and maintained by Ali Nawaz, a WordPress & Shopify developer who builds [custom WordPress plugins](https://bealinawaz.com/services/wordpress-plugin-development/?utm_source=wordpress.org&utm_medium=plugin-readme&utm_campaign=wpw-to-firebase).

== External services ==

This plugin connects to Google services to write your data to Firebase. Nothing is sent until you add a service account key (and, for the Realtime Database, a database URL) and enable at least one type of data.

**Google OAuth 2.0 token service** (`https://oauth2.googleapis.com/token`)
Used to exchange your service account credentials for a short-lived access token. The plugin sends a request signed with your service account's private key, containing the service account email and the requested permissions. This happens when a sync runs and no valid token is cached, at most about once an hour.

**Cloud Firestore** (`https://firestore.googleapis.com`)
Used when Cloud Firestore is chosen as the database. Receives the same data described below for the Realtime Database, as documents in your project's Firestore database. Data is sent during a full sync, on your chosen schedule, and a few seconds after an item changes if live updates are on. "Test connection" reads at most one document.

**Firebase Realtime Database** (your database URL, ending in `firebaseio.com` or `firebasedatabase.app`)
Receives the data you choose to sync: product details, order details including customer names, email addresses, phone numbers, and billing and shipping addresses, and user details including email addresses, names, and roles. Data is sent during a full sync, on your chosen schedule, and a few seconds after an item changes if live updates are on.

These services are provided by Google.
Firebase terms of service: https://firebase.google.com/terms
Google Cloud terms of service: https://cloud.google.com/terms
Google privacy policy: https://policies.google.com/privacy

== Installation ==

1. Install and activate the plugin.
2. In the [Firebase console](https://console.firebase.google.com/), create a Cloud Firestore database, or a Realtime Database and copy its URL.
3. In the Firebase console, open **Project settings → Service accounts** and click **Generate new private key**. A JSON file downloads.
4. In WordPress, go to **Settings → WP to Firebase**. Choose Cloud Firestore or Realtime Database, paste the full contents of the JSON file (and the database URL for the Realtime Database), choose what to sync, and save.
5. Click **Test connection**, then **Sync now**.

== Frequently Asked Questions ==

= Should I use Cloud Firestore or the Realtime Database? =

For a new app, Cloud Firestore. Firebase recommends it for new projects, it has richer queries, and FlutterFlow works with it. Choose the Realtime Database if your app already reads from one. You can switch at any time; the next full sync writes everything to the new database.

= Will syncing use up my Firestore free quota? =

Firestore's free tier allows 20,000 document writes a day. The plugin skips items that have not changed since they were last written, so after the first sync, a scheduled full sync only writes what changed. Live updates write one document per change. A daily schedule is enough for most stores, with live updates on.

= Can I use a named Firestore database or a prefix for the collections? =

Yes. Enter the database ID under Firestore database ID (leave `(default)` for the default database), and an optional collection prefix such as `wp_` to write to `wp_products`, `wp_orders`, and `wp_users`.

= Is it safe to store the service account key in WordPress? =

The key is stored in your WordPress database, like other plugin credentials, and is never displayed after saving. Anyone with access to your database could read it, so keep your site and database secure. You can remove the key at any time from the settings page, and you can revoke it in the Google Cloud console.

= Does the plugin send passwords? =

No. Passwords, password reset keys, and other credentials are never sent. User records contain only email, names, roles, and registration date.

= Why did the full sync not finish straight away? =

The sync runs through WP-Cron, which runs when your site receives visits. On a quiet site, or a site with WP-Cron disabled, it continues as soon as cron runs. For large stores, configure a real server cron job for WP-Cron.

= Does deleting an item in WordPress remove it from Firebase? =

Yes, when live updates are on. Trashing or deleting a product, order, or user removes its record from Firebase a few seconds later.

= Why are keys prefixed with product_, order_, and user_? =

The Realtime Database treats objects with sequential numeric keys as arrays, which makes data harder to read and update. The prefix keeps every record addressable by its key, and the same document IDs are used in Cloud Firestore. Each record also includes its WordPress ID in the `id` field.

= Does it work without WooCommerce? =

Yes, for users. Product and order syncing requires WooCommerce.

= Can you customize the plugin for my project? =

Yes. Custom fields, other Firebase services and full integrations with your app are available as paid custom work; see Custom development above.

= Is the plugin available in my language? =

The plugin is ready for translation, and translations are made by volunteers on WordPress.org. You can help translate it into your language at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wpw-to-firebase/). Once a language is 90% translated, WordPress installs the translation automatically through language packs. A translation template, languages/wpw-to-firebase.pot, is included for tools such as Poedit and Loco Translate.

== Screenshots ==

1. Settings → WP to Firebase: choose Cloud Firestore or the Realtime Database, choose users, products or orders, turn on live updates and run a full sync.

== Changelog ==

= 2.2.0 (2026-11-15) =
* Developer: the `wpwtf_record` filter changes a user, product, or order record before it is written to Firebase. It receives the record, the data type, and the WordPress object. Nothing changes unless code uses the filter.

= 2.1.1 (2026-11-15) =
* Translations: a translation template (languages/wpw-to-firebase.pot) is included, and the readme explains how to translate the plugin on translate.wordpress.org.

= 2.1.0 (2026-11-15) =
* New: Cloud Firestore support. Choose Cloud Firestore or the Realtime Database under Settings. Products, orders, and users become documents in their own collections, with proper Firestore types for numbers, lists, and nested details.
* New: unchanged items are skipped during a Firestore full sync, to stay inside the free write quota. The sync status shows how many were skipped.
* New: named Firestore databases and an optional collection prefix.
* New: the `wpwtf_firestore_api_base` filter, for the Firestore emulator in development.

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

= 2.2.0 =
Adds a developer filter for changing records before they are sent. Nothing to change.

= 2.1.1 =
Translation and right-to-left language improvements. Nothing to change.

= 2.1.0 =
Adds Cloud Firestore as a database option. Realtime Database sites keep working as before; nothing to change.

= 2.0.1 =
The plugin is now called WP to Firebase. No action needed; your settings are kept.

= 2.0.0 =
Complete rebuild with background syncing, live updates, and secure service account authentication. Add a service account key in Settings → WP to Firebase after updating.
