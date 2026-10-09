<?php
defined('ABSPATH') || exit;

/**
 * Wallets: prepaid GBP credit identified by email address. No passwords and no WordPress users.
 * Money is stored as integer micro-pounds (1 = GBP 0.000001) so per-record prices like 0.0125 are exact.
 * Access: a single-use, 15 minute emailed sign-in token is exchanged for a 30 day session cookie.
 */
final class P360_Wallets {

    const SESSION_COOKIE = 'p360_session';
    const SESSION_TTL = 30 * DAY_IN_SECONDS;
    const LOGIN_TTL = 900;

    public static function t(string $name): string { global $wpdb; return $wpdb->prefix . 'p360_' . $name; }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('wallets') . " (
            id CHAR(24) NOT NULL,
            email VARCHAR(190) NOT NULL,
            balance_micro BIGINT NOT NULL DEFAULT 0,
            expires_at INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('ledger') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wallet_id CHAR(24) NOT NULL,
            type VARCHAR(12) NOT NULL,
            amount_micro BIGINT NOT NULL,
            balance_after_micro BIGINT NOT NULL,
            ref VARCHAR(64) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY wallet_id (wallet_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('topups') . " (
            id CHAR(24) NOT NULL,
            claim_key CHAR(32) NOT NULL,
            email VARCHAR(190) NOT NULL,
            net_pence INT UNSIGNED NOT NULL,
            vat_pence INT UNSIGNED NOT NULL,
            total_pence INT UNSIGNED NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            stripe_session VARCHAR(255) NOT NULL DEFAULT '',
            wallet_id CHAR(24) NOT NULL DEFAULT '',
            login_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            paid_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('tokens') . " (
            token_hash CHAR(64) NOT NULL,
            type VARCHAR(8) NOT NULL,
            wallet_id CHAR(24) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            used_at INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) $c;");
    }

    // ---------- wallets & ledger ----------
    public static function get(string $id): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('wallets') . ' WHERE id = %s', $id), ARRAY_A) ?: null;
    }
    public static function by_email(string $email): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('wallets') . ' WHERE email = %s', strtolower(trim($email))), ARRAY_A) ?: null;
    }
    public static function ensure(string $email): array {
        global $wpdb;
        $email = strtolower(trim($email));
        $w = self::by_email($email);
        if ($w) { return $w; }
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('wallets') . ' (id, email, balance_micro, expires_at, created_at) VALUES (%s, %s, 0, 0, %d)',
            bin2hex(random_bytes(12)), $email, time()));
        return self::by_email($email);
    }

    private static function log(string $wid, string $type, int $amount, string $ref, string $note): void {
        global $wpdb;
        $bal = (int)$wpdb->get_var($wpdb->prepare('SELECT balance_micro FROM ' . self::t('wallets') . ' WHERE id = %s', $wid));
        $wpdb->insert(self::t('ledger'), ['wallet_id' => $wid, 'type' => $type, 'amount_micro' => $amount, 'balance_after_micro' => $bal,
            'ref' => substr($ref, 0, 64), 'note' => substr($note, 0, 255), 'created_at' => time()]);
    }

    /** Add credit. Top-ups and migrations also (re)start the expiry clock for the whole balance. */
    public static function credit(string $wid, int $micro, string $type, string $ref = '', string $note = ''): void {
        global $wpdb;
        if ($micro <= 0) { return; }
        $extend = in_array($type, ['topup', 'migrate'], true) ? time() + max(1, (int)p360_opt('credit_expiry_days')) * DAY_IN_SECONDS : 0;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::t('wallets') . ' SET balance_micro = balance_micro + %d, expires_at = CASE WHEN %d > expires_at THEN %d ELSE expires_at END WHERE id = %s',
            $micro, $extend, $extend, $wid));
        self::log($wid, $type, $micro, $ref, $note);
    }

    /** Atomically take credit off. False if the balance is too low. */
    public static function debit(string $wid, int $micro, string $type, string $ref = '', string $note = ''): bool {
        global $wpdb;
        if ($micro <= 0) { return true; }
        $n = (int)$wpdb->query($wpdb->prepare('UPDATE ' . self::t('wallets') . ' SET balance_micro = balance_micro - %d WHERE id = %s AND balance_micro >= %d', $micro, $wid, $micro));
        if ($n !== 1) { return false; }
        self::log($wid, $type, -$micro, $ref, $note);
        return true;
    }

    public static function ledger(string $wid, int $limit = 25): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT type, amount_micro, balance_after_micro, note, created_at FROM ' . self::t('ledger') . ' WHERE wallet_id = %s ORDER BY id DESC LIMIT %d', $wid, $limit), ARRAY_A) ?: [];
    }

    // ---------- top-ups ----------
    public static function topup_create(string $email, int $net_pence): array {
        global $wpdb;
        $vat = p360_vat_pence($net_pence);
        $row = ['id' => bin2hex(random_bytes(12)), 'claim_key' => bin2hex(random_bytes(16)), 'email' => strtolower($email),
                'net_pence' => $net_pence, 'vat_pence' => $vat, 'total_pence' => $net_pence + $vat, 'status' => 'pending', 'created_at' => time()];
        $wpdb->insert(self::t('topups'), $row);
        return $row;
    }
    public static function topup_get(string $id): ?array {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('topups') . ' WHERE id = %s', $id), ARRAY_A) ?: null;
    }
    public static function topup_update(string $id, array $f): void {
        global $wpdb;
        $wpdb->update(self::t('topups'), $f, ['id' => $id]);
    }

    /** pending -> paid exactly once; the session and the exact amount must match what we created. Credits the wallet. */
    public static function topup_paid(string $id, string $session_id, int $amount_total): bool {
        global $wpdb;
        $n = (int)$wpdb->query($wpdb->prepare('UPDATE ' . self::t('topups') . " SET status='paid', paid_at=%d WHERE id=%s AND status='pending' AND stripe_session=%s AND total_pence=%d",
            time(), $id, $session_id, $amount_total));
        if ($n !== 1) { return false; }
        $t = self::topup_get($id);
        $w = self::ensure($t['email']);
        self::topup_update($id, ['wallet_id' => $w['id']]);
        self::credit($w['id'], (int)$t['net_pence'] * 10000, 'topup', $id, 'Top-up ' . p360_money((int)$t['net_pence']) . ' (+VAT ' . p360_money((int)$t['vat_pence']) . ')');
        $page = (string)get_option('p360_topup_page_' . $id, home_url('/'));
        delete_option('p360_topup_page_' . $id);
        wp_mail($t['email'], 'Your Prospect360 credit', "Thanks - " . p360_money((int)$t['net_pence']) . " of credit has been added to your wallet.\n\n" .
            "To use it, go to $page and sign in with this email address ({$t['email']}). We will email you a sign-in link; there is no password.\n");
        return true;
    }

    // ---------- tokens & sessions ----------
    public static function token_issue(string $type, string $wid, int $ttl): string {
        global $wpdb;
        $raw = bin2hex(random_bytes(32));
        $wpdb->insert(self::t('tokens'), ['token_hash' => hash('sha256', $raw), 'type' => $type, 'wallet_id' => $wid, 'expires_at' => time() + $ttl, 'created_at' => time()]);
        return $raw;
    }

    /** Single use: the first successful call wins. */
    public static function token_consume(string $type, string $raw): ?string {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $h = hash('sha256', $raw);
        $n = (int)$wpdb->query($wpdb->prepare('UPDATE ' . self::t('tokens') . ' SET used_at = %d WHERE token_hash = %s AND type = %s AND used_at = 0 AND expires_at > %d', time(), $h, $type, time()));
        if ($n !== 1) { return null; }
        return (string)$wpdb->get_var($wpdb->prepare('SELECT wallet_id FROM ' . self::t('tokens') . ' WHERE token_hash = %s', $h));
    }

    public static function session_start(string $wid): string {
        $raw = self::token_issue('session', $wid, self::SESSION_TTL);
        setcookie(self::SESSION_COOKIE, $raw, ['expires' => time() + self::SESSION_TTL, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::SESSION_COOKIE] = $raw;
        return $raw;
    }

    /** The signed-in wallet for this request, or null. */
    public static function current(): ?array {
        global $wpdb;
        $raw = (string)($_COOKIE[self::SESSION_COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $wid = $wpdb->get_var($wpdb->prepare('SELECT wallet_id FROM ' . self::t('tokens') . " WHERE token_hash = %s AND type = 'session' AND expires_at > %d", hash('sha256', $raw), time()));
        return $wid ? self::get((string)$wid) : null;
    }

    public static function session_end(): void {
        global $wpdb;
        $raw = (string)($_COOKIE[self::SESSION_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $raw)) { $wpdb->delete(self::t('tokens'), ['token_hash' => hash('sha256', $raw)]); }
        setcookie(self::SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::SESSION_COOKIE]);
    }

    /** CSRF token bound to the session; required (as a header) on every state-changing request. */
    public static function csrf(): string {
        $raw = (string)($_COOKIE[self::SESSION_COOKIE] ?? '');
        return hash_hmac('sha256', 'p360-csrf', $raw);
    }

    /** Daily: zero out expired balances, drop expired tokens and abandoned top-ups. */
    public static function cleanup(): void {
        global $wpdb;
        $due = $wpdb->get_results($wpdb->prepare('SELECT id, balance_micro FROM ' . self::t('wallets') . ' WHERE balance_micro > 0 AND expires_at > 0 AND expires_at < %d', time()), ARRAY_A) ?: [];
        foreach ($due as $w) {
            if (self::debit($w['id'], (int)$w['balance_micro'], 'expire', '', 'Unused credit expired')) { continue; }
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('tokens') . ' WHERE expires_at < %d', time() - DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t('topups') . " WHERE status='pending' AND created_at < %d", time() - 2 * DAY_IN_SECONDS));
    }

    /** Remove a wallet and everything of theirs (admin action / data deletion request). */
    public static function delete(string $wid): void {
        global $wpdb;
        $dir = p360_storage_dir();
        foreach (P360_Orders::jobs_for_wallet($wid) as $j) {
            foreach (['in_file', 'out_file'] as $f) {
                if (preg_match('/^[a-f0-9]{32}\.csv$/', (string)$j[$f])) { @unlink("$dir/{$j[$f]}"); @unlink("$dir/{$j[$f]}.cache"); }
            }
        }
        $wpdb->delete(P360_Orders::jobs_table(), ['wallet_id' => $wid]);
        $wpdb->delete(self::t('tokens'), ['wallet_id' => $wid]);
        $wpdb->delete(self::t('ledger'), ['wallet_id' => $wid]);
        $wpdb->delete(self::t('wallets'), ['id' => $wid]);
    }
}
