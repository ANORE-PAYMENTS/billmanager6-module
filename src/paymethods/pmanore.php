#!/usr/bin/php
<?php

declare(strict_types=1);

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__DIR__) . '/include/php');
define('__MODULE__', 'pmanore');

require_once 'anore_billmanager.php';
require_once 'bill_util.php';

$options = getopt('', ['command:', 'payment:']);

try {
    $command = (string) ($options['command'] ?? '');
    Debug('command ' . $command);

    if ($command === 'config') {
        $config = simplexml_load_string($default_xml_string);
        $features = $config->addChild('feature');
        $features->addChild('redirect', 'on');
        $features->addChild('notneedprofile', 'on');
        $features->addChild('pmvalidate', 'on');
        $params = $config->addChild('param');
        $params->addChild('payment_script', '/mancgi/anorepayment.php');
        echo $config->asXML();
        exit;
    }

    if ($command === 'pmvalidate') {
        $form = simplexml_load_string((string) file_get_contents('php://stdin'));
        if (!$form instanceof SimpleXMLElement) {
            throw new ISPErrorException('value');
        }
        $apiKey = trim((string) $form->api_key);
        $shopId = trim((string) $form->shop_id);
        $webhookSecret = trim((string) $form->webhook_secret);

        try {
            $form->api_url = anoreBmApiUrl((string) $form->api_url);
        } catch (Throwable $error) {
            throw new ISPErrorException('value', 'api_url', (string) $form->api_url);
        }
        if ($apiKey === '') {
            throw new ISPErrorException('value', 'api_key', '');
        }
        if ($shopId !== '' && (!ctype_digit($shopId) || (int) $shopId <= 0)) {
            throw new ISPErrorException('value', 'shop_id', $shopId);
        }
        if ($webhookSecret === '') {
            throw new ISPErrorException('value', 'webhook_secret', '');
        }
        try {
            $form->methods = implode(',', anoreBmMethods((string) $form->methods));
        } catch (Throwable $error) {
            throw new ISPErrorException('value', 'methods', (string) $form->methods);
        }

        echo $form->asXML();
        exit;
    }

    throw new ISPErrorException('unknown command');
} catch (Throwable $error) {
    if ($error instanceof ISPErrorException) {
        echo $error;
    } else {
        Debug('Anore module command failed: ' . $error->getMessage());
        echo new ISPErrorException('value');
    }
}
