<?php

declare(strict_types=1);

$default_xml_string = '<doc/>';

class ISPErrorException extends RuntimeException
{
    public function __construct(string $message, string $field = '', string $value = '')
    {
        parent::__construct($message . ($field !== '' ? ': ' . $field . '=' . $value : ''));
    }
}

function fixtureRead(): array
{
    return json_decode((string) file_get_contents((string) getenv('BILLMANAGER_FIXTURE_STATE')), true, 512, JSON_THROW_ON_ERROR);
}

function fixtureWrite(array $state): void
{
    file_put_contents((string) getenv('BILLMANAGER_FIXTURE_STATE'), json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
}

function fixtureXml(SimpleXMLElement $parent, array $values): void
{
    foreach ($values as $name => $value) {
        $element = $parent->addChild((string) $name);
        if (is_array($value)) {
            fixtureXml($element, $value);
        } else {
            $element[0] = (string) $value;
        }
    }
}

function LocalQuery(string $command, array $params = [], $auth = false): SimpleXMLElement
{
    $state = fixtureRead();
    $state['calls'][] = ['command' => $command, 'params' => $params, 'auth' => $auth];
    fixtureWrite($state);
    if ((string) $auth !== '' && $auth !== 'fixture-auth') {
        return new SimpleXMLElement('<doc><error type="auth"><msg>Invalid session</msg></error></doc>');
    }
    $failure = $state['failures'][$command] ?? '';
    if ($failure === 'throw') {
        throw new RuntimeException('Fixture BILLmanager query failed');
    }
    if ($failure === 'xml') {
        return new SimpleXMLElement('<doc><error type="fixture"><msg>Fixture BILLmanager query failed</msg></error></doc>');
    }
    if ($command === 'payment') {
        if ($auth !== 'fixture-auth') {
            return new SimpleXMLElement('<doc><error type="auth"><msg>Session is required</msg></error></doc>');
        }
        $doc = new SimpleXMLElement('<doc/>');
        $requestedId = (int) ($params['id'] ?? 0);
        $accessibleIds = $state['accessibleIds'] ?? [(int) $state['payment']['id']];
        if (in_array($requestedId, $accessibleIds, true)) {
            $doc->addChild('elem')->addChild('id', (string) $requestedId);
        }
        return $doc;
    }
    if ($command === 'payment.info') {
        if ((string) $auth !== '') {
            return new SimpleXMLElement('<doc><error type="access"><msg>Privileged payment information requires administrator access</msg></error></doc>');
        }
        if (!empty($state['mappingOnInfo'])) {
            $mappingPath = dirname(__DIR__, 2) . '/var/anore-payments/42.json';
            $mapping = json_decode((string) file_get_contents($mappingPath), true, 512, JSON_THROW_ON_ERROR);
            $mapping = array_replace($mapping, $state['mappingOnInfo']);
            if (isset($mapping['attempts'])) {
                foreach ($mapping['attempts'] as &$attempt) {
                    $attempt = array_replace($attempt, $state['mappingOnInfo']);
                }
                unset($attempt);
            }
            file_put_contents($mappingPath, json_encode($mapping, JSON_THROW_ON_ERROR), LOCK_EX);
            unset($state['mappingOnInfo']);
            fixtureWrite($state);
        }
        $doc = new SimpleXMLElement('<doc/>');
        fixtureXml($doc->addChild('payment'), $state['payment']);
        return $doc;
    }
    if ($command === 'payment.setpaid') {
        if ((string) ($state['payment']['status'] ?? '') !== '4') {
            $state['credits'][] = ['elid' => (int) ($params['elid'] ?? 0)];
        }
        $state['payment']['status'] = '4';
        $state['payment']['paid'] = 'on';
        $state['payment']['externalid'] = (string) ($params['externalid'] ?? '');
    } elseif ($command === 'payment.setnopay') {
        $state['payment']['status'] = '1';
        $state['payment']['paid'] = 'off';
    } elseif ($command === 'payment.setinpay') {
        if ((string) $state['payment']['status'] !== '4') {
            $state['payment']['status'] = '8';
        }
    } else {
        throw new RuntimeException('Unexpected fixture query: ' . $command);
    }
    if (!empty($state['failStateWriteAfterCredit']) && $command === 'payment.setpaid') {
        $directory = dirname(__DIR__, 2) . '/var/anore-payments';
        $paymentId = (int) ($params['elid'] ?? 0);
        $target = $directory . '/' . $paymentId . '.json';
        if (PHP_OS_FAMILY === 'Windows') {
            chmod($target, 0444);
        } else {
            chmod($directory, 0550);
        }
    }
    fixtureWrite($state);
    return new SimpleXMLElement('<doc/>');
}

function CgiInput(): array
{
    parse_str((string) getenv('QUERY_STRING'), $input);
    return $input + $_GET + $_POST;
}

function Debug(string $message): void
{
    $state = fixtureRead();
    if (!empty($state['logFailure'])) {
        throw new RuntimeException('Fixture logging failed');
    }
    $state['logs'][] = $message;
    fixtureWrite($state);
}
