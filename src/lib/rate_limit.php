<?php
date_default_timezone_set('Europe/Vienna');

function rate_limit_check_and_record(string $email, int $minSecondsBetween, int $maxPerDay): array {
    $path = DATA_DIR . '/ratelimit.json';
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');

    $res = json_store_with_lock($path, function($current) use ($email, $minSecondsBetween, $maxPerDay, $now, $today) {
        if (!is_array($current)) $current = ['by_email' => []];
        if (!isset($current['by_email']) || !is_array($current['by_email'])) $current['by_email'] = [];

        $rec = $current['by_email'][$email] ?? ['last_ts' => null, 'days' => []];

        $lastTs = $rec['last_ts'] ?? null;
        if ($lastTs) {
            $last = parse_rfc3339($lastTs);
            if ($last) {
                $diff = $now->getTimestamp() - $last->getTimestamp();
                if ($diff < $minSecondsBetween) {
                    return ['data' => $current, 'allowed' => false, 'reason' => 'too_soon', 'wait_seconds' => ($minSecondsBetween - $diff)];
                }
            }
        }

        $days = $rec['days'] ?? [];
        $countToday = intval($days[$today] ?? 0);
        if ($countToday >= $maxPerDay) {
            return ['data' => $current, 'allowed' => false, 'reason' => 'daily_limit'];
        }

        $countToday++;
        $days[$today] = $countToday;
        $rec['days'] = $days;
        $rec['last_ts'] = $now->format(DateTime::RFC3339);

        $current['by_email'][$email] = $rec;
        return ['data' => $current, 'allowed' => true, 'count_today' => $countToday];
    });

    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];
    $r = $res['result'];
    if (empty($r['allowed'])) return ['ok' => true, 'allowed' => false, 'reason' => $r['reason'] ?? 'unknown', 'wait_seconds' => $r['wait_seconds'] ?? null];
    return ['ok' => true, 'allowed' => true, 'count_today' => $r['count_today'] ?? null];
}
