=== Nera – Spending Limit ===
Contributors: Nera
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.0
Stable tag: 1.0.6
License: GPLv2 or later

Voluntary, per-customer spending limits for WooCommerce, with TeraWallet awareness.

== Description ==

Lets logged-in customers set a voluntary cap on how much they spend over a period
(daily, weekly, monthly, yearly, or custom dates/weeks/months/years). Admins enable
and configure the feature; the limit is surfaced and enforced at checkout.

= Admin (CMS) =
Theme Settings → **Nera Features** → **Spend Limit**:

* **Enable Spend Limit** – master on/off switch.
* **Limit Types** – which types customers may choose (daily/weekly/monthly/yearly/custom).
* **Default Limit Type** – the type pre-selected on the frontend.
* **Max Limit Amount** – the maximum a customer may set (default 10000); also used as
  the amount-slider maximum when no wallet plugin is active.

= Customer (Account Details) =
When enabled, a **Spending limit** card appears on the Account Details page with:

* an **Amount limit** slider (1 → wallet balance when TeraWallet is active, otherwise
  the CMS "Max Limit Amount"),
* a **Limit type** dropdown,
* for **Custom**: a sub-type (day/week/month/year) and a calendar to pick the specific
  periods. Each saved period shows as a chip with a remove (×) button that asks for
  confirmation before removing.

= Checkout =
A status card shows the limit, spent-this-period, remaining, the current order amount,
and (when present) the wallet balance. Enforcement:

* **Within limit** – no interference.
* **Over limit, paying by cash/gateway** – the customer is asked to confirm before the
  order can be placed.
* **Over limit, TeraWallet active** – allowed only if the wallet balance covers the
  order; otherwise the Place order button is disabled.

"Spent so far" = the sum of the customer's WooCommerce order totals with paid statuses
(processing, completed, on-hold) whose date falls in the active period window
(calendar-anchored). The server is always the authoritative enforcer.

== Testing ==

Run the WP-free logic smoke test:

`php tests/smoke-test.php`

== Changelog ==

= 1.0.1 =
* Account page: spending-limit section moved to the top of Account Details.
* Account page: per-user enable/disable switch; removing all custom periods clears the limit.
* Account page: refined Limit Type / custom-period controls and calendar; "Save" button; status message auto-hides after 5s.
* Single CMS limit type hides the frontend dropdown and auto-selects that type.
* Checkout: over-limit always prompts a confirmation (even when the wallet covers it); only hard-blocked when the wallet is active and cannot cover the order.
* New CMS field: customizable over-limit confirmation message ({limit}/{spent}/{total}/{over} placeholders).
* Branded, centered confirmation dialogs.

= 1.0.0 =
* Initial release.
