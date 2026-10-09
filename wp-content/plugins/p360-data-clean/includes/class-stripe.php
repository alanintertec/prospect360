<?php
defined('ABSPATH') || exit;

/** Minimal Stripe REST client (no SDK needed). */
final class P360_Stripe {

    public static function configured(): bool {
        $key = p360_secret('stripe_secret');
        if ($key === '' || p360_secret('stripe_webhook_secret') === '') { return false; }
        // never let a test key run live, or a live key run in test mode
        $is_test_key = (bool)preg_match('/^(sk|rk)_test_/', $key);
        return p360_test_mode() ? $is_test_key : !$is_test_key;
    }

    /** @throws RuntimeException */
    public static function request(string $method, string $path, array $params = [], array $headers = []): array {
        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => array_merge([
                'Authorization' => 'Basic ' . base64_encode(p360_secret('stripe_secret') . ':'),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ], $headers),
        ];
        if ($method === 'POST') { $args['body'] = http_build_query($params); }
        $res = wp_remote_request(rtrim(p360_stripe_base(), '/') . $path, $args);
        if (is_wp_error($res)) { throw new RuntimeException('Stripe unreachable: ' . $res->get_error_message()); }
        $code = wp_remote_retrieve_response_code($res);
        $j = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($j) || $code >= 400) {
            throw new RuntimeException('Stripe error: ' . ($j['error']['message'] ?? "HTTP $code"));
        }
        return $j;
    }

    /** @return string checkout URL */
    public static function create_checkout(array $topup, string $page_url): string {
        $items = [[
            'price_data' => [
                'currency' => 'gbp', 'unit_amount' => (int)$topup['net_pence'],
                'product_data' => ['name' => 'Prospect360 data cleansing credit', 'description' => 'Prepaid credit for email, mobile, TPS and address checks'],
            ],
            'quantity' => 1,
        ]];
        if ($topup['vat_pence'] > 0) {
            $items[] = ['price_data' => ['currency' => 'gbp', 'unit_amount' => (int)$topup['vat_pence'],
                'product_data' => ['name' => 'VAT (' . (string)(float)p360_opt('vat_rate') . '%)']], 'quantity' => 1];
        }
        $s = self::request('POST', '/v1/checkout/sessions', [
            'mode'                => 'payment',
            'line_items'          => $items,
            'customer_email'      => $topup['email'],
            'client_reference_id' => $topup['id'],
            'metadata'            => ['topup_id' => $topup['id']],
            'payment_intent_data' => ['description' => 'Prospect360 credit ' . $topup['id'], 'metadata' => ['topup_id' => $topup['id']]],
            'success_url'         => add_query_arg(['p360_topup' => $topup['id'], 'p360_key' => $topup['claim_key']], $page_url),
            'cancel_url'          => $page_url,
        ], ['Idempotency-Key' => 'p360-topup-' . $topup['id']]);
        P360_Wallets::topup_update($topup['id'], ['stripe_session' => $s['id']]);
        update_option('p360_topup_page_' . $topup['id'], $page_url, false);
        return $s['url'];
    }

    /** Ask Stripe whether this top-up was paid (covers the case where the webhook has not arrived yet). */
    public static function reconcile(array $topup): void {
        if ($topup['status'] !== 'pending' || $topup['stripe_session'] === '') { return; }
        try {
            $s = self::request('GET', '/v1/checkout/sessions/' . rawurlencode($topup['stripe_session']));
        } catch (RuntimeException $e) { return; }
        if (($s['payment_status'] ?? '') === 'paid') {
            P360_Wallets::topup_paid($topup['id'], $s['id'], (int)$s['amount_total']);
        }
    }

    public static function verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
        $t = 0; $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) { continue; }
            if ($kv[0] === 't') { $t = (int)$kv[1]; } elseif ($kv[0] === 'v1') { $sigs[] = $kv[1]; }
        }
        if (!$t || !$sigs || abs(time() - $t) > $tolerance) { return false; }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) { if (hash_equals($expected, $s)) { return true; } }
        return false;
    }
}
