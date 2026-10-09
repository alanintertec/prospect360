<?php
defined('ABSPATH') || exit;

/** Minimal Stripe REST client (no SDK needed). */
final class P360_Stripe {

    public static function configured(): bool {
        return p360_secret('stripe_secret') !== '' && p360_secret('stripe_webhook_secret') !== '';
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
    public static function create_checkout(array $order, string $page_url): string {
        $svc = p360_services()[$order['service']];
        $items = [[
            'price_data' => [
                'currency'     => 'gbp',
                'unit_amount'  => (int)$order['net_pence'],
                'product_data' => [
                    'name'        => $svc['label'] . ' - ' . number_format($order['records']) . ' records',
                    'description' => 'Prospect360 data cleansing',
                ],
            ],
            'quantity' => 1,
        ]];
        if ($order['vat_pence'] > 0) {
            $items[] = [
                'price_data' => [
                    'currency'     => 'gbp',
                    'unit_amount'  => (int)$order['vat_pence'],
                    'product_data' => ['name' => 'VAT (' . (string)(float)p360_opt('vat_rate') . '%)'],
                ],
                'quantity' => 1,
            ];
        }
        $s = self::request('POST', '/v1/checkout/sessions', [
            'mode'                => 'payment',
            'line_items'          => $items,
            'customer_email'      => $order['email'],
            'client_reference_id' => $order['id'],
            'metadata'            => ['order_id' => $order['id']],
            'payment_intent_data' => ['description' => 'Prospect360 order ' . $order['id'], 'metadata' => ['order_id' => $order['id']]],
            'success_url'         => P360_Orders::url($order, $page_url),
            'cancel_url'          => $page_url,
        ], ['Idempotency-Key' => 'p360-' . $order['id']]);
        P360_Orders::update($order['id'], ['stripe_session' => $s['id']]);
        update_option('p360_order_page_' . $order['id'], $page_url, false);
        return $s['url'];
    }

    /** Reconcile with Stripe when the webhook has not arrived yet (customer just came back from checkout). */
    public static function reconcile(array $order): void {
        if ($order['status'] !== 'pending' || $order['stripe_session'] === '') { return; }
        try {
            $s = self::request('GET', '/v1/checkout/sessions/' . rawurlencode($order['stripe_session']));
        } catch (RuntimeException $e) { return; }
        if (($s['payment_status'] ?? '') === 'paid') {
            P360_Orders::mark_paid($order['id'], $s['id'], (int)$s['amount_total']);
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
