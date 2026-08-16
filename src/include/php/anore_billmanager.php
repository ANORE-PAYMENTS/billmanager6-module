<?php

declare(strict_types=1);

const ANORE_BM_STATE_DIR = '/usr/local/mgr5/var/anore-payments';

function anoreBmPayment(SimpleXMLElement $info): SimpleXMLElement
{
    if (!isset($info->payment[0])) {
        throw new RuntimeException('BILLmanager payment was not found');
    }

    return $info->payment[0];
}

function anoreBmPaymethod(SimpleXMLElement $payment): SimpleXMLElement
{
    $fallback = null;
    foreach ($payment->paymethod as $candidate) {
        $fallback = $candidate;
        if (isset($candidate->api_key) || isset($candidate->webhook_secret)) {
            return $candidate;
        }
    }

    if ($fallback instanceof SimpleXMLElement) {
        return $fallback;
    }

    throw new RuntimeException('Anore payment method settings were not found');
}

function anoreBmCurrency(SimpleXMLElement $payment): string
{
    foreach ($payment->currency as $currency) {
        $iso = strtoupper(trim((string) $currency->iso));
        if ($iso !== '') {
            return $iso;
        }
    }

    throw new RuntimeException('BILLmanager payment currency was not found');
}

function anoreBmManagerUrl(string $base, string $function, int $paymentId): string
{
    $separator = strpos($base, '?') !== false ? '&' : '?';
    return $base . $separator . http_build_query([
        'func' => $function,
        'elid' => $paymentId,
        'module' => 'pmanore.php',
    ]);
}

function anoreBmOrderId(int $paymentId, string $managerUrl): string
{
    $installation = substr(hash('sha256', strtolower(trim($managerUrl))), 0, 12);
    return 'billmanager6:' . $installation . ':' . $paymentId;
}

function anoreBmPaymentIdFromOrder(string $orderId): int
{
    if (!preg_match('/^billmanager6:[a-f0-9]{12}:([1-9][0-9]*)$/', $orderId, $matches)) {
        throw new RuntimeException('Invalid BILLmanager order ID');
    }

    return (int) $matches[1];
}

function anoreBmStatePath(int $paymentId): string
{
    return ANORE_BM_STATE_DIR . '/' . $paymentId . '.json';
}

function anoreBmEnsureStateDir(): void
{
    if (!is_dir(ANORE_BM_STATE_DIR) && !mkdir(ANORE_BM_STATE_DIR, 0750, true) && !is_dir(ANORE_BM_STATE_DIR)) {
        throw new RuntimeException('Cannot create Anore state directory');
    }
}

function anoreBmLoadState(int $paymentId): ?array
{
    $path = anoreBmStatePath($paymentId);
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function anoreBmStoreState(int $paymentId, array $state): void
{
    anoreBmEnsureStateDir();
    $path = anoreBmStatePath($paymentId);
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Cannot save Anore payment mapping');
    }

    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot publish Anore payment mapping');
    }
}

function anoreBmDeleteState(int $paymentId): void
{
    $path = anoreBmStatePath($paymentId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function anoreBmWithLock(int $paymentId, callable $callback)
{
    anoreBmEnsureStateDir();
    $lockPath = ANORE_BM_STATE_DIR . '/' . $paymentId . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock Anore payment initialization');
    }

    try {
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function anoreBmApiRequest(
    string $apiUrl,
    string $apiKey,
    string $apiSecret,
    string $method,
    string $path,
    ?array $payload = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required');
    }

    $apiUrl = rtrim(trim($apiUrl), '/');
    if (!preg_match('#^https://#i', $apiUrl)) {
        throw new RuntimeException('Anore API URL must use HTTPS');
    }
    if ($apiKey === '') {
        throw new RuntimeException('Anore API key is empty');
    }

    $raw = $payload === null
        ? ''
        : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        if ($apiSecret !== '') {
            $headers[] = 'X-ZPay-Signature: ' . hash_hmac('sha256', $raw, $apiSecret);
        }
    }

    $handle = curl_init($apiUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Cannot initialize cURL');
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $raw);
    }

    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Anore API transport error: ' . $curlError);
    }

    $json = json_decode((string) $body, true);
    return [
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => (string) $body,
    ];
}

function anoreBmApiError(array $response): string
{
    $json = $response['json'] ?? null;
    if (is_array($json)) {
        $message = trim((string) ($json['message'] ?? $json['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }
        if (isset($json['errors']) && is_array($json['errors'])) {
            return implode('; ', array_map('strval', $json['errors']));
        }
    }

    return 'HTTP ' . (int) ($response['status'] ?? 0);
}

function anoreBmSignatureHeader(): string
{
    return strtolower(trim((string) ($_SERVER['HTTP_ANORE_SIGNATURE'] ?? '')));
}

function anoreBmRespond(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}
