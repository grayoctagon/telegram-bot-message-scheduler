<?php
date_default_timezone_set('Europe/Vienna');

/*
Very small SMTP sender (AUTH LOGIN, optional STARTTLS).
If smtp.mode is "mail", PHP mail() is used.
This is intentionally minimal for V1.
*/

function send_email(array $cfg, string $to, string $subject, string $body): array {
    $smtp = $cfg['smtp'] ?? [];
    $mode = $smtp['mode'] ?? 'mail';

    if ($mode === 'mail') {
        $from = $smtp['from'] ?? 'no-reply@example.com';
        $headers = "From: " . $from . "\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) return ['ok' => false, 'error' => 'mail() failed'];
        return ['ok' => true];
    }

    return smtp_send(
        $smtp['host'] ?? '',
        intval($smtp['port'] ?? 587),
        $smtp['user'] ?? '',
        $smtp['pass'] ?? '',
        $smtp['from'] ?? 'no-reply@example.com',
        $to,
        $subject,
        $body,
        !empty($smtp['starttls'])
    );
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
    if (!$fp) return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];

    stream_set_timeout($fp, 10);
    $greeting = smtp_read($fp);

    $resp = smtp_cmd($fp, "EHLO localhost");
    if ($starttls && strpos($resp, 'STARTTLS') !== false) {
        $resp2 = smtp_cmd($fp, "STARTTLS");
        if (substr($resp2, 0, 3) !== '220') {
            fclose($fp);
            return ['ok' => false, 'error' => 'STARTTLS rejected: ' . trim($resp2)];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'error' => 'STARTTLS crypto failed'];
        }
        $resp = smtp_cmd($fp, "EHLO localhost");
    }

    if ($user !== '' && $pass !== '') {
        $r = smtp_cmd($fp, "AUTH LOGIN");
        if (substr($r, 0, 3) !== '334') {
            fclose($fp);
            return ['ok' => false, 'error' => 'AUTH not accepted: ' . trim($r)];
        }
        $r = smtp_cmd($fp, base64_encode($user));
        $r = smtp_cmd($fp, base64_encode($pass));
        if (substr($r, 0, 3) !== '235') {
            fclose($fp);
            return ['ok' => false, 'error' => 'AUTH failed: ' . trim($r)];
        }
    }

    $r = smtp_cmd($fp, "MAIL FROM:<{$from}>");
    if (substr($r, 0, 3) !== '250') {
        fclose($fp);
        return ['ok' => false, 'error' => 'MAIL FROM failed: ' . trim($r)];
    }

    $r = smtp_cmd($fp, "RCPT TO:<{$to}>");
    if (substr($r, 0, 3) !== '250' && substr($r, 0, 3) !== '251') {
        fclose($fp);
        return ['ok' => false, 'error' => 'RCPT TO failed: ' . trim($r)];
    }

    $r = smtp_cmd($fp, "DATA");
    if (substr($r, 0, 3) !== '354') {
        fclose($fp);
        return ['ok' => false, 'error' => 'DATA failed: ' . trim($r)];
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
    smtp_cmd($fp, "QUIT");
    fclose($fp);

    if (substr($r, 0, 3) !== '250') {
        return ['ok' => false, 'error' => 'DATA end failed: ' . trim($r)];
    }
    return ['ok' => true];
}
