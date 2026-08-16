#!/usr/bin/php
<?php

declare(strict_types=1);

set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/local/mgr5/include/php');
define('__MODULE__', 'anoreresult');

require_once 'bill_util.php';
require_once 'anore_billmanager.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    anoreBmRespond(405, 'Method Not Allowed');
}

$raw = (string) file_get_contents('php://input');
if ($raw === '') {
    anoreBmRespond(400, 'Empty body');
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload');
    }

    $orderId = trim((string) ($payload['orderId'] ?? ''));
    $paymentId = anoreBmPaymentIdFromOrder($orderId);
    $state = anoreBmLoadState($paymentId);
    $stateName = is_array($state) ? (string) ($state['state'] ?? '') : '';
    if (!in_array($stateName, ['ready', 'paid', 'expired'], true)) {
        throw new RuntimeException('Anore payment mapping was not found');
    }

    $info = LocalQuery('payment.info', ['elid' => $paymentId]);
    $payment = anoreBmPayment($info);
    $paymethod = anoreBmPaymethod($payment);
    $webhookSecret = trim((string) $paymethod->webhook_secret);
    $signature = anoreBmSignatureHeader();
    if ($webhookSecret === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        anoreBmRespond(401, 'Invalid signature');
    }
    $expected = hash_hmac('sha256', $raw, $webhookSecret);
    if (!hash_equals($expected, $signature)) {
        anoreBmRespond(401, 'Invalid signature');
    }

    $anorePaymentId = trim((string) ($payload['id'] ?? ''));
    if ($anorePaymentId === '' || !hash_equals((string) ($state['anorePaymentId'] ?? ''), $anorePaymentId)) {
        throw new RuntimeException('Anore payment ID does not match the saved mapping');
    }
    if (!hash_equals((string) ($state['orderId'] ?? ''), $orderId)) {
        throw new RuntimeException('Anore order ID does not match the saved mapping');
    }

    $amount = isset($payload['amount']) && is_numeric($payload['amount'])
        ? number_format((float) $payload['amount'], 2, '.', '')
        : '';
    $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
    if ($amount === '' || !hash_equals((string) ($state['amount'] ?? ''), $amount)) {
        throw new RuntimeException('Anore amount does not match the saved mapping');
    }
    if ($currency === '' || !hash_equals((string) ($state['currency'] ?? ''), $currency)) {
        throw new RuntimeException('Anore currency does not match the saved mapping');
    }

    $event = trim((string) ($payload['event'] ?? ''));
    if (in_array($event, ['payment.succeeded', 'payment.expired'], true)) {
        anoreBmWithLock($paymentId, static function () use ($event, $paymentId): void {
            $lockedState = anoreBmLoadState($paymentId);
            $lockedStateName = is_array($lockedState) ? (string) ($lockedState['state'] ?? '') : '';
            if (!is_array($lockedState) || !in_array($lockedStateName, ['ready', 'paid', 'expired'], true)) {
                throw new RuntimeException('Anore payment mapping was not found');
            }

            if ($event === 'payment.succeeded') {
                if ($lockedStateName !== 'paid') {
                    LocalQuery('payment.setpaid', ['elid' => $paymentId]);
                    $lockedState['state'] = 'paid';
                    $lockedState['paidAt'] = gmdate(DATE_ATOM);
                    anoreBmStoreState($paymentId, $lockedState);
                }
                return;
            }

            if ($lockedStateName === 'paid') {
                return;
            }
            if ($lockedStateName !== 'expired') {
                LocalQuery('payment.setnopay', ['elid' => $paymentId]);
                $lockedState['state'] = 'expired';
                $lockedState['expiredAt'] = gmdate(DATE_ATOM);
                anoreBmStoreState($paymentId, $lockedState);
            }
        });
        anoreBmRespond(200, 'OK');
    }

    anoreBmRespond(200, 'Ignored');
} catch (JsonException $error) {
    Debug('Anore webhook JSON error: ' . $error->getMessage());
    anoreBmRespond(400, 'Invalid JSON');
} catch (Throwable $error) {
    Debug('Anore webhook rejected: ' . $error->getMessage());
    anoreBmRespond(400, 'Invalid webhook');
}
