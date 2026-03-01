<?php
date_default_timezone_set('Europe/Vienna');

/*
Very small SMTP sender (AUTH LOGIN, optional STARTTLS).
If smtp.mode is "mail", PHP mail() is used.
This is intentionally minimal for V1.
*/

function send_email(array $cfg, string $to, string $subject, string $body, array $meta = []): array {
    $t0 = microtime(true);
    $smtp = $cfg['smtp'] ?? [];
    $mode = $smtp['mode'] ?? 'mail';

    $baseLog = [
        'id' => bin2hex(random_bytes(8)),
        'entrydate' => (new DateTimeImmutable('now'))->format(DateTime::RFC3339),
        'mode' => $mode,
        'to' => $to,
        'from' => $smtp['from'] ?? 'no-reply@example.com',
        'subject' => $subject,
        'body_len' => strlen($body),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];

    if (!empty($meta)) {
        // Caller-provided metadata for debugging (never include secrets).
        $baseLog['meta'] = $meta;
    }

    if ($mode === 'mail') {
        $from = $smtp['from'] ?? 'no-reply@example.com';
        $headers = "From: " . $from . "\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        $res = $ok ? ['ok' => true] : ['ok' => false, 'error' => 'mail() failed'];
        smtp_log_event(array_merge($baseLog, [
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'ok' => (bool)($res['ok'] ?? false),
            'error' => $res['error'] ?? null
        ]));
        return $res;
    }

    $host = $smtp['host'] ?? '';
    $port = intval($smtp['port'] ?? 587);
    $starttls = !empty($smtp['starttls']);
    $hasAuth = (($smtp['user'] ?? '') !== '' && ($smtp['pass'] ?? '') !== '');

    $res = smtp_send(
        $host,
        $port,
        $smtp['user'] ?? '',
        $smtp['pass'] ?? '',
        $smtp['from'] ?? 'no-reply@example.com',
        $to,
        $subject,
        $body,
        $starttls
    );

    smtp_log_event(array_merge($baseLog, [
        'host' => $host,
        'port' => $port,
        'starttls' => $starttls,
        'auth' => $hasAuth,
        'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
        'ok' => (bool)($res['ok'] ?? false),
        'error' => $res['error'] ?? null,
        'smtp_code' => $res['smtp_code'] ?? null,
        'smtp_reply' => $res['smtp_reply'] ?? null
    ]));

    return $res;
}

// Writes a beautified JSON log file with 30 days retention.
// Path: data/smtp_log.json (protected via data/.htaccess)
function smtp_log_event(array $entry): void {
    $baseDir = defined('DATA_DIR') ? DATA_DIR : (dirname(__DIR__) . '/data');
    $path = $baseDir . '/smtp_log.json';

    // Retention cutoff
    $cutoff = (new DateTimeImmutable('now'))->modify('-30 days');

    json_store_with_lock($path, function($current) use ($entry, $cutoff) {
        if (!is_array($current)) $current = ['entries' => []];
        if (!isset($current['entries']) || !is_array($current['entries'])) $current['entries'] = [];

        // Prune older than 30 days
        $kept = [];
        foreach ($current['entries'] as $e) {
            $ts = $e['entrydate'] ?? '';
            try {
                $dt = new DateTimeImmutable($ts);
                if ($dt >= $cutoff) $kept[] = $e;
            } catch (Throwable $ex) {
                // If we can't parse, keep it (don't risk deleting valid data).
                $kept[] = $e;
            }
        }

        $kept[] = $entry;
        $current['entries'] = $kept;
        return ['data' => $current];
    });
}

