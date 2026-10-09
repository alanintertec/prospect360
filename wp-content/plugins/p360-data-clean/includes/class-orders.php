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
            used_records INT UNSIGNED NOT NULL DEFAULT 0,
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
        $j = self::jobs_table();
        dbDelta("CREATE TABLE $j (
            id CHAR(24) NOT NULL,
            order_id CHAR(24) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'processing',
            in_file VARCHAR(64) NOT NULL DEFAULT '',
            out_file VARCHAR(64) NOT NULL DEFAULT '',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            billed INT UNSIGNED NOT NULL DEFAULT 0,
            done_rows INT UNSIGNED NOT NULL DEFAULT 0,
            in_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
            job LONGTEXT NULL,
            message VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id)
        ) $charset;");
    }

    public static function jobs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'p360_jobs';
    }

    /** Pre-1.1 orders held a single upload on the order row; move it to a job so the unused balance carries over. */
    public static function migrate(): void {
        global $wpdb;
        if (get_option('p360_jobs_migrated')) { return; }
        $t = self::table();
        $rows = $wpdb->get_results("SELECT * FROM $t WHERE out_file <> ''", ARRAY_A) ?: [];
        foreach ($rows as $o) {
            $wpdb->insert(self::jobs_table(), [
                'id' => bin2hex(random_bytes(12)), 'order_id' => $o['id'],
                'status' => $o['status'] === 'complete' ? 'complete' : 'processing',
                'in_file' => $o['in_file'], 'out_file' => $o['out_file'], 'total_rows' => (int)$o['total_rows'],
                'billed' => (int)$o['total_rows'], 'done_rows' => (int)$o['done_rows'], 'in_offset' => (int)$o['in_offset'],
                'job' => $o['job'], 'message' => (string)$o['message'], 'created_at' => (int)$o['created_at'],
            ]);
            self::update($o['id'], ['status' => 'paid', 'used_records' => (int)$o['total_rows'], 'in_file' => '', 'out_file' => '']);
        }
        update_option('p360_jobs_migrated', 1);
    }

    public static function remaining(array $o): int { return max(0, (int)$o['records'] - (int)$o['used_records']); }
    public static function expires_at(array $o): int { return (int)$o['paid_at'] + max(1, (int)p360_opt('credit_expiry_days')) * DAY_IN_SECONDS; }

    /** Atomically take $n records off the balance. False if there are not enough (or the order is not paid). */
    public static function reserve(string $id, int $n): bool {
        global $wpdb;
        return 1 === (int)$wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET used_records = used_records + %d WHERE id = %s AND status = 'paid' AND records - used_records >= %d",
            $n, $id, $n
        ));
    }

    // ---- jobs (one per uploaded file)
    public static function job_create(array $row): void {
        global $wpdb;
        $wpdb->insert(self::jobs_table(), $row);
    }
    public static function job_get(string $id): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE id = %s', $id), ARRAY_A);
        return $r ?: null;
    }
    public static function job_update(string $id, array $fields): void {
        global $wpdb;
        $wpdb->update(self::jobs_table(), $fields, ['id' => $id]);
    }
    public static function jobs(string $order_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE order_id = %s ORDER BY created_at DESC', $order_id), ARRAY_A) ?: [];
    }
    public static function active_job(string $order_id): ?array {
        foreach (self::jobs($order_id) as $j) { if ($j['status'] === 'processing') { return $j; } }
        return null;
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
            "Download your sample CSV and upload your files here. You can upload several files until your records are used up (keep this link private):\n" . self::url($o, $page) . "\n"
        );
        return true;
    }

    /** Daily: delete customer files after the retention period, expire unused balances, drop abandoned unpaid orders. */
    public static function cleanup(): void {
        global $wpdb;
        $dir = p360_storage_dir();
        $cut = time() - max(1, (int)p360_opt('retention_days')) * DAY_IN_SECONDS;
        $jobs = $wpdb->get_results($wpdb->prepare(
            'SELECT id, in_file, out_file FROM ' . self::jobs_table() . " WHERE created_at < %d AND status <> 'expired'", $cut
        ), ARRAY_A) ?: [];
        foreach ($jobs as $r) {
            foreach (['in_file', 'out_file'] as $f) {
                if ($r[$f] !== '' && preg_match('/^[a-f0-9]{32}\.csv$/', $r[$f])) { @unlink("$dir/{$r[$f]}"); @unlink("$dir/{$r[$f]}.cache"); }
            }
            self::job_update($r['id'], ['status' => 'expired', 'in_file' => '', 'out_file' => '', 'job' => null]);
        }
        $t = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status='expired' WHERE status='paid' AND paid_at < %d", time() - max(1, (int)p360_opt('credit_expiry_days')) * DAY_IN_SECONDS
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status='pending' AND created_at < %d", time() - 2 * DAY_IN_SECONDS));
    }
}
