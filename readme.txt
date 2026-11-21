=== ForgeHub Customer Portal ===
Contributors: wpforgehub
Tags: woocommerce, customer portal, downloads, magic link, passwordless
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern, high-performance customer dashboard for digital products with passwordless magic link login, downloads manager, and license keys.

== Description ==

Transform WooCommerce's outdated default "My Account" area into a sleek, modern, high-converting **Customer Portal**. Built specifically for digital stores, software vendors, theme/plugin creators, and course creators.

### ⚡ Key Features (Free Edition)
* **Modern SaaS Portal UI:** Replaces clunky default WooCommerce tables with a responsive, high-contrast dashboard.
* **Passwordless Magic Link Login:** Allows customers to log in with 1-click via a secure email magic link (eliminating forgotten password friction).
* **Direct Downloads Manager:** Clean `.zip` downloads tab displaying version numbers, file sizes, and instant download buttons.
* **Orders & Purchases History:** Clean view of customer orders and fulfillment statuses.
* **Profile & Password Management:** Customers can easily update their name, email, and set/reset their password.
* **Instant Post-Checkout Redirection:** Automatically routes buyers straight to their downloads upon order completion.

### 🚀 Pro Features
* **Priority Support Desk Engine:** Complete database-backed customer support ticketing with status tracking, threaded replies, and screenshot attachments.
* **Domain License Manager & Remote Deactivation:** View active site activations per license key and remotely deactivate staging or retired domains.
* **Automated PDF & Printable Invoices:** 1-click compliant tax invoices with EU VAT numbers, breakdowns, and printable PDF layouts.
* **Custom Brand Themes:** Match your brand's accent colors and toggle between Dark Mode and Clean White.

== Installation ==

1. Upload the `wpfh-customer-portal` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your preferences under **WooCommerce -> Customer Portal**.

== Changelog ==

= 1.1.4 =
* Fix Magic Link nonce & rate-limit, remove hardcoded branding in Pro PDF/tickets, preserve 3rd-party My Account tabs, harden Pro Domain Manager


= 1.1.3 =
* Rename: plugin is now "ForgeHub Customer Portal" (ForgeHub naming standard).
* Fix: Plugins screen now shows a consistent "Settings" link.
= 1.1.0 =
* Feature (Pro): Priority Support Desk system with threaded replies, priority levels, and secure attachments.
* Feature (Pro): Remote Domain License Delegation and 1-click seat revocation.
* Feature (Pro): Automated compliant PDF & printable Invoice generator with EU VAT breakdown.
* Enhancement: Standardized Freemius license hook filters (`fs_can_use_premium_code__wpfh-customer-portal`).

= 1.0.0 =
* Initial public release with Passwordless Magic Link Login, Modern Customer Portal tabs, and Instant Post-Checkout Download Redirection.
