<?php
defined('ABSPATH') || exit;

/**
 * REST API (namespace p360/v1). Routes are public by design (visitors are not WP users);
 * customer routes are protected by the order's random access key instead of a login.
 */
final class P360_Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        $any = '__return_true';
        register_rest_route('p360/v1', '/checkout', ['methods' => 'POST', 'callback' => [__CLASS__, 'checkout'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/stripe-webhook', ['methods' => 'POST', 'callback' => [__CLASS__, 'webhook'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/order', ['methods' => 'GET', 'callback' => [__CLASS__, 'order'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/upload', ['methods' => 'POST', 'callback' => [__CLASS__, 'upload'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/process', ['methods' => 'POST', 'callback' => [__CLASS__, 'process'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/sample', ['methods' => 'GET', 'callback' => [__CLASS__, 'sample'], 'permission_callback' => $any]);
        register_rest_route('p360/v1', '/download', ['methods' => 'GET', 'callback' => [__CLASS__, 'download'], 'permission_callback' => $any]);
    }

    private static function err(string $msg, int $status): WP_REST_Response {
        return new WP_REST_Response(['error' => $msg], $status);
    }

    private static function order_from(WP_REST_Request $r): ?array {
        return P360_Orders::auth((string)$r->get_param('id'), (string)$r->get_param('key'));
    }

    public static function checkout(WP_REST_Request $r) {
        if (!P360_Stripe::configured() || p360_secret('provero_token') === '') {
            return self::err('Ordering is not available right now.', 503);
        }
        $ip = md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $n = (int)get_transient("p360_rl_$ip");
        if ($n >= 10) { return self::err('Too many attempts. Please try again later.', 429); }
        set_transient("p360_rl_$ip", $n + 1, HOUR_IN_SECONDS);

        $services = p360_services();
        $service = sanitize_key((string)$r->get_param('service'));
        $records = (int)$r->get_param('records');
        $email = sanitize_email((string)$r->get_param('email'));
        $min = (int)p360_opt('min_records');
        $max = (int)p360_opt('max_records');
        if (!isset($services[$service])) { return self::err('Please choose a service.', 400); }
        if ($records < $min || $records > $max) {
            return self::err('Number of records must be between ' . number_format($min) . ' and ' . number_format($max) . '.', 400);
        }
        if (!is_email($email)) { return self::err('Please enter a valid email address.', 400); }

        $page = esc_url_raw((string)$r->get_param('page_url'));
        if ($page === '' || strpos($page, home_url('/')) !== 0) { $page = home_url('/'); }
        $page = remove_query_arg(['p360_order', 'p360_key'], $page);

        try {
            $order = P360_Orders::create($service, $records, $email);
            $url = P360_Stripe::create_checkout($order, $page);
        } catch (Throwable $e) {
            error_log('[p360] checkout failed: ' . $e->getMessage());
            return self::err('We could not start the payment. Please try again.', 502);
        }
        return ['url' => $url];
    }

    public static function webhook(WP_REST_Request $r) {
        $payload = $r->get_body();
        $secret = p360_secret('stripe_webhook_secret');
        $sig = (string)$r->get_header('stripe-signature');
        if ($secret === '' || !P360_Stripe::verify_signature($payload, $sig, $secret)) {
            return self::err('Invalid signature', 400);
        }
        $event = json_decode($payload, true);
        if (($event['livemode'] ?? null) === p360_test_mode()) { return ['received' => true, 'ignored' => 'mode mismatch']; }
        $type = $event['type'] ?? '';
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $s = $event['data']['object'] ?? [];
            if (($s['payment_status'] ?? '') === 'paid' && !empty($s['metadata']['order_id'])) {
                P360_Orders::mark_paid((string)$s['metadata']['order_id'], (string)$s['id'], (int)($s['amount_total'] ?? -1));
            }
        }
        return ['received' => true];
    }

    public static function order(WP_REST_Request $r) {
        $o = self::order_from($r);
        if (!$o) { return self::err('Order not found.', 404); }
        if ($o['status'] === 'pending') {
            P360_Stripe::reconcile($o);
            $o = P360_Orders::get($o['id']);
        }
        return P360_Processor::progress($o) + ['paid' => $o['status'] !== 'pending'];
    }

    public static function upload(WP_REST_Request $r) {
        $o = self::order_from($r);
        if (!$o) { return self::err('Order not found.', 404); }
        $file = $r->get_file_params()['file'] ?? null;
        if (!$file) { return self::err('No file received.', 400); }
        $res = P360_Processor::accept_upload($o, $file);
        if (!$res['ok']) { return self::err($res['error'], 400); }
        return P360_Processor::progress(P360_Orders::get($o['id']));
    }

    public static function process(WP_REST_Request $r) {
        $o = self::order_from($r);
        if (!$o) { return self::err('Order not found.', 404); }
        return P360_Processor::process_chunk($o);
    }

    private static function send_csv(string $filename, callable $body): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $body();
        exit;
    }

    public static function sample(WP_REST_Request $r) {
        $o = self::order_from($r);
        if (!$o || $o['status'] === 'pending') { return self::err('Order not found.', 404); }
        $svc = p360_services()[$o['service']];
        self::send_csv("prospect360-sample-{$o['service']}.csv", function () use ($svc) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($svc['sample'][0]));
            foreach ($svc['sample'] as $row) { fputcsv($out, $row); }
            fclose($out);
        });
    }

    public static function download(WP_REST_Request $r) {
        $o = self::order_from($r);
        if (!$o || !in_array($o['status'], ['complete', 'processing'], true) || $o['out_file'] === '') {
            return self::err('Not available.', 404);
        }
        if ($o['status'] !== 'complete') { return self::err('Your file is still being processed.', 409); }
        $path = p360_storage_dir() . '/' . $o['out_file'];
        if (!preg_match('/^[a-f0-9]{32}\.csv$/', $o['out_file']) || !is_file($path)) { return self::err('Not available.', 404); }
        self::send_csv('prospect360-cleaned-' . substr($o['id'], 0, 8) . '.csv', function () use ($path) { readfile($path); });
    }
}
