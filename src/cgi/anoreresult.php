#!/usr/bin/php
<?php

declare(strict_types=1);

define('__MODULE__', 'anoreresult');
require_once dirname(__DIR__) . '/include/php/anore_billmanager.php';

try {
    require_once dirname(__DIR__) . '/include/php/bill_util.php';
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        anoreBmRespond(405, 'Method Not Allowed');
    }
    $raw = anoreBmRawBody();
    if ($raw === '') {
        anoreBmRespond(400, 'Empty body');
    }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new AnoreBmRequestException(400, 'Invalid payload');
    }
    $event = anoreBmString($payload, 'event');
    $orderId = anoreBmString($payload, 'orderId');
    parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
    if ($orderId !== '') {
        $paymentId = anoreBmPaymentIdFromOrder($orderId);
        if (isset($query['payment']) && anoreBmPaymentId($query['payment']) !== $paymentId) {
            throw new AnoreBmRequestException(422, 'Callback payment ID does not match');
        }
    } elseif (isset($query['payment'])) {
        $paymentId = anoreBmPaymentId($query['payment']);
    } else {
        $paymentId = anoreBmPaymentIdFromUuid(anoreBmString($payload, 'id'));
    }

    anoreBmWithLock($paymentId, static function () use ($paymentId, $payload, $event, $orderId, $raw): void {
        $payment = anoreBmPayment(anoreBmQuery('payment.info', ['elid' => $paymentId]));
        $paymethod = anoreBmPaymethod($payment);
        anoreBmVerifySignature($raw, trim((string) $paymethod->webhook_secret));
        if (!in_array($event, ['payment.succeeded', 'payment.expired'], true)) {
            return;
        }
        $state = anoreBmLoadState($paymentId);
        if ($state === null) {
            throw new AnoreBmRequestException(422, 'Anore payment mapping was not found');
        }
        $uuid = anoreBmUuid(anoreBmString($payload, 'id'));
        $index = null;
        foreach ($state['attempts'] as $i => $attempt) {
            if (($attempt['anorePaymentId'] ?? '') === $uuid) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            foreach ($state['attempts'] as $attempt) {
                if ($attempt['state'] === 'initializing') {
                    throw new AnoreBmRequestException(503, 'Anore invoice creation is not reconciled');
                }
            }
            throw new AnoreBmRequestException(422, 'Anore payment ID does not match');
        }
        $attempt = $state['attempts'][$index];
        if ($orderId !== '' && !hash_equals((string) $attempt['orderId'], $orderId)) {
            throw new AnoreBmRequestException(422, 'Anore order ID does not match');
        }
        $amount = anoreBmAmount($payload['amount'] ?? null);
        $currency = strtoupper(anoreBmString($payload, 'currency'));
        if (!hash_equals((string) $attempt['amount'], $amount) || !hash_equals((string) $attempt['currency'], $currency)) {
            throw new AnoreBmRequestException(422, 'Anore amount or currency does not match');
        }
        $billingStatus = (int) $payment->status;
        $externalId = trim((string) $payment->externalid);
        if ($event === 'payment.expired' && $billingStatus === 4) {
            return;
        }
        if ($billingStatus === 4) {
            $legacyCredit = !empty($attempt['legacy']) && $attempt['state'] === 'paid' && $externalId === '';
            if ($externalId !== $uuid && !$legacyCredit) {
                throw new AnoreBmRequestException(422, 'BILLmanager payment was credited by another transaction');
            }
            $state['attempts'][$index]['state'] = 'paid';
            $state['attempts'][$index]['paidAt'] = $attempt['paidAt'] ?? gmdate(DATE_ATOM);
            $state['attempts'][$index]['creditConfirmed'] = true;
            anoreBmStoreState($paymentId, $state);
            return;
        }
        if ($attempt['state'] === 'paid'
            && ($event !== 'payment.succeeded' || empty($attempt['legacy']) || !empty($attempt['creditConfirmed']))) {
            throw new AnoreBmRequestException(422, 'BILLmanager credit changed; manual reconciliation is required');
        }
        if ($event === 'payment.succeeded') {
            if (!in_array($billingStatus, [1, 2, 8], true)) {
                throw new AnoreBmRequestException(422, 'BILLmanager payment is not eligible for credit');
            }
            if (anoreBmAmount((string) $payment->paymethodamount) !== $amount || anoreBmCurrency($payment) !== $currency
                || (isset($attempt['paymethodId']) && $attempt['paymethodId'] !== trim((string) $paymethod->id))) {
                throw new AnoreBmRequestException(422, 'BILLmanager invoice changed; manual reconciliation is required');
            }
            anoreBmQuery('payment.setpaid', ['elid' => $paymentId, 'sok' => 'ok', 'externalid' => $uuid,
                'info' => 'Anore payment ' . $uuid]);
            $confirmed = anoreBmPayment(anoreBmQuery('payment.info', ['elid' => $paymentId]));
            if ((int) $confirmed->status !== 4 || trim((string) $confirmed->externalid) !== $uuid) {
                throw new AnoreBmRequestException(503, 'BILLmanager credit could not be confirmed');
            }
            $state['attempts'][$index]['state'] = 'paid';
            $state['attempts'][$index]['paidAt'] = gmdate(DATE_ATOM);
            $state['attempts'][$index]['creditConfirmed'] = true;
            anoreBmStoreState($paymentId, $state);
            return;
        }
        if ($attempt['state'] !== 'expired') {
            if ($index === count($state['attempts']) - 1 && in_array($billingStatus, [1, 2, 8], true)) {
                anoreBmQuery('payment.setnopay', ['elid' => $paymentId, 'sok' => 'ok', 'externalid' => $uuid]);
            }
            $state['attempts'][$index]['state'] = 'expired';
            $state['attempts'][$index]['expiredAt'] = gmdate(DATE_ATOM);
            anoreBmStoreState($paymentId, $state);
        }
    });
    anoreBmRespond(200, in_array($event, ['payment.succeeded', 'payment.expired'], true) ? 'OK' : 'Ignored');
} catch (JsonException $error) {
    anoreBmRespond(400, 'Invalid JSON');
} catch (Throwable $error) {
    anoreBmLog('Anore webhook failed: ' . $error->getMessage());
    $status = $error instanceof AnoreBmRequestException ? $error->httpStatus : 503;
    anoreBmRespond($status, $status === 503 ? 'Temporary failure; retry later' : 'Invalid webhook');
}
