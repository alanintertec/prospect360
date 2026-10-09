<?php
defined('ABSPATH') || exit;

/** Settings -> Data Clean */
final class P360_Admin {

    public static function init(): void {
        add_action('admin_menu', function () {
            add_options_page('Data Clean', 'Data Clean', 'manage_options', 'p360-data-clean', [__CLASS__, 'page']);
        });
        add_action('admin_menu', function () {
            add_options_page('Data Clean wallets', 'Data Clean wallets', 'manage_options', 'p360-wallets', [__CLASS__, 'wallets_page']);
        });
        add_action('admin_post_p360_wallet_action', [__CLASS__, 'wallet_action']);
        add_action('admin_init', function () {
            register_setting('p360', 'p360_settings', ['sanitize_callback' => [__CLASS__, 'sanitize']]);
        });
    }

    private static function fields(): array {
        return [
            'Provero'          => [['provero_token', 'Provero API token', 'password']],
            'Stripe'           => [['test_mode', 'Test mode (use the Stripe test keys; no real money is taken)', 'checkbox'],
                                   ['dry_run', 'Dry run (test mode only): fake Provero results, no Provero calls or credit used', 'checkbox'],
                                   ['stripe_test_secret', 'Stripe TEST secret key (sk_test_...)', 'password'],
                                   ['stripe_test_webhook_secret', 'Stripe TEST webhook signing secret (whsec_...)', 'password'],
                                   ['stripe_secret', 'Stripe secret key (sk_live_...)', 'password'],
                                   ['stripe_webhook_secret', 'Stripe webhook signing secret (whsec_...)', 'password']],
            'Prices (GBP per row, ex VAT)' => [['price_email', 'Email verification', 'text'], ['price_hlr', 'Mobile (HLR) verification', 'text'], ['price_tps', 'TPS / CTPS screening', 'text'], ['price_address', 'UK address validation (PAF)', 'text'],
                                   ['vat_rate', 'VAT rate %', 'text']],
            'Top-ups (pence, ex VAT)' => [['topup_packs', 'Pack sizes, comma separated (e.g. 2500,5000,10000)', 'text'], ['topup_min_pence', 'Minimum custom top-up', 'text'], ['topup_max_pence', 'Maximum top-up', 'text']],
            'Limits'           => [['max_upload_mb', 'Maximum upload size (MB)', 'text'], ['retention_days', 'Keep customer files for (days)', 'text'], ['credit_expiry_days', 'Unused credit expires after the latest top-up + (days)', 'text']],
        ];
    }

