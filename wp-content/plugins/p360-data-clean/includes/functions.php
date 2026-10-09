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
        'price_address'         => '0.09',   // Provero entry price is 0.046 per address
        'vat_rate'              => '20',     // percent, 0 to disable
        'min_records'           => '100',
        'max_records'           => '20000',
        'min_charge_pence'      => '300',    // Stripe's GBP minimum is 30p
        'max_upload_mb'         => '5',
        'retention_days'        => '7',     // customer files
        'credit_expiry_days'    => '365',   // unused records on an order
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
            'columnLabel' => 'an "email" column',
        ],
        'hlr' => [
            'label'   => 'Mobile (HLR) verification',
            'desc'    => 'Is the mobile number live, dead or out of network, with current and original network.',
            'column'  => 'phone_number',
            'aliases' => ['phone_number', 'phone', 'mobile', 'mobile_number', 'telephone', 'tel'],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'phone_number' => '07700900123'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'phone_number' => '+447700900456']],
            'price'   => (float)p360_opt('price_hlr'),
            'columnLabel' => 'a "phone_number" column',
        ],
        'tps' => [
            'label'   => 'TPS / CTPS screening',
            'desc'    => 'Is the UK number on the TPS or Corporate TPS do-not-call registers.',
            'column'  => 'phone_number',
            'aliases' => ['phone_number', 'phone', 'mobile', 'mobile_number', 'telephone', 'tel'],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'phone_number' => '01302778473'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'phone_number' => '02071234567']],
            'price'   => (float)p360_opt('price_tps'),
            'columnLabel' => 'a "phone_number" column',
        ],
        'address' => [
            'label'   => 'UK address validation (PAF)',
            'desc'    => 'Check and standardise UK addresses against the Royal Mail Postcode Address File: verified, needs review or no match.',
            'column'  => 'postcode',
            'columnLabel' => 'address columns (see "Accepted format")',
            // several input columns; a row's value is the combination of whichever of these it has
            'fields'  => [
                'full_address'   => ['full_address', 'address', 'fulladdress'],
                'address_line_1' => ['address_line_1', 'address1', 'address_1', 'address_line1', 'line1', 'line_1', 'street'],
                'address_line_2' => ['address_line_2', 'address2', 'address_2', 'address_line2', 'line2', 'line_2'],
                'address_line_3' => ['address_line_3', 'address3', 'address_3', 'address_line3', 'line3', 'line_3'],
                'town_city'      => ['town_city', 'town', 'city', 'post_town', 'posttown'],
                'county'         => ['county'],
                'postcode'       => ['postcode', 'post_code', 'postal_code', 'zip', 'zip_code'],
            ],
            'aliases' => [],
            'sample'  => [['first_name' => 'Jane', 'surname' => 'Smith', 'address_line_1' => '10 Downing Street', 'address_line_2' => '', 'town_city' => 'London', 'postcode' => 'SW1A 2AA'],
                          ['first_name' => 'Tom', 'surname' => 'Jones', 'address_line_1' => '20 Canterbury Crescent', 'address_line_2' => '', 'town_city' => 'Sheffield', 'postcode' => 'S10 3RX']],
            'price'   => (float)p360_opt('price_address'),
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

/**
 * Normalise a phone number to E.164, assuming UK when no country code is given.
 * Accepts digits with spaces, dots, dashes and brackets, e.g. 07700 900123, +44 7700 900123, +44(0)7700 900123,
 * +44-7700-900123, 0044 7700 900123. Returns '' if the value cannot be a phone number (letters, stray symbols).
 */
function p360_normalise_uk_phone(string $raw): string {
    $p = trim($raw);
    if ($p === '' || preg_match('/[^\d\s+().\-]/', $p) || preg_match('/.\+/', $p)) { return ''; }
    $p = preg_replace('/\(\s*0\s*\)/', '', $p);              // "(0)" trunk-prefix marker
    $digits = preg_replace('/\D/', '', $p);
    if ($digits === '') { return ''; }
    $intl = $p[0] === '+';
    if (!$intl && strpos($digits, '00') === 0) { $intl = true; $digits = substr($digits, 2); }
    if ($intl) {
        if (strpos($digits, '44') === 0) { return '+44' . ltrim(substr($digits, 2), '0'); }   // +44 07700... -> +447700...
        return '+' . $digits;
    }
    if ($digits[0] === '0') { return '+44' . substr($digits, 1); }
    if (strpos($digits, '44') === 0 && strlen($digits) >= 11) { return '+44' . ltrim(substr($digits, 2), '0'); }
    return '+44' . $digits;
}

/** Local sanity checks so obviously bad values never cost an API call. Returns an error message or ''. */
function p360_local_error(string $service, string $raw): string {
    $v = trim($raw);
    if ($service === 'email') {
        if (strlen($v) > 254 || !filter_var($v, FILTER_VALIDATE_EMAIL) || !preg_match('/^[^@]+@[^@\s]+\.[^@\s.]{2,}$/', $v)) {
            return 'Invalid email address format';
        }
        return '';
    }
    if ($service === 'address') {
        $a = json_decode($v, true);
        if (!is_array($a) || !$a) { return 'No address supplied'; }
        if (isset($a['postcode']) && strlen($a['postcode']) > 16) { return 'Postcode is too long'; }
        if (!isset($a['full_address']) && !isset($a['address_line_1']) && !isset($a['postcode'])) { return 'Not enough address detail (need an address line, full address or postcode)'; }
        return '';
    }
    $p = p360_normalise_uk_phone($v);
    if ($p === '') { return 'Invalid phone number (digits only, with optional + ( ) - . and spaces)'; }
    $digits = strlen($p) - 1;
    if ($digits < 8 || $digits > 15) { return 'Invalid phone number length'; }
    if (strpos($p, '+44') === 0 && ($digits - 2 < 9 || $digits - 2 > 10)) { return 'Invalid UK phone number length'; }
    if ($service === 'tps' && strpos($p, '+44') !== 0) { return 'TPS screening only supports UK numbers'; }
    return '';
}

/** Stop spreadsheet apps executing formulas in cells we write. */
function p360_cell($v): string {
    if (is_bool($v)) { return $v ? 'Yes' : 'No'; }
    $s = is_scalar($v) ? (string)$v : '';
    if (preg_match('/^\+\d+$/', $s)) { return $s; }   // plain E.164 numbers are safe and should stay readable
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
}
