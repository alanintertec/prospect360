<?php
defined('ABSPATH') || exit;

/** Provero API client + mapping of API responses to CSV columns. */
final class P360_Provero {

    const CONCURRENCY = 8;

    private static function endpoint(string $service): string {
        return ['email' => '/api/validate/email', 'hlr' => '/api/validate/phone', 'tps' => '/api/validate/phone-tps'][$service];
    }

    public static function columns(string $service): array {
        switch ($service) {
            case 'email':
                return ['[EMAIL] Syntax Valid', '[EMAIL] Mailbox Deliverable', '[EMAIL] Catch All', '[EMAIL] Disposable',
                        '[EMAIL] Role Based', '[EMAIL] Risk Level', '[EMAIL] Check Result', '[EMAIL] Typo Suggestion', '[EMAIL] Error'];
            case 'hlr':
                return ['[PHONE] Normalised Number', '[PHONE] HLR Status', '[PHONE] Live', '[PHONE] Current Network', '[PHONE] Original Network', '[PHONE] Error'];
            default:
                return ['[PHONE] Formatted Number', '[PHONE] On TPS', '[PHONE] TPS Date', '[PHONE] On CTPS', '[PHONE] CTPS Date', '[PHONE] Error'];
        }
    }

    /**
     * Look up many values concurrently.
     * @param array<int,string> $values index => raw value
     * @return array{results: array<int,array>, fatal: string}  results[index] = ['cols' => string[]]; fatal is set when the
     *         account itself is the problem (bad token / no balance) and the job must pause rather than burn through rows.
     */
    public static function lookup(string $service, array $values): array {
        if (p360_dry_run()) { return self::fake($service, $values); }
        $results = [];
        $fatal = '';
        $token = p360_secret('provero_token');
        $url = rtrim(p360_provero_base(), '/') . self::endpoint($service);
        $keys = array_keys($values);

        foreach (array_chunk($keys, self::CONCURRENCY) as $group) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($group as $i) {
                $raw = $values[$i];
                $body = $service === 'email' ? ['email' => $raw] : ['phone' => p360_normalise_uk_phone($raw)];
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => wp_json_encode($body),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 25,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = [$ch, $body];
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) { curl_multi_select($mh, 1.0); }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $i => [$ch, $body]) {
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $json = json_decode((string)curl_multi_getcontent($ch), true);
                $err = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($code === 401 || $code === 402) {
                    $fatal = $code === 401 ? 'Provero rejected the API token.' : 'Provero account has insufficient balance.';
                    continue;   // leave unset so the row is retried after the account is fixed
                }
                $results[$i] = ['cols' => self::map($service, $body, $code, is_array($json) ? $json : [], $err)];
            }
            curl_multi_close($mh);
            if ($fatal !== '') { break; }
        }
        return ['results' => $results, 'fatal' => $fatal];
    }

    /** Deterministic fake responses shaped like the real API, run through the same mapping. No network calls. */
    private static function fake(string $service, array $values): array {
        $results = [];
        foreach ($values as $i => $raw) {
            $h = crc32($raw) % 5;
            if ($service === 'email') {
                $body = ['email' => $raw];
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $code = 422; $j = ['message' => 'The email field must be a valid email address.', 'errors' => ['email' => ['The email field must be a valid email address.']]];
                } else {
                    $code = 200;
                    $j = ['emailAddress' => $raw, 'isSyntaxValid' => true, 'isMailboxDeliverable' => $h !== 0, 'isCatchAll' => $h === 1,
                          'typoSuggestion' => $raw, 'isDisposable' => stripos($raw, 'mailinator') !== false,
                          'isRoleBased' => (bool)preg_match('/^(info|admin|sales|support)@/i', $raw),
                          'riskLevel' => $h === 0 ? 'HIGH' : ($h === 1 ? 'MEDIUM' : 'LOW'), 'checkResult' => 'dry_run'];
                }
            } else {
                $phone = p360_normalise_uk_phone($raw);
                $body = ['phone' => $phone];
                if (!preg_match('/^\+\d{10,15}$/', $phone) || ($service === 'tps' && strpos($phone, '+44') !== 0)) {
                    $code = 422; $j = ['message' => 'Invalid phone number', 'errors' => ['phone' => ['Invalid phone number']]];
                } elseif ($service === 'hlr') {
                    $status = [0 => 'Dead', 1 => 'Out of network'][$h] ?? 'Live';
                    $code = 200; $j = ['results' => [['to' => $phone, 'status' => $status, 'live' => $status === 'Live', 'currentNetwork' => 'Dry Run Mobile', 'originalNetwork' => 'Dry Run Mobile']]];
                } else {
                    $code = 200; $j = ['phone' => 'tel:' . $phone, 'onTps' => $h < 2, 'tpsRegisteredDate' => $h < 2 ? '2024-01-01' : '',
                                       'onCtps' => $h === 0, 'ctpsRegisteredDate' => $h === 0 ? '2024-01-01' : '', 'prettierPhoneNumber' => '0' . substr($phone, 3)];
                }
            }
            $results[$i] = ['cols' => self::map($service, $body, $code, $j, '')];
        }
        return ['results' => $results, 'fatal' => ''];
    }

    private static function error_text(int $code, array $j, string $curlErr): string {
        if ($curlErr !== '') { return 'Lookup failed (network)'; }
        if (!empty($j['errors']) && is_array($j['errors'])) {
            $first = reset($j['errors']);
            return is_array($first) ? (string)reset($first) : (string)$first;
        }
        if (!empty($j['requestError']['serviceException']['messageId'])) { return (string)$j['requestError']['serviceException']['messageId']; }
        if (!empty($j['message'])) { return (string)$j['message']; }
        return "Lookup failed (HTTP $code)";
    }

    private static function map(string $service, array $req, int $code, array $j, string $curlErr): array {
        $n = count(self::columns($service));
        $fail = function (string $msg) use ($n): array {
            $c = array_fill(0, $n, '');
            $c[$n - 1] = $msg;
            return array_map('p360_cell', $c);
        };
        if ($code < 200 || $code >= 300 || $curlErr !== '') {
            return $fail(self::error_text($code, $j, $curlErr));
        }
        if ($service === 'email') {
            $typo = (string)($j['typoSuggestion'] ?? '');
            if (strcasecmp($typo, (string)($j['emailAddress'] ?? '')) === 0) { $typo = ''; }
            return array_map('p360_cell', [
                $j['isSyntaxValid'] ?? '', $j['isMailboxDeliverable'] ?? '', $j['isCatchAll'] ?? '', $j['isDisposable'] ?? '',
                $j['isRoleBased'] ?? '', $j['riskLevel'] ?? '', $j['checkResult'] ?? '', $typo, '',
            ]);
        }
        if ($service === 'hlr') {
            $r = $j['results'][0] ?? null;
            if (!is_array($r)) { return $fail('Unexpected response'); }
            $status = is_array($r['status'] ?? null) ? ($r['status']['name'] ?? '') : ($r['status'] ?? '');
            $net = function ($v) { return is_array($v) ? ($v['networkName'] ?? '') : ($v ?? ''); };
            return array_map('p360_cell', [
                $req['phone'], $status, $r['live'] ?? '', $net($r['currentNetwork'] ?? ($r['portedNetwork'] ?? '')), $net($r['originalNetwork'] ?? ''), '',
            ]);
        }
        return array_map('p360_cell', [
            $j['prettierPhoneNumber'] ?? '', $j['onTps'] ?? '', $j['tpsRegisteredDate'] ?? '', $j['onCtps'] ?? '', $j['ctpsRegisteredDate'] ?? '', '',
        ]);
    }
}
