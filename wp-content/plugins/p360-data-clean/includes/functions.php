<?php
defined('ABSPATH') || exit;

function p360_defaults(): array {
    return [
        'provero_token'         => '',
        'stripe_secret'         => '',
        'stripe_webhook_secret' => '',
        'dry_run'               => '',       // '1' = fake Provero results (only honoured in test mode)
        'test_mode'             => '',       // '1' = use the Stripe test keys below
        'stripe_test_secret'    => '',
        'stripe_test_webhook_secret' => '',
        // GBP per record, ex VAT. Provero entry-tier cost: email 0.006, HLR 0.0042, TPS 0.004.
        'price_email'           => '0.012',
        'price_hlr'             => '0.009',
        'price_tps'             => '0.008',
        'vat_rate'              => '20',     // percent, 0 to disable
        'min_records'           => '100',
        'max_records'           => '20000',
        'min_charge_pence'      => '300',    // Stripe's GBP minimum is 30p
        'max_upload_mb'         => '5',
        'retention_days'        => '7',
    ];
}

function p360_opt(string $key) {
    $o = get_option('p360_settings', []);
    $d = p360_defaults();
    return (isset($o[$key]) && $o[$key] !== '') ? $o[$key] : ($d[$key] ?? '');
}

/** Secrets can be pinned in wp-config.php with these constants instead of the database. */
function p360_test_mode(): bool {
    return defined('P360_TEST_MODE') ? (bool)P360_TEST_MODE : p360_opt('test_mode') === '1';
}

/** Dry run: fake Provero results, no API calls. Only ever active while Stripe is in test mode. */
function p360_dry_run(): bool {
    if (!p360_test_mode()) { return false; }
    return defined('P360_DRY_RUN') ? (bool)P360_DRY_RUN : p360_opt('dry_run') === '1';
}

function p360_secret(string $name): string {
    if (p360_test_mode() && in_array($name, ['stripe_secret', 'stripe_webhook_secret'], true)) {
        $name = $name === 'stripe_secret' ? 'stripe_test_secret' : 'stripe_test_webhook_secret';
    }
    $map = [
        'provero_token'         => 'P360_PROVERO_TOKEN',
        'stripe_secret'         => 'P360_STRIPE_SECRET',
        'stripe_webhook_secret' => 'P360_STRIPE_WEBHOOK_SECRET',
        'stripe_test_secret'    => 'P360_STRIPE_TEST_SECRET',
        'stripe_test_webhook_secret' => 'P360_STRIPE_TEST_WEBHOOK_SECRET',
    ];
    if (isset($map[$name]) && defined($map[$name])) {
        return (string)constant($map[$name]);
    }
    return (string)p360_opt($name);
}

function p360_provero_base(): string { return defined('P360_PROVERO_BASE') ? P360_PROVERO_BASE : 'https://api.provero.io'; }
function p360_stripe_base(): string { return defined('P360_STRIPE_BASE') ? P360_STRIPE_BASE : 'https://api.stripe.com'; }

function p360_services(): array {
    return [
        'email' => [
            'label'   => 'Email verification',
            'desc'    => 'Syntax, mailbox deliverability, disposable, catch-all and role-based flags, plus a risk level.',
            'column'  => 'email',
            'aliases' => ['email', 'email_address', 'e-mail', 'emailaddress'],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'email' => 'jane.smith@example.com'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'email' => 'tom@example.org']],
            'price'   => (float)p360_opt('price_email'),
        ],
        'hlr' => [
            'label'   => 'Mobile (HLR) verification',
            'desc'    => 'Is the mobile number live, dead or out of network, with current and original network.',
            'column'  => 'phone_number',
            'aliases' => ['phone_number', 'phone', 'mobile', 'mobile_number', 'telephone', 'tel'],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'phone_number' => '07700900123'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'phone_number' => '+447700900456']],
            'price'   => (float)p360_opt('price_hlr'),
        ],
        'tps' => [
            'label'   => 'TPS / CTPS screening',
            'desc'    => 'Is the UK number on the TPS or Corporate TPS do-not-call registers.',
            'column'  => 'phone_number',
            'aliases' => ['phone_number', 'phone', 'mobile', 'mobile_number', 'telephone', 'tel'],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'phone_number' => '01302778473'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'phone_number' => '02071234567']],
            'price'   => (float)p360_opt('price_tps'),
        ],
    ];
}

/** Net price in pence for N records, before VAT. Mirrored in assets/app.js (the server is authoritative). */
function p360_net_pence(string $service, int $records): int {
    $s = p360_services()[$service];
    return max((int)p360_opt('min_charge_pence'), (int)ceil(round($records * $s['price'] * 100, 6)));
}
function p360_vat_pence(int $net): int { return (int)round($net * ((float)p360_opt('vat_rate') / 100)); }
function p360_money(int $pence): string { return '£' . number_format($pence / 100, 2); }

/** Private storage under uploads/. Files get random names and are only served through the REST download. */
function p360_storage_dir(): string {
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'p360-data-clean';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($dir . '/index.php', "<?php // silence\n");
    }
    return $dir;
}

function p360_normalise_uk_phone(string $raw): string {
    $p = preg_replace('/[^\d+]/', '', trim($raw));
    if ($p === '' || $p === null) { return ''; }
    if (strpos($p, '00') === 0) { $p = '+' . substr($p, 2); }
    if ($p[0] === '+') { return $p; }
    if ($p[0] === '0') { return '+44' . substr($p, 1); }
    if (strpos($p, '44') === 0) { return '+' . $p; }
    return '+44' . $p;
}

/** Stop spreadsheet apps executing formulas in cells we write. */
function p360_cell($v): string {
    if (is_bool($v)) { return $v ? 'Yes' : 'No'; }
    $s = is_scalar($v) ? (string)$v : '';
    if (preg_match('/^\+\d+$/', $s)) { return $s; }   // plain E.164 numbers are safe and should stay readable
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
}
