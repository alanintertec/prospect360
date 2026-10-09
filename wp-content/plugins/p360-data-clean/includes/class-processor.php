<?php
defined('ABSPATH') || exit;

/**
 * Handles uploaded CSV files in resumable chunks. The browser drives it by calling /process repeatedly,
 * so no cron or long-running PHP request is needed and a closed tab can simply be resumed.
 *
 * Billing: an order is a balance of records. Each upload reserves one record per row that has a value in the
 * target column (blank cells are free; the customer is responsible for the quality of their data). Repeated
 * values within a file are still billed per row, but only looked up once with Provero.
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

    /**
     * Map the header row to the columns a service needs.
     * @return array{col:int,fields:array<string,int>,error:string}
     */
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

    /** Identity of a value for billing/caching: same address or same number written differently counts once. */
    private static function key(string $service, string $value): string {
        $v = trim($value);
        if ($v === '') { return ''; }
        if ($service === 'email' || $service === 'address') { return strtolower($v); }
        $n = p360_normalise_uk_phone($v);
        return $n !== '' ? $n : 'raw:' . strtolower($v);
    }

    /**
     * Validate an uploaded file, reserve records from the order balance and register a job.
     * @return array{ok:bool,error?:string,job?:string}
     */
    public static function accept_upload(array $order, array $file): array {
        $svc = p360_services()[$order['service']];
        $max = (int)((float)p360_opt('max_upload_mb') * 1024 * 1024);
        if ($order['status'] !== 'paid') { return ['ok' => false, 'error' => 'This order is not available.']; }
        $remaining = P360_Orders::remaining($order);
        if ($remaining < 1) { return ['ok' => false, 'error' => 'All records on this order have been used.']; }
        if (P360_Orders::active_job($order['id'])) { return ['ok' => false, 'error' => 'Please wait for your current file to finish first.']; }
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
        $map = self::map_columns($order['service'], $svc, $headers);
        if ($map['error'] !== '') { fclose($h); return $fail($map['error']); }
        $meta = ['col' => $map['col'], 'fields' => $map['fields'], 'delim' => $delim, 'ncols' => count($headers)];
        $offset = ftell($h);
        $rows = 0;
        $billed = 0;
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if (self::is_blank_row($r)) { continue; }
            $rows++;
            if (self::row_value($order['service'], $meta, $r) !== '') { $billed++; }
        }
        fclose($h);
        if ($rows === 0) { return $fail('No data rows found under the header.'); }
        if ($billed === 0) { return $fail($order['service'] === 'address' ? 'No addresses found in your address columns.' : "No values found in the \"{$svc['column']}\" column."); }
        if ($billed > $remaining) {
            return $fail("Your file has " . number_format($billed) . " rows with a value but only " . number_format($remaining) .
                " records remain on this order. Remove some rows, or place a new order.");
        }

        $out_name = bin2hex(random_bytes(16)) . '.csv';
        $o = fopen("$dir/$out_name", 'w');
        fputcsv($o, array_merge($headers, P360_Provero::columns($order['service'])));
        fclose($o);

        if (!P360_Orders::reserve($order['id'], $billed)) {
            @unlink("$dir/$out_name");
            return $fail('Not enough records remain on this order.');
        }
        $job_id = bin2hex(random_bytes(12));
        P360_Orders::job_create([
            'id' => $job_id, 'order_id' => $order['id'], 'status' => 'processing',
            'in_file' => $name, 'out_file' => $out_name, 'total_rows' => $rows, 'billed' => $billed,
            'done_rows' => 0, 'in_offset' => $offset,
            'job' => wp_json_encode($meta),
            'created_at' => time(),
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
    public static function process_chunk(array $order, string $job_id): array {
        global $wpdb;
        $lockname = 'p360_' . $job_id;
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockname)) !== 1) {
            return self::progress(P360_Orders::job_get($job_id));   // another request is working on it
        }
        try {
            $j = P360_Orders::job_get($job_id);
            if (!$j || $j['order_id'] !== $order['id']) { return ['status' => 'missing']; }
            if ($j['status'] !== 'processing') { return self::progress($j); }
            @set_time_limit(120);

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

            // look up each unique value once per file: anything seen in an earlier chunk comes from the cache
            $cache_path = "$dir/{$j['out_file']}.cache";
            $cache = self::read_cache($cache_path);
            $todo = [];
            foreach ($rows as $r) {
                $k = self::key($order['service'], self::row_value($order['service'], $meta, $r));
                if ($k !== '' && !isset($cache[$k]) && !isset($todo[$k])) { $todo[$k] = self::row_value($order['service'], $meta, $r); }
            }
            if ($todo) {
                $keys = array_keys($todo);
                $found = P360_Provero::lookup($order['service'], array_values($todo));
                if ($found['fatal'] !== '') {
                    self::notify_admin($found['fatal'], $order);
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

            $n = count(P360_Provero::columns($order['service']));
            $stats = $meta['stats'] ?? ['ok' => 0, 'blank' => 0, 'invalid' => 0, 'reasons' => []];
            $out = fopen("$dir/{$j['out_file']}", 'a');
            flock($out, LOCK_EX);
            foreach ($rows as $r) {
                $k = self::key($order['service'], self::row_value($order['service'], $meta, $r));
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
                        if (!isset($stats['reasons'][$reason]) && count($stats['reasons']) >= 15) { $reason = 'Other'; }
                        $stats['reasons'][$reason] = ($stats['reasons'][$reason] ?? 0) + 1;
                    }
                }
                fputcsv($out, array_merge($r, $cols));
            }
            flock($out, LOCK_UN);
            fclose($out);

            $meta['stats'] = $stats;
            $fields = ['done_rows' => (int)$j['done_rows'] + count($rows), 'in_offset' => $new_offset, 'message' => '', 'job' => wp_json_encode($meta)];
            if ($eof || !$rows) { $fields['status'] = 'complete'; @unlink($cache_path); @unlink("$dir/{$j['in_file']}"); $fields['in_file'] = ''; }
            P360_Orders::job_update($job_id, $fields);
            return self::progress(P360_Orders::job_get($job_id));
        } catch (Throwable $e) {
            P360_Orders::job_update($job_id, ['message' => 'Something went wrong while processing. Please retry.']);
            return self::progress(P360_Orders::job_get($job_id) ?: ['id' => $job_id, 'status' => 'processing', 'done_rows' => 0, 'total_rows' => 0, 'billed' => 0, 'message' => '', 'created_at' => 0]);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockname));
        }
    }

    /** One job as the UI sees it. */
    public static function progress(array $j): array {
        $meta = json_decode((string)($j['job'] ?? ''), true);
        return [
            'stats'   => is_array($meta) ? ($meta['stats'] ?? null) : null,
            'job'     => $j['id'],
            'status'  => $j['status'],
            'done'    => (int)$j['done_rows'],
            'total'   => (int)$j['total_rows'],
            'billed'  => (int)$j['billed'],
            'created' => (int)$j['created_at'],
            'message' => $j['message'],
        ];
    }

    /** The order as the UI sees it: balance plus its files. */
    public static function order_view(array $o): array {
        $jobs = array_map([__CLASS__, 'progress'], P360_Orders::jobs($o['id']));
        return [
            'status'    => $o['status'],
            'service'   => $o['service'],
            'records'   => (int)$o['records'],
            'used'      => (int)$o['used_records'],
            'remaining' => P360_Orders::remaining($o),
            'expires'   => $o['status'] === 'pending' ? 0 : P360_Orders::expires_at($o),
            'jobs'      => $jobs,
        ];
    }

    /** Tell the site owner once an hour when the supplier account blocks processing. */
    private static function notify_admin(string $reason, array $order): void {
        if (get_transient('p360_admin_notified')) { return; }
        set_transient('p360_admin_notified', 1, HOUR_IN_SECONDS);
        wp_mail(get_option('admin_email'), 'Prospect360 Data Clean is paused',
            "$reason\n\nOrder {$order['id']} ({$order['service']}) has a file waiting. " .
            "Fix the Provero account and the customer's page will resume automatically.");
    }
}
