<?php
date_default_timezone_set('Europe/Vienna');

function tg_send_message(string $botToken, string $chatId, string $text): array {
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $resp = curl_exec($ch);

    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'cURL: ' . $err];
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        return ['ok' => false, 'error' => 'Telegram error', 'http' => $code, 'raw' => $resp];
    }

    $messageId = $data['result']['message_id'] ?? null;
    return ['ok' => true, 'message_id' => $messageId, 'result' => $data['result']];
}
