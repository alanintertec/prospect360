<?php
defined('ABSPATH') || exit;

/** Settings -> Data Clean */
final class P360_Admin {

    public static function init(): void {
        add_action('admin_menu', function () {
            add_options_page('Data Clean', 'Data Clean', 'manage_options', 'p360-data-clean', [__CLASS__, 'page']);
        });
        add_action('admin_init', function () {
            register_setting('p360', 'p360_settings', ['sanitize_callback' => [__CLASS__, 'sanitize']]);
        });
    }

    private static function fields(): array {
        return [
            'Provero'          => [['provero_token', 'Provero API token', 'password']],
            'Stripe'           => [['test_mode', 'Test mode (use the Stripe test keys; no real money is taken)', 'checkbox'],
                                   ['stripe_test_secret', 'Stripe TEST secret key (sk_test_...)', 'password'],
                                   ['stripe_test_webhook_secret', 'Stripe TEST webhook signing secret (whsec_...)', 'password'],
                                   ['stripe_secret', 'Stripe secret key (sk_live_...)', 'password'],
                                   ['stripe_webhook_secret', 'Stripe webhook signing secret (whsec_...)', 'password']],
            'Pricing (GBP per record, ex VAT)' => [['price_email', 'Email verification', 'text'], ['price_hlr', 'Mobile (HLR) verification', 'text'], ['price_tps', 'TPS / CTPS screening', 'text'],
                                   ['vat_rate', 'VAT rate %', 'text'], ['min_charge_pence', 'Minimum charge (pence, ex VAT)', 'text']],
            'Limits'           => [['min_records', 'Minimum records per order', 'text'], ['max_records', 'Maximum records per order', 'text'],
                                   ['max_upload_mb', 'Maximum upload size (MB)', 'text'], ['retention_days', 'Keep customer files for (days)', 'text']],
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
                <div class="notice notice-warning inline"><p><strong>TEST MODE is on.</strong> Stripe test keys are used and visitors see a test banner. Create a separate webhook in Stripe's <em>test</em> dashboard and put its secret in the TEST webhook field.</p></div>
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
                            <td><input type="checkbox" name="p360_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(p360_test_mode()); ?> <?php disabled(defined('P360_TEST_MODE')); ?>></td></tr>
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
}
