<?php
/**
 * Plugin Name: Prospect360 Data Clean
 * Description: Prepaid-credit data cleansing. Customers top up a wallet with Stripe (email sign-in, no passwords) and spend it on email, HLR, TPS and UK address validation through the Provero API. Use the [p360_data_clean] shortcode.
 * Version:     2.0.0
 * Requires PHP: 7.4
 * Author:      Intertec Data Solutions
 * Text Domain: p360-data-clean
 */

defined('ABSPATH') || exit;

define('P360_VERSION', '2.0.0');
define('P360_DIR', plugin_dir_path(__FILE__));
define('P360_URL', plugin_dir_url(__FILE__));

require_once P360_DIR . 'includes/functions.php';
require_once P360_DIR . 'includes/class-wallets.php';
require_once P360_DIR . 'includes/class-orders.php';
require_once P360_DIR . 'includes/class-stripe.php';
require_once P360_DIR . 'includes/class-provero.php';
require_once P360_DIR . 'includes/class-processor.php';
require_once P360_DIR . 'includes/class-rest.php';
require_once P360_DIR . 'includes/class-admin.php';
require_once P360_DIR . 'includes/class-shortcode.php';

register_activation_hook(__FILE__, function () {
    P360_Orders::install();
    p360_storage_dir();
    if (!wp_next_scheduled('p360_cleanup')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'p360_cleanup');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('p360_cleanup');
});

add_action('plugins_loaded', function () {
    if (get_option('p360_db_version') !== P360_VERSION) {
        P360_Orders::install();
        P360_Orders::migrate();
        P360_Orders::migrate_wallets();
        update_option('p360_db_version', P360_VERSION);
    }
    P360_Rest::init();
    P360_Admin::init();
    P360_Shortcode::init();
    add_action('p360_cleanup', ['P360_Orders', 'cleanup']);
});
