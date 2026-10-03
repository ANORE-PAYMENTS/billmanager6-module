#!/usr/bin/php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run recovery as root.\n");
    exit(1);
}
$helper = dirname(__DIR__) . '/include/php/anore_billmanager.php';
if (!is_file($helper)) {
    $helper = dirname(__DIR__) . '/src/include/php/anore_billmanager.php';
}
require_once $helper;

try {
    $options = getopt('', ['payment:', 'confirm-not-created']);
    if (!array_key_exists('confirm-not-created', $options)) {
        throw new RuntimeException('First verify in Anore that the uncertain request created no invoice. Use --payment=N --confirm-not-created.');
    }
    $paymentId = anoreBmPaymentId($options['payment'] ?? null);
    anoreBmWithLock($paymentId, static function () use ($paymentId): void {
        $state = anoreBmLoadState($paymentId);
        $last = $state === null ? -1 : count($state['attempts']) - 1;
        if ($last < 0 || $state['attempts'][$last]['state'] !== 'initializing'
            || isset($state['attempts'][$last]['anorePaymentId'])) {
            throw new RuntimeException('Only an uncertain request without an Anore UUID can be reset.');
        }
        $backup = anoreBmStatePath($paymentId) . '.recovery-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        anoreBmAtomicJson($backup, $state);
        array_pop($state['attempts']);
        anoreBmStoreState($paymentId, $state);
    });
    echo "Uncertain initialization reset; previous invoice history preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
