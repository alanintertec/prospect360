<?php
defined('ABSPATH') || exit;

/**
 * Handles an uploaded CSV in resumable chunks. The browser drives it by calling /process repeatedly,
 * so no cron or long-running PHP request is needed and a closed tab can simply be resumed.
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

    private static function is_blank_row($row): bool {
        if (!is_array($row)) { return true; }
        foreach ($row as $cell) { if (trim((string)$cell) !== '') { return false; } }
        return true;
    }

    /**
     * Validate and register an uploaded file for a paid order.
     * @return array{ok:bool,error?:string}
     */
    public static function accept_upload(array $order, array $file): array {
        $svc = p360_services()[$order['service']];
        $max = (int)((float)p360_opt('max_upload_mb') * 1024 * 1024);
        if ($order['status'] !== 'paid') { return ['ok' => false, 'error' => 'This order has already been used.']; }
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
        $col = self::find_column($headers, $svc['aliases']);
        if ($col < 0) {
            fclose($h);
            return $fail("Could not find a \"{$svc['column']}\" column in your header row. Download the sample CSV to see the expected format.");
        }
        $offset = ftell($h);
        $rows = 0;
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if (!self::is_blank_row($r)) { $rows++; }
        }
        fclose($h);
        if ($rows === 0) { return $fail('No data rows found under the header.'); }
        if ($rows > (int)$order['records']) {
            return $fail("Your file has $rows rows but this order covers {$order['records']} records. Please place a new order for the larger size.");
        }

        $out_name = bin2hex(random_bytes(16)) . '.csv';
        $o = fopen("$dir/$out_name", 'w');
        fputcsv($o, array_merge($headers, P360_Provero::columns($order['service'])));
        fclose($o);

        P360_Orders::update($order['id'], [
            'status'     => 'processing',
            'in_file'    => $name,
            'out_file'   => $out_name,
            'total_rows' => $rows,
            'done_rows'  => 0,
            'in_offset'  => $offset,
            'job'        => wp_json_encode(['col' => $col, 'delim' => $delim, 'ncols' => count($headers)]),
            'message'    => '',
        ]);
        return ['ok' => true];
    }

    /**
     * Process the next chunk. Safe against concurrent calls (per-order MySQL lock).
     * @return array progress for the UI
     */
    public static function process_chunk(array $order): array {
        global $wpdb;
        $lockname = 'p360_' . $order['id'];
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockname)) !== 1) {
            return self::progress(P360_Orders::get($order['id']));   // another request is working on it
        }
        try {
            $order = P360_Orders::get($order['id']);
            if ($order['status'] !== 'processing') { return self::progress($order); }
            @set_time_limit(120);

            $job = json_decode((string)$order['job'], true);
            $dir = p360_storage_dir();
            $in = fopen("$dir/{$order['in_file']}", 'r');
            if (!$in) { throw new RuntimeException('input missing'); }
            fseek($in, (int)$order['in_offset']);

            $rows = [];
            while (count($rows) < self::CHUNK_ROWS && ($r = fgetcsv($in, 0, $job['delim'])) !== false) {
                if (self::is_blank_row($r)) { continue; }
                $r = array_slice(array_pad(array_map('strval', $r), $job['ncols'], ''), 0, $job['ncols']);
                $rows[] = $r;
            }
            $new_offset = ftell($in);
            $eof = feof($in) || fgetc($in) === false;
            fclose($in);

            // unique, non-empty values only: blanks are free for us and duplicates are only looked up once
            $unique = [];
            foreach ($rows as $r) {
                $v = trim($r[$job['col']]);
                if ($v !== '' && !isset($unique[$v])) { $unique[$v] = $v; }
            }
            $found = ['results' => [], 'fatal' => ''];
            if ($unique) {
                $found = P360_Provero::lookup($order['service'], array_values($unique));
            }
            if ($found['fatal'] !== '') {
                self::notify_admin($found['fatal'], $order);
                P360_Orders::update($order['id'], ['message' => 'Processing is temporarily paused. We have been notified and it will resume shortly - please keep this page open or come back later.']);
                $o = P360_Orders::get($order['id']);
                $p = self::progress($o);
                $p['paused'] = true;
                return $p;
            }

            $n = count(P360_Provero::columns($order['service']));
            $out = fopen("$dir/{$order['out_file']}", 'a');
            flock($out, LOCK_EX);
            $idx = array_flip(array_values($unique));
            foreach ($rows as $r) {
                $v = trim($r[$job['col']]);
                if ($v === '') {
                    $cols = array_fill(0, $n, '');
                    $cols[$n - 1] = 'No value supplied';
                } else {
                    $cols = $found['results'][$idx[$v]]['cols'] ?? null;
                    if ($cols === null) { $cols = array_fill(0, $n, ''); $cols[$n - 1] = 'Lookup failed'; }
                }
                fputcsv($out, array_merge($r, $cols));
            }
            flock($out, LOCK_UN);
            fclose($out);

            $done = (int)$order['done_rows'] + count($rows);
            $fields = ['done_rows' => $done, 'in_offset' => $new_offset, 'message' => ''];
            if ($eof || !$rows) { $fields['status'] = 'complete'; }
            P360_Orders::update($order['id'], $fields);
            return self::progress(P360_Orders::get($order['id']));
        } catch (Throwable $e) {
            P360_Orders::update($order['id'], ['message' => 'Something went wrong while processing. Please retry.']);
            return self::progress(P360_Orders::get($order['id']));
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockname));
        }
    }

    public static function progress(array $o): array {
        return [
            'status'  => $o['status'],
            'done'    => (int)$o['done_rows'],
            'total'   => (int)$o['total_rows'],
            'records' => (int)$o['records'],
            'service' => $o['service'],
            'message' => $o['message'],
        ];
    }

    /** Tell the site owner once an hour when the supplier account blocks processing. */
    private static function notify_admin(string $reason, array $order): void {
        if (get_transient('p360_admin_notified')) { return; }
        set_transient('p360_admin_notified', 1, HOUR_IN_SECONDS);
        wp_mail(get_option('admin_email'), 'Prospect360 Data Clean is paused',
            "$reason\n\nOrder {$order['id']} ({$order['service']}, {$order['records']} records) is waiting. " .
            "Fix the Provero account and the customer's page will resume automatically.");
    }
}