// Optional maintenance helper: enforce 30-day retention even if no new SMTP events happen.
function smtp_log_prune_if_needed(): void {
    $baseDir = defined('DATA_DIR') ? DATA_DIR : (dirname(__DIR__) . '/data');
    $path = $baseDir . '/smtp_log.json';
    if (!file_exists($path)) return;

    $cutoff = (new DateTimeImmutable('now'))->modify('-30 days');

    // Fast path: check without locking if there is anything to prune.
    $store = json_store_read($path, ['entries' => []]);
    $entries = $store['entries'] ?? [];
    $needsPrune = false;
    foreach ($entries as $e) {
        $ts = $e['entrydate'] ?? '';
        try {
            $dt = new DateTimeImmutable($ts);
            if ($dt < $cutoff) { $needsPrune = true; break; }
        } catch (Throwable $ex) {
            // ignore
        }
    }
    if (!$needsPrune) return;

    json_store_with_lock($path, function($current) use ($cutoff) {
        if (!is_array($current) || !isset($current['entries']) || !is_array($current['entries'])) {
            return ['data' => ['entries' => []]];
        }
        $kept = [];
        foreach ($current['entries'] as $e) {
            $ts = $e['entrydate'] ?? '';
            try {
                $dt = new DateTimeImmutable($ts);
                if ($dt >= $cutoff) $kept[] = $e;
            } catch (Throwable $ex) {
                $kept[] = $e;
            }
        }
        $current['entries'] = $kept;
        return ['data' => $current];
    });
}

function smtp_read($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        // Multi-line replies have '-' as the 4th char.
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $data;
}

function smtp_cmd($fp, string $cmd): string {
    fwrite($fp, $cmd . "\r\n");
    return smtp_read($fp);
}

function smtp_send(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $body, bool $starttls): array {
    if (!$host || $port <= 0) return ['ok' => false, 'error' => 'SMTP host/port missing'];

    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$fp) return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})", 'smtp_code' => null, 'smtp_reply' => null];

    stream_set_timeout($fp, 10);
    $greeting = smtp_read($fp);
    $lastReply = $greeting;

    $resp = smtp_cmd($fp, "EHLO localhost");
    $lastReply = $resp;
    if ($starttls && strpos($resp, 'STARTTLS') !== false) {
        $resp2 = smtp_cmd($fp, "STARTTLS");
        $lastReply = $resp2;
        if (substr($resp2, 0, 3) !== '220') {
            fclose($fp);
            return ['ok' => false, 'error' => 'STARTTLS rejected: ' . trim($resp2), 'smtp_code' => substr(trim($resp2), 0, 3), 'smtp_reply' => trim($resp2)];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'error' => 'STARTTLS crypto failed', 'smtp_code' => null, 'smtp_reply' => trim($lastReply)];
        }
        $resp = smtp_cmd($fp, "EHLO localhost");
        $lastReply = $resp;
    }

    if ($user !== '' && $pass !== '') {
        $r = smtp_cmd($fp, "AUTH LOGIN");
        $lastReply = $r;
        if (substr($r, 0, 3) !== '334') {
            fclose($fp);
            return ['ok' => false, 'error' => 'AUTH not accepted: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
        }
        $r = smtp_cmd($fp, base64_encode($user));
        $r = smtp_cmd($fp, base64_encode($pass));
        $lastReply = $r;
        if (substr($r, 0, 3) !== '235') {
            fclose($fp);
            return ['ok' => false, 'error' => 'AUTH failed: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
        }
    }

    $r = smtp_cmd($fp, "MAIL FROM:<{$from}>");
    $lastReply = $r;
    if (substr($r, 0, 3) !== '250') {
        fclose($fp);
        return ['ok' => false, 'error' => 'MAIL FROM failed: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
    }

    $r = smtp_cmd($fp, "RCPT TO:<{$to}>");
    $lastReply = $r;
    if (substr($r, 0, 3) !== '250' && substr($r, 0, 3) !== '251') {
        fclose($fp);
        return ['ok' => false, 'error' => 'RCPT TO failed: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
    }

    $r = smtp_cmd($fp, "DATA");
    $lastReply = $r;
    if (substr($r, 0, 3) !== '354') {
        fclose($fp);
        return ['ok' => false, 'error' => 'DATA failed: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
    }

    $headers = [];
    $headers[] = "From: {$from}";
    $headers[] = "To: {$to}";
    $headers[] = "Subject: {$subject}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";

    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    fwrite($fp, $msg . "\r\n");

    $r = smtp_read($fp);
    $lastReply = $r;
    smtp_cmd($fp, "QUIT");
    fclose($fp);

    if (substr($r, 0, 3) !== '250') {
        return ['ok' => false, 'error' => 'DATA end failed: ' . trim($r), 'smtp_code' => substr(trim($r), 0, 3), 'smtp_reply' => trim($r)];
    }
    return ['ok' => true, 'smtp_code' => substr(trim($lastReply), 0, 3), 'smtp_reply' => trim($lastReply)];
}
