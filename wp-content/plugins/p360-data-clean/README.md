# Prospect360 Data Clean (WordPress plugin, v2)

Customers top up a **wallet** with Stripe and spend the credit on any of four data-cleaning services, powered by the [Provero API](https://api.provero.io/docs). There are no passwords and no WordPress accounts: a customer is identified by their email address.

| Service | Provero endpoint | Columns added to the customer's CSV |
|---|---|---|
| Email verification | `POST /api/validate/email` | syntax, deliverable, catch-all, disposable, role-based, risk level, check result, typo suggestion |
| Mobile (HLR) | `POST /api/validate/phone` | normalised number, status, live, current/original network |
| TPS / CTPS | `POST /api/validate/phone-tps` | formatted number, on TPS/CTPS, registration dates |
| UK address validation (PAF) | `POST /api/validate/uk-address` | status (verified / review / no_match), premise match, standardised lines, post town, postcode, country, full address, note |

## Install
1. Copy `wp-content/plugins/p360-data-clean` into the site and activate it (creates the tables and a daily cleanup job).
2. **Settings -> Data Clean**: Provero token, Stripe keys, prices per row, VAT, top-up packs. Secrets can instead be set in `wp-config.php` as `P360_PROVERO_TOKEN`, `P360_STRIPE_SECRET`, `P360_STRIPE_WEBHOOK_SECRET` (and `P360_STRIPE_TEST_*`).
3. In Stripe add a webhook endpoint `https://<site>/wp-json/p360/v1/stripe-webhook` for `checkout.session.completed` and `checkout.session.async_payment_succeeded`. A restricted key with *Checkout Sessions: Write* is enough.
4. Put `[p360_data_clean]` on a page (e.g. `/data-clean/`). **Settings -> Data Clean wallets** lets you look up a customer, see their ledger, add/remove credit by hand and delete a wallet (data-deletion requests).

## How it works
- **Credit is money (GBP), not records.** One wallet pays for all services. A top-up buys credit at its net price (VAT is added at checkout as a separate line). Money is stored as integer micro-pounds so prices like 0.0125/row are exact. Every change is in a ledger.
- **Spending**: when a file is accepted the plugin counts rows with a value, multiplies by that service's price, and takes it from the wallet atomically (two uploads can't overspend). Not enough credit -> the file is rejected with the cost and balance, nothing is taken. One file is processed at a time per wallet.
- **Billing rules**: every non-blank row with a value costs the service price (invalid values and repeats included: customers are responsible for their data). Repeats within a file are looked up once with Provero (so they cost you nothing extra). Rows that fail because of a lookup/network/provider fault (not bad input) are **refunded** to the wallet automatically.
- **Expiry**: unused credit expires `credit_expiry_days` (default 365) after the customer's latest top-up; the daily job zeroes expired balances (ledger entry `expire`).
- **Processing**: the browser calls `/process` repeatedly; each call cleans 40 rows (8 concurrent Provero requests). Resumable if the tab closes. If Provero returns 401/402/502/503 the job pauses, the admin is emailed (at most hourly) and it resumes automatically.
- **Files**: stored in `uploads/p360-data-clean/` under random names, served only through the plugin to the owning wallet, deleted after `retention_days` (default 7).
- **Per-file report**: summary (checked / invalid / blank + counts per reason), **Download cleaned CSV**, and **Invalid rows only** / **Rows needing attention**.

## Sign-in and security (no passwords)
- A returning customer enters their email; if a wallet exists we email a **single-use link valid for 15 minutes**. The page exchanges it (via POST, so email link-scanners can't burn it) for a **30-day session cookie** (HttpOnly, Secure, SameSite=Lax) and removes the token from the URL. Tokens are stored hashed. The response is identical whether or not the email has a wallet.
- After paying, the Stripe return page signs the customer in once (single-use claim key), so there is no extra email step for new customers.
- Every state-changing request needs a per-session **CSRF header**. Rate limits: login requests per IP and per email, top-ups per IP, token redemptions per IP.
- Wallet isolation: downloads, uploads and processing check that the job belongs to the signed-in wallet.
- Payments are only credited when Stripe reports `paid` for the same session and the **exact amount**; the webhook is signature-checked and idempotent. Card data never touches the site.
- Wallet credit cannot be withdrawn. The residual risk is someone with access to the customer's mailbox, as with any email-based sign-in.

## Test mode (Stripe)
1. In Stripe's Test mode, create a restricted key (*Checkout Sessions: Write*) and a webhook (same URL and events); copy its signing secret.
2. In **Settings -> Data Clean** tick *Test mode* and fill the two TEST fields (or `P360_TEST_MODE`, `P360_STRIPE_TEST_SECRET`, `P360_STRIPE_TEST_WEBHOOK_SECRET`). Visitors see a TEST MODE banner; pay with `4242 4242 4242 4242`.
3. Test mode refuses a live key (and live mode refuses an `sk_test_` key); webhook events from the other mode are ignored.
4. **Dry run** (test mode only, or `P360_DRY_RUN`): returns deterministic fake results and makes no Provero calls, so testing costs no Provero credit. Do one small real run with dry run off before launch.

## Input checks (before any API call)
- Repeats (emails case-insensitive; phone numbers compared after normalising; addresses ignoring case/spacing) are looked up once.
- **Email**: basic format check; failures are not sent to Provero.
- **Phone**: digits with optional `+ ( ) - .` and spaces. `07700 900123`, `+44 7700 900123`, `+44 (0)7700 900123`, `+44-7700-900123`, `0044 7700 900123` and `44 7700 900123` all become `+447700900123`. Letters/other symbols and wrong lengths are rejected locally; no country code means UK; TPS is UK-only.
- **Address**: either `full_address`, or `postcode` plus `address_line_1` (ideally `town_city`); optional `address_line_2`, `address_line_3`, `county`; common variants (`address1`, `town`, `city`, `post_code`) are recognised. `verified` = full PAF match; `review` and `no_match` rows get a note and count as "not verified".

## Upgrading from 1.x
Orders (record balances) from v1 are migrated automatically on first load: unused records become wallet credit at the price paid per record, and earlier files stay downloadable from the customer's wallet.

## Notes
- You pay Provero separately: keep its balance topped up or jobs will pause. Provero's entry prices are email 0.006, HLR 0.0042, TPS 0.004, address 0.046 per request, so check your margins.
- On nginx the storage folder's `.htaccess` has no effect; random filenames and plugin-only downloads still apply, but add a `deny all` rule for `uploads/p360-data-clean/` if you can.
- The UK postcode lookup / address picker service is not part of this plugin.
