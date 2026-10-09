<?php
defined('ABSPATH') || exit;

/**
 * REST API (namespace p360/v1). Routes are public by design (customers are not WP users); everything
 * wallet-related requires the p360 session cookie, and state-changing calls also need the CSRF header.
 */
final class P360_Rest {

    public static function init(): void { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes(): void {
        $any = '__return_true';
        foreach ([
            ['POST', '/login/request', 'login_request'], ['POST', '/login/redeem', 'login_redeem'], ['POST', '/logout', 'logout'],
            ['GET', '/me', 'me'], ['POST', '/topup', 'topup'], ['POST', '/topup/claim', 'topup_claim'],
            ['POST', '/upload', 'upload'], ['POST', '/process', 'process'],
            ['GET', '/sample', 'sample'], ['GET', '/download', 'download'], ['POST', '/stripe-webhook', 'webhook'],
        ] as [$method, $path, $fn]) {
            register_rest_route('p360/v1', $path, ['methods' => $method, 'callback' => [__CLASS__, $fn], 'permission_callback' => $any]);
        }
    }

    private static function err(string $msg, int $status): WP_REST_Response { return new WP_REST_Response(['error' => $msg], $status); }

    private static function ip(): string { return md5((string)($_SERVER['REMOTE_ADDR'] ?? '')); }

    /** @return true when under the limit (and counts this attempt) */
    private static function allow(string $bucket, int $max, int $seconds = HOUR_IN_SECONDS): bool {
        $k = 'p360_rl_' . substr(md5($bucket), 0, 20);
        $n = (int)get_transient($k);
        if ($n >= $max) { return false; }
        set_transient($k, $n + 1, $seconds);
        return true;
    }

    private static function page_url(WP_REST_Request $r): string {
        $page = esc_url_raw((string)$r->get_param('page_url'));
        if ($page === '' || strpos($page, home_url('/')) !== 0) { $page = home_url('/'); }
        return remove_query_arg(['p360_login', 'p360_topup', 'p360_key'], $page);
    }

    /** Signed-in wallet, or an error response. State-changing calls (needs_csrf) must carry the CSRF header. */
    private static function auth(WP_REST_Request $r, bool $needs_csrf = false) {
        nocache_headers();
        $w = P360_Wallets::current();
        if (!$w) { return self::err('Please sign in.', 401); }
        if ($needs_csrf && !hash_equals(P360_Wallets::csrf(), (string)$r->get_header('x_p360_csrf'))) { return self::err('Session check failed. Please reload the page.', 403); }
        return $w;
    }

    // ---------- sign in ----------
    public static function login_request(WP_REST_Request $r) {
        $email = strtolower(sanitize_email((string)$r->get_param('email')));
        $generic = ['ok' => true, 'message' => 'If that email address has a wallet, a sign-in link is on its way. It works once and expires in 15 minutes.'];
        if (!is_email($email)) { return self::err('Please enter a valid email address.', 400); }
        if (!self::allow('login-ip-' . self::ip(), 10) || !self::allow('login-email-' . $email, 5)) { return self::err('Too many attempts. Please try again later.', 429); }
        $w = P360_Wallets::by_email($email);
        if ($w) {
            $token = P360_Wallets::token_issue('login', $w['id'], P360_Wallets::LOGIN_TTL);
            wp_mail($email, 'Your Prospect360 sign-in link', "Click to sign in (valid for 15 minutes, works once):\n\n" . add_query_arg(['p360_login' => $token], self::page_url($r)) .
                "\n\nIf you did not ask for this, you can ignore this email.\n");
        }
        return $generic;   // same answer whether or not the wallet exists
    }

    /** POST (not GET) so email link scanners cannot use up the single-use token. */
    public static function login_redeem(WP_REST_Request $r) {
        nocache_headers();
        if (!self::allow('redeem-ip-' . self::ip(), 30)) { return self::err('Too many attempts. Please try again later.', 429); }
        $wid = P360_Wallets::token_consume('login', (string)$r->get_param('token'));
        if (!$wid || !($w = P360_Wallets::get($wid))) { return self::err('This sign-in link has expired or was already used. Please request a new one.', 400); }
        P360_Wallets::session_start($w['id']);
        return P360_Processor::wallet_view($w);
    }

    public static function logout(WP_REST_Request $r) {
        $w = self::auth($r, true);
        if ($w instanceof WP_REST_Response) { return $w; }
        P360_Wallets::session_end();
        return ['ok' => true];
    }

    public static function me(WP_REST_Request $r) {
        $w = self::auth($r);
        return $w instanceof WP_REST_Response ? $w : P360_Processor::wallet_view($w);
    }

    // ---------- top-up ----------
    public static function topup(WP_REST_Request $r) {
        if (!P360_Stripe::configured() || (p360_secret('provero_token') === '' && !p360_dry_run())) { return self::err('Top-ups are not available right now.', 503); }
        if (!self::allow('topup-ip-' . self::ip(), 10)) { return self::err('Too many attempts. Please try again later.', 429); }
        $wallet = P360_Wallets::current();
        if ($wallet && !hash_equals(P360_Wallets::csrf(), (string)$r->get_header('x_p360_csrf'))) { return self::err('Session check failed. Please reload the page.', 403); }
        $email = $wallet ? $wallet['email'] : strtolower(sanitize_email((string)$r->get_param('email')));
        $net = (int)$r->get_param('amount_pence');
        $min = (int)p360_opt('topup_min_pence'); $max = (int)p360_opt('topup_max_pence');
        if (!is_email($email)) { return self::err('Please enter a valid email address.', 400); }
        if ($net < $min || $net > $max) { return self::err('Top-up must be between ' . p360_money($min) . ' and ' . p360_money($max) . '.', 400); }
        try {
            $t = P360_Wallets::topup_create($email, $net);
            $url = P360_Stripe::create_checkout($t, self::page_url($r));
        } catch (Throwable $e) {
            error_log('[p360] top-up failed: ' . $e->getMessage());
            return self::err('We could not start the payment. Please try again.', 502);
        }
        return ['url' => $url];
    }

    /** Return page after Stripe: confirm the payment and, once only, sign the customer straight in. */
    public static function topup_claim(WP_REST_Request $r) {
        nocache_headers();
        if (!self::allow('claim-ip-' . self::ip(), 120)) { return self::err('Too many attempts.', 429); }
        $t = P360_Wallets::topup_get((string)$r->get_param('id'));
        if (!$t || !hash_equals($t['claim_key'], (string)$r->get_param('key'))) { return self::err('Payment not found.', 404); }
        if ($t['status'] === 'pending') { P360_Stripe::reconcile($t); $t = P360_Wallets::topup_get($t['id']); }
        if ($t['status'] === 'pending') { return ['state' => 'pending']; }
        global $wpdb;
        $first = (int)$wpdb->query($wpdb->prepare('UPDATE ' . P360_Wallets::t('topups') . ' SET login_used = 1 WHERE id = %s AND login_used = 0', $t['id'])) === 1;
        $w = P360_Wallets::get($t['wallet_id']);
        if (!$first || !$w) { return ['state' => 'signin']; }   // already used: sign in with the emailed link instead
        P360_Wallets::session_start($w['id']);
        return ['state' => 'ok'] + P360_Processor::wallet_view($w);
    }

    public static function webhook(WP_REST_Request $r) {
        $payload = $r->get_body();
        $secret = p360_secret('stripe_webhook_secret');
        $sig = (string)$r->get_header('stripe_signature');
        if ($secret === '' || !P360_Stripe::verify_signature($payload, $sig, $secret)) { return self::err('Invalid signature', 400); }
        $event = json_decode($payload, true);
        if (($event['livemode'] ?? null) === p360_test_mode()) { return ['received' => true, 'ignored' => 'mode mismatch']; }
        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $s = $event['data']['object'] ?? [];
            if (($s['payment_status'] ?? '') === 'paid' && !empty($s['metadata']['topup_id'])) {
                P360_Wallets::topup_paid((string)$s['metadata']['topup_id'], (string)$s['id'], (int)($s['amount_total'] ?? -1));
            }
        }
        return ['received' => true];
    }

