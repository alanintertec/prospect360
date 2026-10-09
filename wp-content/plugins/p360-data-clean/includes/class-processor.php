<?php
defined('ABSPATH') || exit;

/**
 * Handles uploaded CSV files in resumable chunks. The browser drives it by calling /process repeatedly,
 * so no cron or long-running PHP request is needed and a closed tab can simply be resumed.
 *
 * Billing: an upload costs (rows with a value) x (that service's price per record), taken from the wallet when the
 * file is accepted. Blank rows are free; the customer is responsible for the quality of their data. Repeated values
 * are still billed per row but only looked up once with Provero. Rows that fail because of a lookup/network/provider
 * fault (not bad input) are refunded to the wallet.
 */
final class P360_Processor {

    const CHUNK_ROWS = 40;

    /** Pick the column the service needs, ignoring case/spacing. */
    private static function find_column(array $headers, array $aliases): int {
        foreach ($headers as $i => $h) {
            $norm = str_replace([' ', '-'], '_', strtolower(trim($h)));
            $norm = str_replace('e_mail', 'e-mail', $norm);
            if (in_array($norm, $aliases, true) || in_array(strtolower(trim($h)), $aliases, true)) { return $i; }
        }
        return -1;
    }

    /** @return array{col:int,fields:array<string,int>,error:string} */
    private static function map_columns(string $service, array $svc, array $headers): array {
        if ($service !== 'address') {
            $col = self::find_column($headers, $svc['aliases']);
            return ['col' => $col, 'fields' => [], 'error' => $col < 0 ? "Could not find a \"{$svc['column']}\" column in your header row. Download the sample CSV to see the expected format." : ''];
        }
        $fields = [];
        foreach ($svc['fields'] as $name => $aliases) {
            $i = self::find_column($headers, $aliases);
            if ($i >= 0) { $fields[$name] = $i; }
        }
        $ok = isset($fields['full_address']) || (isset($fields['postcode']) && (isset($fields['address_line_1']) || isset($fields['town_city'])));
        return ['col' => -1, 'fields' => $fields, 'error' => $ok ? '' :
            'We need either a "full_address" column, or a "postcode" column plus "address_line_1" (and ideally "town_city"). Download the sample CSV to see the expected format.'];
    }

    /** The value a row is billed, looked up and de-duplicated on ('' = blank). Addresses combine several columns into one JSON string. */
    public static function row_value(string $service, array $meta, array $r): string {
        if ($service === 'address') {
            $out = [];
            foreach ($meta['fields'] ?? [] as $name => $i) {
                $v = trim((string)preg_replace('/\s+/', ' ', (string)($r[$i] ?? '')));
                if ($v !== '') { $out[$name] = $v; }
            }
            return $out ? (string)wp_json_encode($out) : '';
        }
        return trim((string)($r[$meta['col']] ?? ''));
    }

    private static function is_blank_row($row): bool {
        if (!is_array($row)) { return true; }
        foreach ($row as $cell) { if (trim((string)$cell) !== '') { return false; } }
        return true;
    }

    /** Identity of a value for caching: the same address/number written differently counts once. */
    private static function key(string $service, string $value): string {
        $v = trim($value);
        if ($v === '') { return ''; }
        if ($service === 'email' || $service === 'address') { return strtolower($v); }
        $n = p360_normalise_uk_phone($v);
        return $n !== '' ? $n : 'raw:' . strtolower($v);
    }

    /** Failures that are our/provider's fault (not the customer's data) and so are refunded. */
    private static function is_provider_fault(string $reason): bool {
        return strpos($reason, 'Lookup failed') === 0 || $reason === 'Unexpected response' || $reason === 'Could not be checked';
    }

