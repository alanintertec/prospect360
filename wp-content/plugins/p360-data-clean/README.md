# Prospect360 Data Clean (WordPress plugin)

Visitors pick a service, pay per record with Stripe Checkout, download a sample CSV, then upload their CSV to be cleaned through the [Provero API](https://api.provero.io/docs). Results are returned as the original CSV plus verification columns.

| Service | Provero endpoint | Columns added |
|---|---|---|
| Email verification | `POST /api/validate/email` | syntax, deliverable, catch-all, disposable, role-based, risk level, check result, typo suggestion |
| Mobile (HLR) | `POST /api/validate/phone` | normalised number, status, live, current/original network |
| TPS / CTPS | `POST /api/validate/phone-tps` | formatted number, on TPS/CTPS, registration dates |

## Install
1. Copy `wp-content/plugins/p360-data-clean` into the site and activate it (this creates the `wp_p360_orders` table and a daily cleanup job).
2. **Settings -> Data Clean**: enter the Provero token, Stripe secret key and webhook signing secret, and set your per-record prices. Secrets can instead be defined in `wp-config.php` as `P360_PROVERO_TOKEN`, `P360_STRIPE_SECRET`, `P360_STRIPE_WEBHOOK_SECRET`.
3. In Stripe, add a webhook endpoint `https://<site>/wp-json/p360/v1/stripe-webhook` for `checkout.session.completed` and `checkout.session.async_payment_succeeded`, and paste its signing secret into the settings.
4. Add `[p360_data_clean]` to a page (e.g. `/data-clean/`).

## Test mode (Stripe)
1. In Stripe, switch the dashboard to **Test mode** and copy the test secret key (`sk_test_...`).
2. Still in test mode, add a webhook endpoint with the same URL as above (same two events) and copy its signing secret.
3. In **Settings -> Data Clean** tick *Test mode* and fill the two TEST fields (or define `P360_TEST_MODE`, `P360_STRIPE_TEST_SECRET`, `P360_STRIPE_TEST_WEBHOOK_SECRET` in `wp-config.php`).
4. Visitors now see a TEST MODE banner. Pay with card `4242 4242 4242 4242`, any future expiry, any CVC.

**Dry run (optional, test mode only):** tick *Dry run* (or define `P360_DRY_RUN`) to return deterministic fake results instead of calling Provero, so testing uses no Provero credit. It is ignored whenever test mode is off, so live orders always use the real API. Do one small real run (no dry run) before launch to prove the Provero connection.

Safety checks: test mode refuses to run with a live key (and live mode refuses a `sk_test_` key), and webhook events from the other mode are ignored. Without dry run, test orders still use real Provero credits - keep test uploads small.

## How it works
- **Pricing**: `records x price`, minimum charge applies, VAT added as a second Stripe line item. The server recomputes the price; the browser's total is display only.
- **Payment**: the order is only marked paid when the Stripe webhook (or the return-page check against the Stripe API) reports `payment_status=paid` for the same session and exact amount.
- **Access**: no login. Each order has a random 128-bit key in its link (also emailed). Files are stored in `uploads/p360-data-clean/` under random names and only served through the plugin after the key check.
- **Processing**: the browser calls `/process` repeatedly; each call cleans 40 rows (8 concurrent Provero requests, duplicates looked up once, blanks skipped). It is resumable if the tab closes. If Provero returns 401/402 the job pauses, the site admin is emailed, and it resumes once fixed.
- **Balance, not one-shot**: an order is a balance of records. Each upload reserves one record per row that has a value in the email/phone column (blank cells are free; invalid values and repeats still count, so customers should check their data first). Repeats within a file are only looked up once with Provero, so they cost the customer a record but cost you nothing extra. Customers can upload several files via the same order link until the balance is used up or expires (default 365 days, setting *Unused records expire after*). One file is processed at a time. Result files are deleted after the retention period (default 7 days).
- Upgrading from 1.0: orders with a previous upload are migrated automatically (their used records stay used, the rest becomes balance).

## Notes
- You pay Provero separately: keep the Provero account topped up, or paid orders will pause.
- On nginx the `.htaccess` in the storage folder has no effect; the random filenames and REST-only download still apply, but you may add a `deny all` location for `uploads/p360-data-clean/`.
- Rows that fail a lookup (network/validation) get the reason in the `Error` column and are not retried automatically.
