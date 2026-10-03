#!/usr/bin/php
<?php

declare(strict_types=1);

define('__MODULE__', 'anorepayment');
require_once dirname(__DIR__) . '/include/php/anore_billmanager.php';

try {
    require_once dirname(__DIR__) . '/include/php/bill_util.php';
    $input = anoreBmCgiInput();
    $auth = anoreBmString($input, 'auth');
    if ($auth === '') {
        throw new AnoreBmRequestException(403, 'Authorization is required');
    }
    $paymentId = anoreBmPaymentId($input['elid'] ?? null);
    anoreBmAuthorizePayment($paymentId, $auth);

    $url = anoreBmWithLock($paymentId, static function () use ($paymentId, $auth): string {
        anoreBmAuthorizePayment($paymentId, $auth);
        $payment = anoreBmPayment(anoreBmQuery('payment.info', ['elid' => $paymentId]));
        if (!in_array((int) $payment->status, [1, 2, 8], true)) {
            throw new AnoreBmRequestException(409, 'This BILLmanager payment cannot be paid again');
        }
        $paymethod = anoreBmPaymethod($payment);
        $amount = anoreBmAmount((string) $payment->paymethodamount);
        $currency = anoreBmCurrency($payment);
        if (!in_array($currency, ['RUB', 'USD'], true)) {
            throw new AnoreBmRequestException(422, 'Anore supports RUB and USD invoices only');
        }
        $managerUrl = trim((string) $payment->manager_url);
        $orderId = anoreBmOrderId($paymentId, $managerUrl);
        $payload = [
            'amount' => (float) $amount,
            'currency' => strtolower($currency),
            'description' => trim((string) $payment->number) !== ''
                ? 'BILLmanager invoice ' . trim((string) $payment->number) : 'BILLmanager payment #' . $paymentId,
            'orderId' => $orderId,
            'getbackurl' => anoreBmManagerUrl($managerUrl, 'payment.success', $paymentId),
            'successurl' => anoreBmManagerUrl($managerUrl, 'payment.success', $paymentId),
            'failurl' => anoreBmManagerUrl($managerUrl, 'payment.fail', $paymentId),
            'callbackUrl' => anoreBmCallbackUrl($managerUrl, $paymentId),
        ];
        $email = trim((string) $payment->useremail);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $payload['email'] = $email;
        }
        $shopId = trim((string) $paymethod->shop_id);
        if ($shopId !== '') {
            $payload['shopId'] = anoreBmPaymentId($shopId);
        }
        $methods = anoreBmMethods((string) $paymethod->methods);
        if ($methods !== []) {
            $payload['methods'] = $methods;
        }
        $apiUrl = anoreBmApiUrl((string) $paymethod->api_url);
        $apiKey = trim((string) $paymethod->api_key);
        $apiSecret = trim((string) $paymethod->api_secret);
        if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey) || trim((string) $paymethod->webhook_secret) === '') {
            throw new AnoreBmRequestException(422, 'Anore payment method is not configured');
        }
        $fingerprint = hash('sha256', json_encode([$apiUrl, $apiKey, $apiSecret,
            trim((string) $paymethod->id), $payload], JSON_THROW_ON_ERROR));
        $state = anoreBmLoadState($paymentId) ?? ['version' => 2, 'attempts' => []];
        foreach ($state['attempts'] as $attempt) {
            if ($attempt['state'] === 'paid') {
                throw new AnoreBmRequestException(409, 'This Anore invoice has already been paid');
            }
            if ($attempt['state'] === 'initializing') {
                throw new AnoreBmRequestException(409, 'Previous Anore request is uncertain; verify it before manual recovery');
            }
        }
        $last = count($state['attempts']) - 1;
        if ($last >= 0) {
            $attempt = $state['attempts'][$last];
            if ($attempt['state'] === 'ready' && ($attempt['orderId'] ?? '') === $orderId
                && ($attempt['amount'] ?? '') === $amount && ($attempt['currency'] ?? '') === $currency
                && (!isset($attempt['fingerprint']) || hash_equals($attempt['fingerprint'], $fingerprint))
                && strtotime($attempt['expiresAt'] ?? '') > time()) {
                $cachedUrl = anoreBmHttpsUrl((string) ($attempt['paymentUrl'] ?? ''));
                anoreBmIndex($paymentId, (string) $attempt['anorePaymentId']);
                anoreBmQuery('payment.setinpay', ['elid' => $paymentId, 'sok' => 'ok',
                    'externalid' => $attempt['anorePaymentId']]);
                return $cachedUrl;
            }
            if ($attempt['state'] === 'ready' && strtotime($attempt['expiresAt']) > time()) {
                throw new AnoreBmRequestException(409, 'Anore invoice settings changed; reconcile the active invoice before creating another');
            }
        }
        $state['attempts'][] = [
            'state' => 'initializing', 'billmanagerPaymentId' => $paymentId,
            'orderId' => $orderId, 'amount' => $amount, 'currency' => $currency,
            'paymethodId' => trim((string) $paymethod->id), 'fingerprint' => $fingerprint,
            'startedAt' => gmdate(DATE_ATOM),
        ];
        $index = count($state['attempts']) - 1;
        anoreBmStoreState($paymentId, $state);
        $response = anoreBmApiRequest($apiUrl, $apiKey, $apiSecret, 'POST', '/api/v1/payments', $payload);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            if (in_array($response['status'], [400, 401, 403, 404, 405, 422, 429], true)) {
                array_pop($state['attempts']);
                anoreBmStoreState($paymentId, $state);
            }
            throw new AnoreBmRequestException(502, 'Anore invoice creation failed (HTTP ' . $response['status'] . ')');
        }
        $id = anoreBmUuid(anoreBmString($response['json'], 'id'));
        $paymentUrl = anoreBmHttpsUrl(anoreBmString($response['json'], 'paymentUrl'));
        $expiresIn = filter_var($response['json']['expiresIn'] ?? 14400, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 604800]]);
        if ($expiresIn === false) {
            throw new AnoreBmRequestException(502, 'Anore returned an invalid invoice expiry');
        }
        $state['attempts'][$index] = array_merge($state['attempts'][$index], [
            'state' => 'ready', 'anorePaymentId' => $id, 'paymentUrl' => $paymentUrl,
            'expiresAt' => gmdate(DATE_ATOM, time() + $expiresIn), 'createdAt' => gmdate(DATE_ATOM),
        ]);
        anoreBmStoreState($paymentId, $state);
        anoreBmIndex($paymentId, $id);
        anoreBmQuery('payment.setinpay', ['elid' => $paymentId, 'sok' => 'ok', 'externalid' => $id]);
        return $paymentUrl;
    });
    anoreBmRedirect($url);
} catch (Throwable $error) {
    anoreBmLog('Anore payment initialization failed: ' . $error->getMessage());
    $status = $error instanceof AnoreBmRequestException ? $error->httpStatus : 503;
    anoreBmRespond($status, $status === 403 ? 'Access denied' :
        'Не удалось открыть оплату. Повторите попытку позже или обратитесь в поддержку.');
}
