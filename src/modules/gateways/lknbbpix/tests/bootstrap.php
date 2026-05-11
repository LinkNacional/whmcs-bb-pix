<?php

require_once __DIR__ . '/../vendor/autoload.php';

$GLOBALS['lknbbpix_gateway_variables'] = [
    'enable_logs' => false,
    'fine_days' => '1',
    'pix_expiration' => '1',
    'receiver_pix_key' => 'teste@pix.com',
    'convenio' => '34627',
    'cnpj_recebedor' => '28552001000168',
    'pix_descrip' => '',
    'recurrence_object_name' => 'Fatura WHMCS',
    'enable_pix_automatic' => 'on',
];

$GLOBALS['lknbbpix_test_invoices'] = [];

if (!function_exists('enum_exists')) {
    function enum_exists(string $enum, bool $autoload = true): bool
    {
        return false;
    }
}

if (!function_exists('getGatewayVariables')) {
    function getGatewayVariables(string $gateway): array
    {
        return $GLOBALS['lknbbpix_gateway_variables'];
    }
}

if (!function_exists('logTransaction')) {
    function logTransaction(string $gateway, string $data, string $result): void
    {
    }
}

if (!function_exists('localAPI')) {
    function localAPI(string $command, array $postData = []): array
    {
        if ($command === 'GetInvoice') {
            $invoiceId = (int) ($postData['invoiceid'] ?? 0);

            if ($invoiceId > 0 && isset($GLOBALS['lknbbpix_test_invoices'][$invoiceId])) {
                return $GLOBALS['lknbbpix_test_invoices'][$invoiceId];
            }

            return [
                'balance' => '100.00',
                'duedate' => '2026-05-20',
                'total' => '100.00',
                'notes' => ''
            ];
        }

        if ($command === 'UpdateInvoice') {
            return ['result' => 'success'];
        }

        return ['result' => 'success'];
    }
}