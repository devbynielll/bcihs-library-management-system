<?php
// This account reader never calls the notification engine or the SMS send endpoint.
function semaphoreCreditsKey(): string
{
    $key = getenv('SEMAPHORE_API_KEY') ?: ($_SERVER['SEMAPHORE_API_KEY'] ?? '');
    if (is_string($key) && trim($key) !== '') return trim($key);
    $path = function_exists('hostingPrivatePath')
        ? hostingPrivatePath('semaphore-api-key.php') : __DIR__ . '/semaphore-api-key.php';
    if (is_link($path) || !is_file($path) || !is_readable($path)) return '';
    ob_start();
    try { $key = require $path; }
    catch (Throwable $exception) { $key = ''; }
    finally { ob_end_clean(); }
    return is_string($key) ? trim($key) : '';
}

function semaphoreCreditsTransport(string $key): array
{
    $retry = 60;
    $curl = curl_init('https://api.semaphore.co/api/v4/account?' . http_build_query(['apikey' => $key]));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retry): int {
            if (preg_match('/^Retry-After:\s*(\d+)\s*$/i', trim($line), $match)) {
                $retry = max(60, min(86400, (int)$match[1]));
            }
            return strlen($line);
        }]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return ['http' => $status, 'body' => is_string($body) ? $body : '', 'retry' => $retry];
}

function semaphoreCreditsRead(string $key, string $directory, callable $transport, ?int $now = null): array
{
    $now ??= time();
    $empty = ['configured' => $key !== '', 'balance' => null, 'updated_at' => null,
        'next_check_at' => null, 'message' => 'Semaphore key is not configured for balance checks.'];
    if ($key === '') return $empty;
    $empty['message'] = 'Credit balance is unavailable. No SMS was sent.';
    if (is_link($directory)) return $empty;
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) return $empty;
    if ((fileperms($directory) & 0077) !== 0) return $empty;
    $path = $directory . '/semaphore-credits-cache.json';
    if (is_link($path)) return $empty;
    $file = @fopen($path, 'x+');
    if ($file) @chmod($path, 0600);
    else $file = @fopen($path, 'r+');
    if (!$file) return $empty;
    try {
        if ((fileperms($path) & 0077) !== 0 || !flock($file, LOCK_EX)) return $empty;
        $raw = stream_get_contents($file);
        $cache = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($cache)) return $empty;
        $fingerprint = hash('sha256', $key);
        $sameKey = ($cache['key_id'] ?? '') === $fingerprint;
        $result = $empty;
        if ($sameKey) {
            $result['balance'] = $cache['balance'] ?? null;
            $result['updated_at'] = $cache['updated_at'] ?? null;
            $result['message'] = $cache['message'] ?? $empty['message'];
        }
        $result['next_check_at'] = $cache['next_check_at'] ?? null;
        if (($cache['next_check_at'] ?? 0) > $now) return $result;
        $save = static function (array $value) use ($file): void {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $json) !== strlen($json) || !fflush($file)) {
                throw new RuntimeException('Cache unavailable');
            }
            if (function_exists('fsync') && !fsync($file)) throw new RuntimeException('Cache unavailable');
        };
        // Persist the cooldown before HTTP, including when a process stops mid-request.
        $result['next_check_at'] = $now + 60;
        $result['message'] = 'Balance refresh did not complete. Please wait before retrying.';
        $save($result + ['key_id' => $fingerprint]);
        try {
            $response = $transport($key);
            $result['next_check_at'] = $now + max(60, min(86400, (int)($response['retry'] ?? 60)));
            $data = json_decode($response['body'] ?? '', true);
            $balance = is_array($data) ? ($data['credit_balance'] ?? null) : null;
            if (($response['http'] ?? 0) === 200 && (is_int($balance) || is_float($balance) || is_string($balance))
                && preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', (string)$balance)) {
                $result['balance'] = (string)$balance;
                $result['updated_at'] = $now;
                $result['message'] = 'Balance loaded from Semaphore. No SMS was sent.';
            } else {
                $result['message'] = ($response['http'] ?? 0) === 429
                    ? 'Semaphore rate limit reached. Please wait before refreshing.'
                    : 'Could not refresh Semaphore credits. Any previous balance may be outdated.';
            }
        } catch (Throwable $exception) {
            $result['message'] = 'Could not refresh Semaphore credits. Any previous balance may be outdated.';
        }
        $save($result + ['key_id' => $fingerprint]);
        return $result;
    } catch (Throwable $exception) { return $empty; }
    finally { flock($file, LOCK_UN); fclose($file); }
}
