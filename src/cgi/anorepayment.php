#!/usr/bin/php
<?php

declare(strict_types=1);

set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/local/mgr5/include/php');
define('__MODULE__', 'anorepayment');

require_once 'bill_util.php';
require_once 'anore_billmanager.php';

try {
    $input = CgiInput();
    if (trim((string) ($input['auth'] ?? '')) === '') {
        throw new RuntimeException('Authorization is required');
    }

    $paymentId = filter_var($input['elid'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($paymentId === false) {
        throw new RuntimeException('Invalid BILLmanager payment ID');
    }

    $info = LocalQuery('payment.info', ['elid' => $paymentId]);
    $payment = anoreBmPayment($info);
    $paymethod = anoreBmPaymethod($payment);
    $managerUrl = trim((string) $payment->manager_url);
    $amount = number_format((float) $payment->paymethodamount, 2, '.', '');
    $currency = anoreBmCurrency($payment);
    if (!in_array($currency, ['RUB', 'USD'], true)) {
        throw new RuntimeException('Anore supports RUB and USD invoices only');
    }

    $orderId = anoreBmOrderId((int) $paymentId, $managerUrl);
    $paymentUrl = anoreBmWithLock((int) $paymentId, function () use (
        $paymentId,
        $payment,
        $paymethod,
        $managerUrl,
        $amount,
        $currency,
        $orderId
    ): string {
        $state = anoreBmLoadState((int) $paymentId);
        if (is_array($state)) {
            if (($state['orderId'] ?? '') !== $orderId
                || ($state['amount'] ?? '') !== $amount
                || ($state['currency'] ?? '') !== $currency) {
                throw new RuntimeException('Saved Anore invoice does not match this BILLmanager payment');
            }
            if (($state['state'] ?? '') === 'ready'
                && filter_var($state['paymentUrl'] ?? '', FILTER_VALIDATE_URL)) {
                return (string) $state['paymentUrl'];
            }
            throw new RuntimeException('Previous Anore invoice request has an uncertain result; reset it manually before retrying');
        }

        anoreBmStoreState((int) $paymentId, [
            'state' => 'initializing',
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'startedAt' => gmdate(DATE_ATOM),
        ]);

        $number = trim((string) $payment->number);
        $description = $number !== '' ? 'BILLmanager invoice ' . $number : 'BILLmanager payment #' . $paymentId;
        $payload = [
            'amount' => (float) $amount,
            'currency' => strtolower($currency),
            'description' => $description,
            'orderId' => $orderId,
            'getbackurl' => anoreBmManagerUrl($managerUrl, 'payment.success', (int) $paymentId),
            'successurl' => anoreBmManagerUrl($managerUrl, 'payment.success', (int) $paymentId),
            'failurl' => anoreBmManagerUrl($managerUrl, 'payment.fail', (int) $paymentId),
        ];

        $shopId = trim((string) $paymethod->shop_id);
        if ($shopId !== '') {
            $payload['shopId'] = (int) $shopId;
        }
        $methods = array_values(array_filter(array_map('trim', explode(',', (string) $paymethod->methods))));
        if ($methods !== []) {
            $payload['methods'] = $methods;
        }

        try {
            $response = anoreBmApiRequest(
                (string) $paymethod->api_url,
                trim((string) $paymethod->api_key),
                trim((string) $paymethod->api_secret),
                'POST',
                '/api/v1/payments',
                $payload
            );
        } catch (Throwable $error) {
            Debug('Anore create request has uncertain result: ' . $error->getMessage());
            throw $error;
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            if ($response['status'] >= 400 && $response['status'] < 500) {
                anoreBmDeleteState((int) $paymentId);
            }
            throw new RuntimeException('Anore rejected invoice creation: ' . anoreBmApiError($response));
        }

        $anorePaymentId = trim((string) ($response['json']['id'] ?? ''));
        $url = trim((string) ($response['json']['paymentUrl'] ?? ''));
        if ($anorePaymentId === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Anore returned an incomplete invoice response');
        }

        $expiresIn = max(60, (int) ($response['json']['expiresIn'] ?? 14400));
        anoreBmStoreState((int) $paymentId, [
            'state' => 'ready',
            'billmanagerPaymentId' => (int) $paymentId,
            'anorePaymentId' => $anorePaymentId,
            'paymentUrl' => $url,
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'expiresAt' => gmdate(DATE_ATOM, time() + $expiresIn),
            'createdAt' => gmdate(DATE_ATOM),
        ]);

        LocalQuery('payment.setinpay', ['elid' => $paymentId]);
        return $url;
    });

    header('Cache-Control: no-store');
    header('Location: ' . $paymentUrl, true, 302);
    exit;
} catch (Throwable $error) {
    Debug('Anore payment initialization failed: ' . $error->getMessage());
    http_response_code(502);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка оплаты</title>';
    echo '<body><h1>Не удалось открыть оплату</h1><p>Повторите попытку позже или обратитесь в поддержку.</p></body></html>';
}

