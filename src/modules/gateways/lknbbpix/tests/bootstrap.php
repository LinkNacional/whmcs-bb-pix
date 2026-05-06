<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('enum_exists')) {
    function enum_exists(string $enum, bool $autoload = true): bool
    {
        return false;
    }
}

if (!function_exists('getGatewayVariables')) {
    function getGatewayVariables(string $gateway): array
    {
        return [
            'enable_logs' => false,
            'fine_days' => '1',
            'pix_expiration' => '1',
            'receiver_pix_key' => 'teste@pix.com',
            'pix_descrip' => '',
        ];
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