    // ---------- files ----------
    public static function upload(WP_REST_Request $r) {
        $w = self::auth($r, true);
        if ($w instanceof WP_REST_Response) { return $w; }
        $file = $r->get_file_params()['file'] ?? null;
        if (!$file) { return self::err('No file received.', 400); }
        $res = P360_Processor::accept_upload($w, sanitize_key((string)$r->get_param('service')), $file);
        if (!$res['ok']) { return self::err($res['error'], 400); }
        return P360_Processor::progress(P360_Orders::job_get($res['job']));
    }

    public static function process(WP_REST_Request $r) {
        $w = self::auth($r, true);
        if ($w instanceof WP_REST_Response) { return $w; }
        $p = P360_Processor::process_chunk($w, (string)$r->get_param('job'));
        return $p['status'] === 'missing' ? self::err('File not found.', 404) : $p;
    }

    private static function send_csv(string $filename, callable $body): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $body();
        exit;
    }

    /** Public: customers can see the expected format before paying. */
    public static function sample(WP_REST_Request $r) {
        $services = p360_services();
        $service = sanitize_key((string)$r->get_param('service'));
        if (!isset($services[$service])) { return self::err('Unknown service.', 404); }
        $svc = $services[$service];
        self::send_csv("prospect360-sample-$service.csv", function () use ($svc) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($svc['sample'][0]));
            foreach ($svc['sample'] as $row) { fputcsv($out, $row); }
            fclose($out);
        });
    }

    public static function download(WP_REST_Request $r) {
        $w = self::auth($r);
        if ($w instanceof WP_REST_Response) { return $w; }
        $j = P360_Orders::job_get((string)$r->get_param('job'));
        if (!$j || $j['wallet_id'] !== $w['id'] || $j['out_file'] === '' || $j['status'] === 'expired') { return self::err('Not available.', 404); }
        if ($j['status'] !== 'complete') { return self::err('Your file is still being processed.', 409); }
        $path = p360_storage_dir() . '/' . $j['out_file'];
        if (!preg_match('/^[a-f0-9]{32}\.csv$/', $j['out_file']) || !is_file($path)) { return self::err('Not available.', 404); }
        if ($r->get_param('invalid')) {
            // original columns + the reason, for rows that were rejected (blank rows are left out)
            $meta = json_decode((string)$j['job'], true) ?: [];
            $ncols = (int)($meta['ncols'] ?? 0);
            self::send_csv('prospect360-attention-rows-' . substr($j['id'], 0, 8) . '.csv', function () use ($path, $ncols) {
                $in = fopen($path, 'r');
                $out = fopen('php://output', 'w');
                $head = fgetcsv($in);
                fputcsv($out, array_merge(array_slice($head, 0, $ncols), ['Reason']));
                while (($row = fgetcsv($in)) !== false) {
                    $reason = (string)end($row);
                    if ($reason !== '' && $reason !== 'No value supplied') { fputcsv($out, array_merge(array_slice($row, 0, $ncols), [$reason])); }
                }
                fclose($in); fclose($out);
            });
        }
        self::send_csv('prospect360-cleaned-' . substr($j['id'], 0, 8) . '.csv', function () use ($path) { readfile($path); });
    }
}
