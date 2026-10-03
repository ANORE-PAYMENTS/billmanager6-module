<?php

declare(strict_types=1);

define('ANORE_BM_STATE_DIR', dirname(__DIR__, 2) . '/var/anore-payments');

class AnoreBmRequestException extends RuntimeException
{
    public $httpStatus;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->httpStatus = $status;
    }
}

function anoreBmLog(string $message): void
{
    try {
        if (function_exists('Debug')) {
            Debug($message);
        }
    } catch (Throwable $ignored) {
    }
}

function anoreBmQuery(string $function, array $params, ?string $auth = null): SimpleXMLElement
{
    global $log_file;
    $previousLog = $log_file ?? null;
    $quietLog = fopen('php://temp', 'w+');
    $log_file = $quietLog;
    try {
        $result = $auth === null ? LocalQuery($function, $params) : LocalQuery($function, $params, $auth);
    } finally {
        $log_file = $previousLog;
        if (is_resource($quietLog)) {
            fclose($quietLog);
        }
    }
    if (!$result instanceof SimpleXMLElement || $result->xpath('//error')) {
        throw new AnoreBmRequestException($auth === null ? 503 : 403, 'BILLmanager rejected ' . $function);
    }
    return $result;
}

function anoreBmPayment(SimpleXMLElement $info): SimpleXMLElement
{
    if (!isset($info->payment[0])) {
        throw new AnoreBmRequestException(503, 'BILLmanager payment was not found');
    }
    return $info->payment[0];
}

function anoreBmAuthorizePayment(int $paymentId, string $auth): void
{
    $list = anoreBmQuery('payment', ['filter' => 'on', 'id' => $paymentId], $auth);
    foreach ($list->elem as $item) {
        if (trim((string) $item->id) === (string) $paymentId) {
            return;
        }
    }
    throw new AnoreBmRequestException(403, 'BILLmanager payment is not accessible to this session');
}

function anoreBmPaymethod(SimpleXMLElement $payment): SimpleXMLElement
{
    foreach ($payment->paymethod as $candidate) {
        if (isset($candidate->api_key) || isset($candidate->webhook_secret)) {
            $module = trim((string) $candidate->module);
            if ($module !== '' && $module !== 'pmanore.php' && $module !== 'anore.php') {
                throw new AnoreBmRequestException(422, 'BILLmanager payment uses another payment method');
            }
            return $candidate;
        }
    }
    throw new AnoreBmRequestException(503, 'Anore payment method settings were not found');
}

function anoreBmCurrency(SimpleXMLElement $payment): string
{
    foreach ($payment->currency as $currency) {
        $iso = strtoupper(trim((string) $currency->iso));
        if ($iso !== '') {
            return $iso;
        }
    }
    throw new AnoreBmRequestException(503, 'BILLmanager payment currency was not found');
}

function anoreBmAmount($value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new AnoreBmRequestException(422, 'Invalid payment amount');
    }
    $text = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $text)) {
        throw new AnoreBmRequestException(422, 'Invalid payment amount');
    }
    $parts = explode('.', $text);
    $amount = $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    if ($amount === '0.00') {
        throw new AnoreBmRequestException(422, 'Payment amount must be positive');
    }
    return $amount;
}

function anoreBmString(array $input, string $key): string
{
    if (!isset($input[$key])) {
        return '';
    }
    if (!is_string($input[$key])) {
        throw new AnoreBmRequestException(400, 'Invalid ' . $key);
    }
    return trim($input[$key]);
}

function anoreBmPaymentId($value): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new AnoreBmRequestException(400, 'Invalid BILLmanager payment ID');
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new AnoreBmRequestException(400, 'Invalid BILLmanager payment ID');
    }
    return (int) $id;
}

function anoreBmHttpsUrl(string $url): string
{
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
        || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
        throw new AnoreBmRequestException(422, 'A valid HTTPS URL is required');
    }
    return $url;
}

function anoreBmApiUrl(string $apiUrl): string
{
    $url = rtrim(trim($apiUrl), '/');
    if ($url === '') {
        $url = 'https://api.anore.cc';
    }
    $url = preg_replace('#/api/v1$#i', '', $url);
    anoreBmHttpsUrl($url);
    $parts = parse_url($url);
    if (isset($parts['query']) || isset($parts['fragment'])) {
        throw new AnoreBmRequestException(422, 'API URL must not contain a query or fragment');
    }
    return $url;
}

function anoreBmMethods(string $csv): array
{
    $methods = [];
    foreach (explode(',', strtolower($csv)) as $item) {
        $method = trim($item);
        if ($method === '') {
            continue;
        }
        $method = ['dvnet' => 'crypto', 'xrocket' => 'crypto-old'][$method] ?? $method;
        if (!in_array($method, ['sbp', 'card', 'yoomoney', 'crypto', 'crypto-old'], true)) {
            throw new AnoreBmRequestException(422, 'Unknown Anore payment method');
        }
        if (!in_array($method, $methods, true)) {
            $methods[] = $method;
        }
    }
    return $methods;
}

