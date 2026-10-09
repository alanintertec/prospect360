<?php
defined('ABSPATH') || exit;

final class P360_Shortcode {

    public static function init(): void {
        add_shortcode('p360_data_clean', [__CLASS__, 'render']);
    }

    public static function render(): string {
        wp_enqueue_style('p360-data-clean', P360_URL . 'assets/app.css', [], P360_VERSION);
        wp_enqueue_script('p360-data-clean', P360_URL . 'assets/app.js', [], P360_VERSION, true);

        $services = [];
        foreach (p360_services() as $key => $s) {
            $services[$key] = ['label' => $s['label'], 'desc' => $s['desc'], 'price' => $s['price'], 'column' => $s['column']];
        }
        wp_localize_script('p360-data-clean', 'P360', [
            'rest'      => esc_url_raw(rest_url('p360/v1/')),
            'services'  => $services,
            'vat'       => (float)p360_opt('vat_rate') / 100,
            'minCharge' => (int)p360_opt('min_charge_pence'),
            'min'       => (int)p360_opt('min_records'),
            'max'       => (int)p360_opt('max_records'),
            'maxMb'     => (float)p360_opt('max_upload_mb'),
            'testMode'  => p360_test_mode(),
            'dryRun'    => p360_dry_run(),
        ]);
        return '<div id="p360-app" class="p360"><noscript>Please enable JavaScript to use this tool.</noscript></div>';
    }
}