    /**
     * Validate an uploaded file, take its cost from the wallet and register a job.
     * @return array{ok:bool,error?:string,job?:string}
     */
    public static function accept_upload(array $wallet, string $service, array $file): array {
        $services = p360_services();
        if (!isset($services[$service])) { return ['ok' => false, 'error' => 'Please choose a service.']; }
        $svc = $services[$service];
        $max = (int)((float)p360_opt('max_upload_mb') * 1024 * 1024);
        if (P360_Orders::active_job($wallet['id'])) { return ['ok' => false, 'error' => 'Please wait for your current file to finish first.']; }
        if ((int)$wallet['balance_micro'] <= 0) { return ['ok' => false, 'error' => 'Your balance is empty. Please top up first.']; }
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'The upload failed. Please try again.'];
        }
        if ($file['size'] > $max) { return ['ok' => false, 'error' => 'File too large (maximum ' . p360_opt('max_upload_mb') . 'MB).']; }
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            return ['ok' => false, 'error' => 'Only .csv files are accepted.'];
        }

        $name = bin2hex(random_bytes(16)) . '.csv';
        $dir = p360_storage_dir();
        $in = "$dir/$name";
        if (!move_uploaded_file($file['tmp_name'], $in)) { return ['ok' => false, 'error' => 'Could not store the file.']; }

        $fail = function (string $msg) use ($in): array { @unlink($in); return ['ok' => false, 'error' => $msg]; };
        $h = fopen($in, 'r');
        if (!$h) { return $fail('Could not read the file.'); }
        $first = (string)fgets($h);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $delim = (string)array_key_first($delims);
        rewind($h);
        $headers = fgetcsv($h, 0, $delim);
        if (!$headers) { fclose($h); return $fail('The file looks empty.'); }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        $headers = array_map('trim', array_map('strval', $headers));
        $map = self::map_columns($service, $svc, $headers);
        if ($map['error'] !== '') { fclose($h); return $fail($map['error']); }
        $meta = ['col' => $map['col'], 'fields' => $map['fields'], 'delim' => $delim, 'ncols' => count($headers)];
        $offset = ftell($h);
        $rows = 0;
        $billed = 0;
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if (self::is_blank_row($r)) { continue; }
            $rows++;
            if (self::row_value($service, $meta, $r) !== '') { $billed++; }
        }
        fclose($h);
        if ($rows === 0) { return $fail('No data rows found under the header.'); }
        if ($billed === 0) { return $fail($service === 'address' ? 'No addresses found in your address columns.' : "No values found in the \"{$svc['column']}\" column."); }

        $price = p360_price_micro($service);
        $cost = $billed * $price;
        if ($cost > (int)$wallet['balance_micro']) {
            return $fail('This file has ' . number_format($billed) . ' rows to check, costing ' . p360_micro_money($cost) . ' (' . $svc['label'] . ' at ' .
                p360_micro_money($price) . ' per row), but your balance is ' . p360_micro_money((int)$wallet['balance_micro']) . '. Please top up or use a smaller file.');
        }

        $out_name = bin2hex(random_bytes(16)) . '.csv';
        $o = fopen("$dir/$out_name", 'w');
        fputcsv($o, array_merge($headers, P360_Provero::columns($service)));
        fclose($o);

        $job_id = bin2hex(random_bytes(12));
        if (!P360_Wallets::debit($wallet['id'], $cost, 'spend', $job_id, $svc['label'] . ': ' . number_format($billed) . ' rows')) {
            @unlink("$dir/$out_name");
            return $fail('Your balance is too low for this file. Please top up.');
        }
        P360_Orders::job_create([
            'id' => $job_id, 'wallet_id' => $wallet['id'], 'service' => $service, 'status' => 'processing',
            'in_file' => $name, 'out_file' => $out_name, 'total_rows' => $rows, 'billed' => $billed,
            'cost_micro' => $cost, 'price_micro' => $price, 'done_rows' => 0, 'in_offset' => $offset,
            'job' => wp_json_encode($meta), 'created_at' => time(),
        ]);
        return ['ok' => true, 'job' => $job_id];
    }

    private static function read_cache(string $path): array {
        $c = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($c) ? $c : [];
    }

    /**
     * Process the next chunk of a job. Safe against concurrent calls (per-job MySQL lock).
     * @return array progress for the UI
     */
    public static function process_chunk(array $wallet, string $job_id): array {
        global $wpdb;
        $lockname = 'p360_' . $job_id;
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockname)) !== 1) {
            return self::progress(P360_Orders::job_get($job_id));   // another request is working on it
        }
        try {
            $j = P360_Orders::job_get($job_id);
            if (!$j || $j['wallet_id'] !== $wallet['id']) { return ['status' => 'missing']; }
            if ($j['status'] !== 'processing') { return self::progress($j); }
            @set_time_limit(120);

            $service = $j['service'];
            $meta = json_decode((string)$j['job'], true);
            $dir = p360_storage_dir();
            $in = fopen("$dir/{$j['in_file']}", 'r');
            if (!$in) { throw new RuntimeException('input missing'); }
            fseek($in, (int)$j['in_offset']);

            $rows = [];
            while (count($rows) < self::CHUNK_ROWS && ($r = fgetcsv($in, 0, $meta['delim'])) !== false) {
                if (self::is_blank_row($r)) { continue; }
                $rows[] = array_slice(array_pad(array_map('strval', $r), $meta['ncols'], ''), 0, $meta['ncols']);
            }
            $new_offset = ftell($in);
            $eof = feof($in) || fgetc($in) === false;
            fclose($in);

            // look up each distinct value once per file: anything seen in an earlier chunk comes from the cache
            $cache_path = "$dir/{$j['out_file']}.cache";
            $cache = self::read_cache($cache_path);
            $todo = [];
            foreach ($rows as $r) {
                $v = self::row_value($service, $meta, $r);
                $k = self::key($service, $v);
                if ($k !== '' && !isset($cache[$k]) && !isset($todo[$k])) { $todo[$k] = $v; }
            }
            if ($todo) {
                $keys = array_keys($todo);
                $found = P360_Provero::lookup($service, array_values($todo));
                if ($found['fatal'] !== '') {
                    self::notify_admin($found['fatal'], $j);
                    P360_Orders::job_update($job_id, ['message' => 'Processing is temporarily paused. We have been notified and it will resume shortly - please keep this page open or come back later.']);
                    return self::progress(P360_Orders::job_get($job_id)) + ['paused' => true];
                }
                foreach ($keys as $idx => $k) {
                    if (isset($found['results'][$idx])) { $cache[$k] = $found['results'][$idx]['cols']; }
                }
                $tmp = $cache_path . '.' . bin2hex(random_bytes(3)) . '.tmp';
                file_put_contents($tmp, wp_json_encode($cache));
                rename($tmp, $cache_path);
            }

            $n = count(P360_Provero::columns($service));
            $stats = $meta['stats'] ?? ['ok' => 0, 'blank' => 0, 'invalid' => 0, 'reasons' => []];
            $faults = 0;
            $out = fopen("$dir/{$j['out_file']}", 'a');
            flock($out, LOCK_EX);
            foreach ($rows as $r) {
                $k = self::key($service, self::row_value($service, $meta, $r));
                if ($k === '') {
                    $cols = array_fill(0, $n, '');
                    $cols[$n - 1] = 'No value supplied';
                    $stats['blank']++;
                } else {
                    $cols = $cache[$k] ?? null;
                    if ($cols === null) { $cols = array_fill(0, $n, ''); $cols[$n - 1] = 'Lookup failed'; }
                    $reason = (string)$cols[$n - 1];
                    if ($reason === '') {
                        $stats['ok']++;
                    } else {
                        $stats['invalid']++;
                        if (self::is_provider_fault($reason)) { $faults++; }
                        if (!isset($stats['reasons'][$reason]) && count($stats['reasons']) >= 15) { $reason = 'Other'; }
                        $stats['reasons'][$reason] = ($stats['reasons'][$reason] ?? 0) + 1;
                    }
                }
                fputcsv($out, array_merge($r, $cols));
            }
            flock($out, LOCK_UN);
            fclose($out);

            $fields = ['done_rows' => (int)$j['done_rows'] + count($rows), 'in_offset' => $new_offset, 'message' => ''];
            if ($faults > 0) {
                // our/provider's fault, not the customer's data: give the money back
                $refund = $faults * (int)$j['price_micro'];
                P360_Wallets::credit($wallet['id'], $refund, 'refund', $job_id, $faults . ' failed lookup' . ($faults > 1 ? 's' : '') . ' refunded');
                $fields['refunded_micro'] = (int)$j['refunded_micro'] + $refund;
            }
            $meta['stats'] = $stats;
            $fields['job'] = wp_json_encode($meta);
            if ($eof || !$rows) { $fields['status'] = 'complete'; @unlink($cache_path); @unlink("$dir/{$j['in_file']}"); $fields['in_file'] = ''; }
            P360_Orders::job_update($job_id, $fields);
            return self::progress(P360_Orders::job_get($job_id));
        } catch (Throwable $e) {
            P360_Orders::job_update($job_id, ['message' => 'Something went wrong while processing. Please retry.']);
            return self::progress(P360_Orders::job_get($job_id) ?: ['id' => $job_id, 'status' => 'processing', 'service' => '', 'done_rows' => 0, 'total_rows' => 0, 'billed' => 0, 'cost_micro' => 0, 'refunded_micro' => 0, 'message' => '', 'created_at' => 0]);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockname));
        }
    }

    /** One job as the UI sees it. */
    public static function progress(array $j): array {
        $meta = json_decode((string)($j['job'] ?? ''), true);
        return [
            'stats'    => is_array($meta) ? ($meta['stats'] ?? null) : null,
            'job'      => $j['id'],
            'service'  => $j['service'],
            'status'   => $j['status'],
            'done'     => (int)$j['done_rows'],
            'total'    => (int)$j['total_rows'],
            'billed'   => (int)$j['billed'],
            'cost'     => (int)$j['cost_micro'],
            'refunded' => (int)$j['refunded_micro'],
            'created'  => (int)$j['created_at'],
            'message'  => $j['message'],
        ];
    }

    /** The signed-in wallet as the UI sees it. */
    public static function wallet_view(array $w): array {
        return [
            'email'   => $w['email'],
            'balance' => (int)$w['balance_micro'],
            'expires' => (int)$w['expires_at'],
            'csrf'    => P360_Wallets::csrf(),
            'jobs'    => array_map([__CLASS__, 'progress'], array_slice(P360_Orders::jobs_for_wallet($w['id']), 0, 30)),
            'ledger'  => array_map(function ($l) {
                return ['type' => $l['type'], 'amount' => (int)$l['amount_micro'], 'balance' => (int)$l['balance_after_micro'], 'note' => $l['note'], 'time' => (int)$l['created_at']];
            }, P360_Wallets::ledger($w['id'], 25)),
        ];
    }

    /** Tell the site owner once an hour when the supplier account blocks processing. */
    private static function notify_admin(string $reason, array $job): void {
        if (get_transient('p360_admin_notified')) { return; }
        set_transient('p360_admin_notified', 1, HOUR_IN_SECONDS);
        wp_mail(get_option('admin_email'), 'Prospect360 Data Clean is paused',
            "$reason\n\nA {$job['service']} file (job {$job['id']}) is waiting. Fix the Provero account and the customer's page will resume automatically.");
    }
}