    public static function sanitize($in): array {
        $old = get_option('p360_settings', []);
        $out = [];
        foreach (self::fields() as $group) {
            foreach ($group as [$key, , $type]) {
                if ($type === 'checkbox') { $out[$key] = empty($in[$key]) ? '' : '1'; continue; }
                $v = isset($in[$key]) ? trim((string)$in[$key]) : '';
                // blank secret fields keep the stored value so they never need re-entering
                if ($type === 'password' && $v === '' && isset($old[$key])) { $v = $old[$key]; }
                $out[$key] = sanitize_text_field($v);
            }
        }
        return $out;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $o = get_option('p360_settings', []);
        ?>
        <div class="wrap">
            <h1>Data Clean</h1>
            <?php if (p360_test_mode()) : ?>
                <div class="notice notice-warning inline"><p><strong>TEST MODE is on<?php echo p360_dry_run() ? ' with DRY RUN (Provero is not called)' : ' (Provero is still called and uses credit)'; ?>.</strong> Stripe test keys are used and visitors see a test banner. Create a separate webhook in Stripe's <em>test</em> dashboard and put its secret in the TEST webhook field.</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p><strong>LIVE mode.</strong> Real payments are taken.</p></div>
            <?php endif; ?>
            <p>Add the shortcode <code>[p360_data_clean]</code> to a page.</p>
            <p><strong>Stripe webhook URL:</strong> <code><?php echo esc_html(rest_url('p360/v1/stripe-webhook')); ?></code><br>
                Subscribe it to <code>checkout.session.completed</code> and <code>checkout.session.async_payment_succeeded</code>.</p>
            <p>Secrets can alternatively be set in <code>wp-config.php</code> with <code>P360_PROVERO_TOKEN</code>, <code>P360_STRIPE_SECRET</code> and <code>P360_STRIPE_WEBHOOK_SECRET</code>; those override the fields below.</p>
            <form method="post" action="options.php">
                <?php settings_fields('p360'); ?>
                <?php foreach (self::fields() as $title => $group) : ?>
                    <h2><?php echo esc_html($title); ?></h2>
                    <table class="form-table" role="presentation">
                    <?php foreach ($group as [$key, $label, $type]) :
                        if ($type === 'checkbox') : ?>
                        <tr><th scope="row"><?php echo esc_html($label); ?></th>
                            <td><input type="checkbox" name="p360_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked($key === 'dry_run' ? p360_opt('dry_run') === '1' : p360_test_mode()); ?> <?php disabled($key === 'test_mode' && defined('P360_TEST_MODE')); ?>></td></tr>
                        <?php continue; endif;
                        $val = $type === 'password' ? '' : (string)($o[$key] ?? p360_defaults()[$key]);
                        $ph = $type === 'password' && !empty($o[$key]) ? 'saved - leave blank to keep' : '';
                        ?>
                        <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td><input class="regular-text" id="<?php echo esc_attr($key); ?>" type="<?php echo esc_attr($type); ?>"
                                       name="p360_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"
                                       placeholder="<?php echo esc_attr($ph); ?>" autocomplete="off"></td></tr>
                    <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // ---------- wallets screen ----------
    public static function wallet_action(): void {
        if (!current_user_can('manage_options')) { wp_die('Not allowed'); }
        check_admin_referer('p360_wallet_action');
        $w = P360_Wallets::get((string)($_POST['wallet'] ?? ''));
        $back = admin_url('options-general.php?page=p360-wallets');
        if (!$w) { wp_safe_redirect($back); exit; }
        $do = (string)($_POST['do'] ?? '');
        $back = add_query_arg('wallet', $w['id'], $back);
        if ($do === 'adjust') {
            $micro = (int)round((float)($_POST['amount'] ?? 0) * 1000000);
            $note = 'Admin: ' . sanitize_text_field((string)($_POST['note'] ?? ''));
            if ($micro > 0) { P360_Wallets::credit($w['id'], $micro, 'adjust', '', $note); }
            elseif ($micro < 0) { P360_Wallets::debit($w['id'], -$micro, 'adjust', '', $note); }
        } elseif ($do === 'delete' && ($_POST['confirm'] ?? '') === 'DELETE') {
            P360_Wallets::delete($w['id']);
            $back = admin_url('options-general.php?page=p360-wallets');
        }
        wp_safe_redirect($back);
        exit;
    }

    public static function wallets_page(): void {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $t = P360_Wallets::t('wallets');
        $sel = isset($_GET['wallet']) ? P360_Wallets::get((string)$_GET['wallet']) : null;
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        ?>
        <div class="wrap"><h1>Data Clean wallets</h1>
        <form method="get"><input type="hidden" name="page" value="p360-wallets">
            <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="Search by email"> <button class="button">Search</button></form>
        <?php if ($sel) :
            $ledger = P360_Wallets::ledger($sel['id'], 100); ?>
            <h2><?php echo esc_html($sel['email']); ?></h2>
            <p>Balance <strong><?php echo esc_html(p360_micro_money((int)$sel['balance_micro'])); ?></strong>
               &middot; expires <?php echo $sel['expires_at'] ? esc_html(wp_date('j M Y', (int)$sel['expires_at'])) : 'n/a'; ?>
               &middot; <a href="<?php echo esc_url(admin_url('options-general.php?page=p360-wallets')); ?>">back to list</a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('p360_wallet_action'); ?>
                <input type="hidden" name="action" value="p360_wallet_action"><input type="hidden" name="wallet" value="<?php echo esc_attr($sel['id']); ?>"><input type="hidden" name="do" value="adjust">
                <h3>Adjust credit</h3>
                <input name="amount" type="number" step="0.01" placeholder="e.g. 5 or -5 (GBP)" required>
                <input name="note" type="text" placeholder="Reason (shown in the ledger)" class="regular-text" required>
                <button class="button button-primary">Apply</button>
            </form>
            <table class="widefat striped" style="margin-top:1em"><thead><tr><th>When</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Note</th></tr></thead><tbody>
            <?php foreach ($ledger as $l) : ?>
                <tr><td><?php echo esc_html(wp_date('j M Y H:i', (int)$l['created_at'])); ?></td><td><?php echo esc_html($l['type']); ?></td>
                    <td><?php echo ((int)$l['amount_micro'] < 0 ? '-' : '+') . esc_html(p360_micro_money(abs((int)$l['amount_micro']))); ?></td>
                    <td><?php echo esc_html(p360_micro_money((int)$l['balance_after_micro'])); ?></td><td><?php echo esc_html($l['note']); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:2em" onsubmit="return confirm('Delete this wallet and all its files? This cannot be undone.');">
                <?php wp_nonce_field('p360_wallet_action'); ?>
                <input type="hidden" name="action" value="p360_wallet_action"><input type="hidden" name="wallet" value="<?php echo esc_attr($sel['id']); ?>"><input type="hidden" name="do" value="delete">
                <h3>Delete wallet (data deletion request)</h3>
                <input name="confirm" type="text" placeholder="Type DELETE to confirm"> <button class="button">Delete wallet and files</button>
            </form>
        <?php else :
            $where = $q !== '' ? $wpdb->prepare("WHERE email LIKE %s", '%' . $wpdb->esc_like($q) . '%') : '';
            $rows = $wpdb->get_results("SELECT id, email, balance_micro, expires_at, created_at FROM $t $where ORDER BY created_at DESC LIMIT 100", ARRAY_A) ?: []; ?>
            <table class="widefat striped" style="margin-top:1em"><thead><tr><th>Email</th><th>Balance</th><th>Expires</th><th>Created</th></tr></thead><tbody>
            <?php foreach ($rows as $w) : ?>
                <tr><td><a href="<?php echo esc_url(add_query_arg('wallet', $w['id'], admin_url('options-general.php?page=p360-wallets'))); ?>"><?php echo esc_html($w['email']); ?></a></td>
                    <td><?php echo esc_html(p360_micro_money((int)$w['balance_micro'])); ?></td>
                    <td><?php echo $w['expires_at'] ? esc_html(wp_date('j M Y', (int)$w['expires_at'])) : '-'; ?></td>
                    <td><?php echo esc_html(wp_date('j M Y', (int)$w['created_at'])); ?></td></tr>
            <?php endforeach; if (!$rows) { echo '<tr><td colspan="4">No wallets yet.</td></tr>'; } ?></tbody></table>
        <?php endif; ?></div>
        <?php
    }
}