function anoreBmManagerUrl(string $base, string $function, int $paymentId): string
{
    anoreBmHttpsUrl($base);
    if (parse_url($base, PHP_URL_FRAGMENT) !== null) {
        throw new AnoreBmRequestException(422, 'BILLmanager URL must not contain a fragment');
    }
    return $base . (strpos($base, '?') !== false ? '&' : '?') . http_build_query([
        'func' => $function, 'elid' => $paymentId, 'module' => 'pmanore.php',
    ]);
}

function anoreBmCallbackUrl(string $base, int $paymentId): string
{
    anoreBmHttpsUrl($base);
    $parts = parse_url($base);
    return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . '/mancgi/anoreresult.php?payment=' . $paymentId;
}

function anoreBmOrderId(int $paymentId, string $managerUrl): string
{
    return 'billmanager6:' . substr(hash('sha256', strtolower(trim($managerUrl))), 0, 12) . ':' . $paymentId;
}

function anoreBmPaymentIdFromOrder(string $orderId): int
{
    if (!preg_match('/^billmanager6:[a-f0-9]{12}:([1-9][0-9]*)$/D', $orderId, $matches)) {
        throw new AnoreBmRequestException(400, 'Invalid BILLmanager order ID');
    }
    return anoreBmPaymentId($matches[1]);
}

function anoreBmUuid(string $id): string
{
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
        throw new AnoreBmRequestException(422, 'Invalid Anore payment ID');
    }
    return $id;
}

function anoreBmStatePath(int $paymentId): string
{
    return ANORE_BM_STATE_DIR . '/' . anoreBmPaymentId($paymentId) . '.json';
}

function anoreBmEnsureStateDir(): void
{
    if (!is_dir(ANORE_BM_STATE_DIR) && !@mkdir(ANORE_BM_STATE_DIR, 0750, true) && !is_dir(ANORE_BM_STATE_DIR)) {
        throw new RuntimeException('Cannot create Anore state directory');
    }
}

function anoreBmReadJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read Anore payment mapping');
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Anore payment mapping is damaged');
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Anore payment mapping is damaged');
    }
    return $decoded;
}

function anoreBmLoadState(int $paymentId): ?array
{
    $path = anoreBmStatePath($paymentId);
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    $state = anoreBmReadJson($path);
    if (!isset($state['attempts'])) {
        $state['legacy'] = true;
        $state = ['version' => 2, 'state' => $state['state'] ?? '', 'attempts' => [$state]];
    }
    if (($state['version'] ?? null) !== 2 || !is_array($state['attempts'])
        || ($state['attempts'] !== [] && array_keys($state['attempts']) !== range(0, count($state['attempts']) - 1))) {
        throw new RuntimeException('Unsupported Anore payment mapping');
    }
    foreach ($state['attempts'] as $attempt) {
        if (!is_array($attempt) || !in_array($attempt['state'] ?? '', ['initializing', 'ready', 'paid', 'expired'], true)
            || !isset($attempt['orderId'], $attempt['amount'], $attempt['currency'])
            || !is_string($attempt['orderId']) || !is_string($attempt['amount']) || !is_string($attempt['currency'])) {
            throw new RuntimeException('Anore payment mapping is damaged');
        }
        try {
            if (anoreBmPaymentIdFromOrder($attempt['orderId']) !== $paymentId
                || anoreBmAmount($attempt['amount']) !== $attempt['amount']
                || !in_array($attempt['currency'], ['RUB', 'USD'], true)) {
                throw new RuntimeException('Invalid saved invoice identity');
            }
            if ($attempt['state'] !== 'initializing') {
                anoreBmUuid(anoreBmString($attempt, 'anorePaymentId'));
                anoreBmHttpsUrl(anoreBmString($attempt, 'paymentUrl'));
                if (!is_string($attempt['expiresAt'] ?? null) || strtotime($attempt['expiresAt']) === false) {
                    throw new RuntimeException('Invalid saved invoice expiry');
                }
            }
        } catch (Throwable $error) {
            throw new RuntimeException('Anore payment mapping is damaged');
        }
    }
    return $state;
}

