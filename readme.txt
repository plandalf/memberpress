=== Plandalf for MemberPress ===
Contributors: plandalf
Tags: memberpress, checkout, memberships, order bumps, subscriptions
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell MemberPress memberships through Plandalf checkout. MemberPress keeps members and access; Plandalf takes the payment.

== Description ==

Early-access source release. The default hosted Plandalf connection endpoint is not yet available. Evaluate with a compatible development server; do not replace a live membership checkout until hosted setup and buyer access have been verified.

Plandalf for MemberPress puts your Plandalf checkout where the MemberPress signup form used to be. Buyers pay on Plandalf, with your order bumps, discount codes and checkout design. The moment Plandalf confirms the payment, the plugin gives them the membership in MemberPress.

* **MemberPress keeps** members, memberships, access rules, protected content, groups, emails and the account page.
* **Plandalf runs** the checkout, prices, discounts, order bumps, tax, invoices, payments and renewals.
* **Any number of Plandalf prices can grant one membership:** monthly, yearly or a launch price.
* **New buyers pay first** and choose their password afterwards. Logged-in members are recognised automatically.
* **Every payment is recorded in MemberPress** as a normal transaction, numbered with its Plandalf invoice.

Setup guide: https://plandalf.com/docs/product-guides/platforms/memberpress

= Needs =

* MemberPress 1.12 or later, active.
* A Plandalf account with Stripe connected.
* Your site on HTTPS, so Plandalf can send it purchase events.

= External service =

This plugin connects your site to Plandalf (https://plandalf.com), which runs the checkout and takes payments.

* **When you connect,** your site address is sent to Plandalf and Plandalf returns an API key for this site. The plugin then registers `/wp-json/plandalf/v1/events` so Plandalf can send it signed purchase events.
* **When you manage memberships,** the plugin reads your Plandalf checkouts and prices, creates prices from memberships, and stores which prices grant which memberships.
* **On membership pages,** the plugin loads the Plandalf checkout script from Plandalf. For logged-in members it passes a signed token with their WordPress user ID, email and name so the purchase goes onto their account.
* **Once a day,** it asks Plandalf for the state of subscriptions that are about to renew.
* **When a member cancels** from the MemberPress account page, it asks Plandalf to cancel the subscription.

Plandalf terms: https://plandalf.com/terms-of-service
Plandalf privacy policy: https://plandalf.com/privacy-policy

== Installation ==

1. Upload `plandalf-memberpress.zip` in **Plugins → Add New → Upload Plugin** and activate it.
2. Open **MemberPress → Plandalf** and click **Connect with Plandalf**.
3. Choose your checkout design, then create or link a Plandalf price for each membership.

== Frequently Asked Questions ==

= Do I have to change my MemberPress rules or content? =

No. The plugin only replaces the signup form and records purchases as MemberPress transactions and subscriptions.

= What happens to members who already pay through a MemberPress gateway? =

They keep billing as before. Plandalf handles new purchases, so both can run side by side.

= Do MemberPress coupons work? =

No. Create discounts in Plandalf, where they can also carry a deadline, a usage limit or a minimum order.

= What does deleting the plugin remove? =

Its settings, event log and schedule, and this site's event endpoint in Plandalf. Members, transactions and subscriptions in MemberPress stay.

== Changelog ==

= 0.1.0 =
* Early access release: connect, checkout design, price links with drift, embedded, popup and full-screen checkout, signed purchase events, guest accounts with first-password setup, renewals, cancellations, refunds and a daily check.
