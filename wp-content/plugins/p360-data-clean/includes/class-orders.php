<?php
defined('ABSPATH') || exit;

/** Orders live in their own table so they cannot be edited from the WP post screens. */
final class P360_Orders {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'p360_orders';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id CHAR(24) NOT NULL,
            access_key CHAR(32) NOT NULL,
            service VARCHAR(16) NOT NULL,
            records INT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            net_pence INT UNSIGNED NOT NULL,
            vat_pence INT UNSIGNED NOT NULL,
            total_pence INT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            stripe_session VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL,
            paid_at INT UNSIGNED NOT NULL DEFAULT 0,
            in_file VARCHAR(64) NOT NULL DEFAULT '',
            out_file VARCHAR(64) NOT NULL DEFAULT '',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            done_rows INT UNSIGNED NOT NULL DEFAULT 0,
            in_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
            job LONGTEXT NULL,
            message VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY status_created (status, created_at)
        ) $charset;");
    }

    public static function create(string $service, int $records, string $email): array {
        global $wpdb;
        $net = p360_net_pence($service, $records);
        $vat = p360_vat_pence($net);
        $row = [
            'id'          => bin2hex(random_bytes(12)),
            'access_key'  => bin2hex(random_bytes(16)),
            'service'     => $service,
            'records'     => $records,
            'email'       => $email,
            'net_pence'   => $net,
            'vat_pence'   => $vat,
            'total_pence' => $net + $vat,
            'status'      => 'pending',
            'created_at'  => time(),
        ];
        $wpdb->insert(self::table(), $row);
        return $row;
    }

    public static function get(string $id): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id), ARRAY_A);
        return $r ?: null;
    }

    /** Fetch an order only when the secret access key matches. */
    public static function auth(string $id, string $key): ?array {
        $o = self::get($id);
        return ($o && hash_equals($o['access_key'], $key)) ? $o : null;
    }

    public static function update(string $id, array $fields): void {
        global $wpdb;
        $wpdb->update(self::table(), $fields, ['id' => $id]);
    }

    public static function url(array $o, string $base): string {
        return add_query_arg(['p360_order' => $o['id'], 'p360_key' => $o['access_key']], $base);
    }

    /**
     * Atomically move pending -> paid. Only the first caller (webhook or return-page check) wins,
     * so the customer gets exactly one email. The amount and session must match what we created.
     */
    public static function mark_paid(string $id, string $session_id, int $amount_total): bool {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status='paid', paid_at=%d WHERE id=%s AND status='pending' AND stripe_session=%s AND total_pence=%d",
            time(), $id, $session_id, $amount_total
        ));
        if ($n !== 1) { return false; }
        $o = self::get($id);
        $page = (string)get_option('p360_order_page_' . $id, home_url('/'));
        delete_option('p360_order_page_' . $id);
        $s = p360_services()[$o['service']] ?? ['label' => $o['service']];
        wp_mail(
            $o['email'],
            'Your Prospect360 data cleaning order',
            "Thanks for your order.\n\n{$o['records']} records - {$s['label']}\n\n" .
            "Download your sample CSV and upload your file here (keep this link private):\n" . self::url($o, $page) . "\n"
        );
        return true;
    }

    /** Daily: remove customer files after the retention period and drop abandoned unpaid orders. */
    public static function cleanup(): void {
        global $wpdb;
        $t = self::table();
        $dir = p360_storage_dir();
        $cut = time() - max(1, (int)p360_opt('retention_days')) * DAY_IN_SECONDS;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, in_file, out_file FROM $t WHERE created_at < %d AND status NOT IN ('pending','expired')", $cut
        ), ARRAY_A);
        foreach ($rows as $r) {
            foreach (['in_file', 'out_file'] as $f) {
                if ($r[$f] !== '' && preg_match('/^[a-f0-9]{32}\.csv$/', $r[$f])) { @unlink("$dir/{$r[$f]}"); }
            }
            self::update($r['id'], ['status' => 'expired', 'in_file' => '', 'out_file' => '', 'job' => null]);
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status='pending' AND created_at < %d", time() - 2 * DAY_IN_SECONDS));
    }
}
