# TEB NestPay (3D Pay Hosting) for WooCommerce

A WooCommerce payment gateway plugin for the **TEB Kosova NestPay** platform, using the
**3D Pay Hosting** model and **Hash version 3 (SHA-512)** — the current, secure hashing
scheme.

The customer is redirected to the bank's 3D Secure hosted page to enter card details, so
**card data never touches your server** (keeps your PCI scope minimal). The bank redirects
back with a signed result, which the plugin verifies before marking the order paid.

> **Disclaimer:** This is an unofficial, community-built plugin. It is **not affiliated
> with, endorsed by, or supported by TEB Kosova or Asseco**. "TEB" and "NestPay" are
> trademarks of their respective owners. Use at your own risk.

---

## Requirements

- WordPress with **WooCommerce** active (this is a WooCommerce gateway — plain WordPress
  cannot process payments on its own).
- PHP 7.2+ with the standard `hash` extension (bundled with PHP).
- HTTPS on your site (required for the bank to POST results back to your callback).

## Installation

1. Copy the folder `teb-nestpay-woocommerce` into `wp-content/plugins/` on your site
   (or zip that folder and upload it via **Plugins → Add New → Upload Plugin**).
2. In WP admin, go to **Plugins** and activate **"TEB NestPay (3D Pay Hosting) for WooCommerce"**.
3. Go to **WooCommerce → Settings → Payments → TEB NestPay (3D Pay Hosting)** and configure it.

## Configuration

| Setting | What to enter |
|---|---|
| **Enable** | Turn the method on. |
| **Title / Description** | What the customer sees at checkout. |
| **Test mode** | ON while testing (uses the Test gateway URL). |
| **Client ID (Merchant ID)** | Provided by TEB. |
| **Store Key** | Provided by TEB. Used for the SHA-512 hash — keep secret. |
| **Store type** | Leave as `3d_pay_hosting`. |
| **Transaction type** | `Auth` (charge now) or `PreAuth` (authorize, capture later). |
| **Currency code** | `978` = EUR (default for Kosovo). Must match your merchant account. |
| **Language** | Language of the bank's hosted page. |
| **Test gateway URL** | Confirm the exact host with TEB. Sample default is the NestPay test host. |
| **Live gateway URL** | Production 3D gate URL — **TEB must give you this.** |

---

## What to request from TEB (you don't have these yet)

You will need real credentials from the bank before this plugin can process payments.
Ask your TEB Kosova merchant/e-commerce contact for:

1. **Client ID / Merchant ID** — your unique merchant number.
2. **Store Key** — the secret key used to compute the SHA-512 hash. Confirm it's issued
   for **Hash version 3 (ver3 / SHA-512)**.
3. **Production 3D Gate URL** — the live `.../servlet/est3Dgate` endpoint (host domain).
4. **Test 3D Gate URL** — and confirm whether the shared NestPay test host applies to you.
5. **Currency** your account is set up for (Kosovo is normally **EUR / 978**).
6. **Allowed return URLs** — some setups require the bank to whitelist your `okUrl`/`failUrl`.
   Give them your callback URL (see below).
7. *(Optional, for the Query Service)* the **API username & password** if you want to query
   order status/history programmatically later.

### Your callback URL to give them

```
https://YOUR-DOMAIN/?wc-api=teb_nestpay
```

The plugin uses this single URL for both `okUrl` and `failUrl`; the bank POSTs the result
there, the plugin verifies the hash, then sends the customer to the order-received page
(success) or back to checkout with an error (failure).

---

## How it works (flow)

1. Customer places the order → plugin builds a form (client ID, amount, order id, currency,
   return URLs, `hashAlgorithm=ver3`, and the **SHA-512 hash**) and auto-submits it to the
   bank's 3D gate.
2. Customer authenticates + pays on the **bank's** page.
3. Bank redirects back to `?wc-api=teb_nestpay` (POST).
4. Plugin recomputes the hash to verify authenticity, checks `mdStatus` (1–4 = 3D OK) and
   `Response=Approved` / `ProcReturnCode=00`, then calls `payment_complete()` or fails the order.

---

## Author

**Gentian Nuka**

## License

GPL-2.0-or-later