function anoreBmAtomicJson(string $path, array $value): void
{
    anoreBmEnsureStateDir();
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    try {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@chmod($temporary, 0600)
            || !@rename($temporary, $path)) {
            throw new RuntimeException('Cannot save Anore payment mapping');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function anoreBmStoreState(int $paymentId, array $state): void
{
    $state['version'] = 2;
    $last = count($state['attempts']) - 1;
    $state['state'] = $last >= 0 ? $state['attempts'][$last]['state'] : 'empty';
    anoreBmAtomicJson(anoreBmStatePath($paymentId), $state);
}

function anoreBmIndex(int $paymentId, string $uuid): void
{
    $path = ANORE_BM_STATE_DIR . '/' . anoreBmUuid($uuid) . '.invoice';
    if (file_exists($path) && anoreBmPaymentId(anoreBmReadJson($path)['paymentId'] ?? null) !== $paymentId) {
        throw new RuntimeException('Anore payment ID is already mapped to another invoice');
    }
    anoreBmAtomicJson($path, ['paymentId' => $paymentId]);
}

function anoreBmPaymentIdFromUuid(string $uuid): int
{
    $path = ANORE_BM_STATE_DIR . '/' . anoreBmUuid($uuid) . '.invoice';
    if (!file_exists($path)) {
        throw new AnoreBmRequestException(422, 'Anore payment mapping was not found');
    }
    return anoreBmPaymentId(anoreBmReadJson($path)['paymentId'] ?? null);
}

function anoreBmWithLock(int $paymentId, callable $callback)
{
    anoreBmEnsureStateDir();
    $lock = @fopen(ANORE_BM_STATE_DIR . '/' . anoreBmPaymentId($paymentId) . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open Anore payment lock');
    }
    @chmod(ANORE_BM_STATE_DIR . '/' . $paymentId . '.lock', 0600);
    $deadline = microtime(true) + 2;
    try {
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                throw new AnoreBmRequestException(503, 'Anore payment is being processed; retry later');
            }
            usleep(50000);
        }
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function anoreBmApiRequest(string $apiUrl, string $apiKey, string $apiSecret, string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required');
    }
    $apiUrl = anoreBmApiUrl($apiUrl);
    if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey)) {
        throw new AnoreBmRequestException(422, 'Anore API key is invalid');
    }
    $raw = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiKey];
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
        CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $raw);
    }
    $body = curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === false || $errno !== 0) {
        throw new RuntimeException('Anore API transport failed (cURL ' . $errno . ')');
    }
    $json = json_decode((string) $body, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

function anoreBmRawBody(): string
{
    $raw = file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        throw new AnoreBmRequestException(400, 'Invalid request body');
    }
    return $raw;
}

function anoreBmCgiInput(): array
{
    if (PHP_SAPI !== 'cli') {
        $input = array_merge($_GET, $_POST);
    } else {
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
        $form = [];
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            parse_str(anoreBmRawBody(), $form);
        }
        $input = array_merge($query, $form);
    }
    if (!isset($input['auth']) || $input['auth'] === '') {
        $cookie = $_COOKIE['billmgrses5'] ?? '';
        if (!is_string($cookie)) {
            throw new AnoreBmRequestException(403, 'Invalid BILLmanager session');
        }
        if ($cookie === '') {
            foreach (explode(';', (string) ($_SERVER['HTTP_COOKIE'] ?? '')) as $item) {
                $parts = explode('=', trim($item), 2);
                if ($parts[0] === 'billmgrses5' && isset($parts[1])) {
                    $cookie = rawurldecode($parts[1]);
                    break;
                }
            }
        }
        if ($cookie !== '') {
            $input['auth'] = explode(':', $cookie, 2)[0];
        }
    }
    return $input;
}

function anoreBmSignatureHeader(): string
{
    return strtolower(trim((string) ($_SERVER['HTTP_ANORE_SIGNATURE'] ?? '')));
}

function anoreBmVerifySignature(string $raw, string $secret): void
{
    $signature = anoreBmSignatureHeader();
    if ($secret === '' || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        throw new AnoreBmRequestException(401, 'Invalid signature');
    }
}

function anoreBmHeaders(int $status, array $headers): void
{
    $reasons = [200 => 'OK', 302 => 'Found', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Entity', 502 => 'Bad Gateway', 503 => 'Service Unavailable'];
    $headers['Cache-Control'] = 'no-store';
    if (PHP_SAPI === 'cli') {
        echo 'Status: ' . $status . ' ' . ($reasons[$status] ?? 'Error') . "\r\n";
        foreach ($headers as $key => $value) {
            echo $key . ': ' . $value . "\r\n";
        }
        echo "\r\n";
    } else {
        http_response_code($status);
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }
    }
}

function anoreBmRespond(int $status, string $message): void
{
    $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
    if ($status === 503) {
        $headers['Retry-After'] = '30';
    }
    if ($status === 405) {
        $headers['Allow'] = 'POST';
    }
    anoreBmHeaders($status, $headers);
    echo $message;
    exit;
}

function anoreBmRedirect(string $url): void
{
    anoreBmHttpsUrl($url);
    anoreBmHeaders(302, ['Content-Type' => 'text/plain; charset=UTF-8', 'Location' => $url]);
    exit;
}
