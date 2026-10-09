<?php
defined('ABSPATH') || exit;

/**
 * Jobs (one per uploaded file) and the legacy 1.x per-service "orders" table, which is now only read
 * during migration to wallet credit.
 */
final class P360_Orders {

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'p360_orders'; }
    public static function jobs_table(): string { global $wpdb; return $wpdb->prefix . 'p360_jobs'; }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = self::table();
        // legacy (<=1.x) orders: kept so existing balances can be migrated, no longer written to
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
        ) $c;");
        dbDelta("CREATE TABLE " . self::jobs_table() . " (
            id CHAR(24) NOT NULL,
            order_id CHAR(24) NOT NULL DEFAULT '',
            wallet_id CHAR(24) NOT NULL DEFAULT '',
            service VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'processing',
            in_file VARCHAR(64) NOT NULL DEFAULT '',
            out_file VARCHAR(64) NOT NULL DEFAULT '',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            billed INT UNSIGNED NOT NULL DEFAULT 0,
            cost_micro BIGINT NOT NULL DEFAULT 0,
            price_micro INT UNSIGNED NOT NULL DEFAULT 0,
            refunded_micro BIGINT NOT NULL DEFAULT 0,
            done_rows INT UNSIGNED NOT NULL DEFAULT 0,
            in_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
            job LONGTEXT NULL,
            message VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY wallet_id (wallet_id)
        ) $c;");
        P360_Wallets::install();
    }

    // ---------- jobs ----------
    public static function job_create(array $row): void { global $wpdb; $wpdb->insert(self::jobs_table(), $row); }
    public static function job_get(string $id): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE id = %s', $id), ARRAY_A) ?: null;
    }
    public static function job_update(string $id, array $fields): void { global $wpdb; $wpdb->update(self::jobs_table(), $fields, ['id' => $id]); }
    public static function jobs_for_wallet(string $wid): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE wallet_id = %s ORDER BY created_at DESC', $wid), ARRAY_A) ?: [];
    }
    public static function active_job(string $wid): ?array {
        foreach (self::jobs_for_wallet($wid) as $j) { if ($j['status'] === 'processing') { return $j; } }
        return null;
    }

    // ---------- migrations ----------
    /** 1.0 -> 1.1: a single upload used to live on the order row; move it to a job. */
    public static function migrate(): void {
        global $wpdb;
        if (get_option('p360_jobs_migrated')) { return; }
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE out_file <> ''", ARRAY_A) ?: [];
        foreach ($rows as $o) {
            $wpdb->insert(self::jobs_table(), [
                'id' => bin2hex(random_bytes(12)), 'order_id' => $o['id'], 'status' => $o['status'] === 'complete' ? 'complete' : 'processing',
                'in_file' => $o['in_file'], 'out_file' => $o['out_file'], 'total_rows' => (int)$o['total_rows'], 'billed' => (int)$o['total_rows'],
                'done_rows' => (int)$o['done_rows'], 'in_offset' => (int)$o['in_offset'], 'job' => $o['job'], 'message' => (string)$o['message'], 'created_at' => (int)$o['created_at'],
            ]);
            $wpdb->update(self::table(), ['status' => 'paid', 'used_records' => (int)$o['total_rows'], 'in_file' => '', 'out_file' => ''], ['id' => $o['id']]);
        }
        update_option('p360_jobs_migrated', 1);
    }

    /** 1.x -> 2.0: unused records on paid orders become wallet credit at the price paid per record. */
    public static function migrate_wallets(): void {
        global $wpdb;
        if (get_option('p360_wallets_migrated')) { return; }
        $orders = $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE status = 'paid'", ARRAY_A) ?: [];
        foreach ($orders as $o) {
            $w = P360_Wallets::ensure($o['email']);
            $remaining = max(0, (int)$o['records'] - (int)$o['used_records']);
            $micro = $o['records'] > 0 ? (int)round($o['net_pence'] * 10000 / $o['records'] * $remaining) : 0;
            P360_Wallets::credit($w['id'], $micro, 'migrate', $o['id'], "Migrated: $remaining unused " . $o['service'] . " records from an earlier order");
            $wpdb->query($wpdb->prepare('UPDATE ' . self::jobs_table() . " SET wallet_id = %s, service = %s WHERE order_id = %s AND wallet_id = ''", $w['id'], $o['service'], $o['id']));
            $wpdb->update(self::table(), ['status' => 'migrated', 'used_records' => (int)$o['records']], ['id' => $o['id']]);
        }
        update_option('p360_wallets_migrated', 1);
    }

    /** Daily: delete customer files after the retention period; wallet housekeeping. */
    public static function cleanup(): void {
        global $wpdb;
        $dir = p360_storage_dir();
        $cut = time() - max(1, (int)p360_opt('retention_days')) * DAY_IN_SECONDS;
        $jobs = $wpdb->get_results($wpdb->prepare('SELECT id, in_file, out_file FROM ' . self::jobs_table() . " WHERE created_at < %d AND status <> 'expired'", $cut), ARRAY_A) ?: [];
        foreach ($jobs as $r) {
            foreach (['in_file', 'out_file'] as $f) {
                if ($r[$f] !== '' && preg_match('/^[a-f0-9]{32}\.csv$/', $r[$f])) { @unlink("$dir/{$r[$f]}"); @unlink("$dir/{$r[$f]}.cache"); }
            }
            self::job_update($r['id'], ['status' => 'expired', 'in_file' => '', 'out_file' => '', 'job' => null]);
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE status='pending' AND created_at < %d", time() - 2 * DAY_IN_SECONDS));
        P360_Wallets::cleanup();
    }
}
