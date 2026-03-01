<?php
date_default_timezone_set('Europe/Vienna');

function json_store_read(string $path, $default) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) return $default;
    return $data;
}

function json_store_write_atomic(string $path, $data): bool {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function json_store_with_lock(string $jsonPath, callable $mutator, int $retries = 12, int $sleepMs = 60): array {
    // Lock file per json.
    $lockPath = $jsonPath . '.lock';
    $dir = dirname($jsonPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $fp = fopen($lockPath, 'c+');
    if (!$fp) return ['ok' => false, 'error' => 'Cannot open lock'];

    $attempt = 0;
    while ($attempt <= $retries) {
        $attempt++;

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            try {
                $current = json_store_read($jsonPath, null);
                $result = $mutator($current);
                if (!is_array($result) || !array_key_exists('data', $result)) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return ['ok' => false, 'error' => 'Invalid mutator result'];
                }
                $ok = json_store_write_atomic($jsonPath, $result['data']);
                flock($fp, LOCK_UN);
                fclose($fp);
                if (!$ok) return ['ok' => false, 'error' => 'Write failed'];
                return ['ok' => true, 'result' => $result];
            } catch (Throwable $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['ok' => false, 'error' => 'Exception: ' . $e->getMessage()];
            }
        }

        usleep($sleepMs * 1000);
    }

    fclose($fp);
    return ['ok' => false, 'error' => 'Lock timeout'];
}
