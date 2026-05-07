<?php

namespace Lkn\BBPix\Helpers;

$gatewayFunctionsPath = __DIR__ . '/../../../../../includes/gatewayfunctions.php';

if (file_exists($gatewayFunctionsPath)) {
    require_once $gatewayFunctionsPath;
}

/**
 * Provides fast access to the module settings and constants.
 *
 * @since 1.0.0
 *
 * @link
 */
final class Config
{
    final public static function constant(string $constant): mixed
    {
        $constants = require __DIR__ . '/../constants.php';

        return self::getArrayKeyValue($constants, $constant);
    }

    final public static function setting(string $name): mixed
    {
        $settings = getGatewayVariables(self::constant('name'));

        return self::parseConfig($name, $settings[$name]);
    }

    /**
     * @since 1.0.0
     *
     * @param array  $array
     * @param string $keys  can be a key1.subkey1.subkey2.
     *
     * @return mixed
     */
    private static function getArrayKeyValue(array $array, string $keys): mixed
    {
        $keys = explode('.', $keys);

        foreach ($keys as $key) {
            if (is_array($array) && array_key_exists($key, $array)) {
                $array = $array[$key];
            }
        }

        return $array;
    }

    private static function parseConfig(string $name, mixed $value)
    {
        return match ($name) {
            'pix_descrip' => substr(trim($value), 0, 140),
            'recurrence_object_name' => substr(trim((string) $value), 0, 140),
            'pix_expiration' => empty($value) ? 1 : ((int) ($value)),
            'cnpj_cf_id' => (int) ($value),
            'cpf_cf_id' => (int) ($value),
            'cpf_cnpj_cf_id' => (int) ($value),
            'send_payer_doc_and_name' => (bool) ($value),
            'enable_pix_automatic' => $value === 'on',
            'enable_pix_when_invoice_cancel' => is_null($value) ? true : ((bool) $value),
            'discount_for_pix_payment_percentage' => ((float) $value) / 100,
            'ruled_discount_percentage' => ((float) $value) / 100,
            'receiver_pix_key' => self::formatReceiverPixKey($value),
            'enable_share_pix_btn' => (bool) $value,
            'enable_client_manual_check' => (bool) $value,
            'max_client_manual_checks' => (bool) $value,
            'enable_admin_manual_check' => (bool) $value,
            'domain_register_discount_percentage' => $value ? ((float) $value / 100) : 0,
            'product_discount_rule' => $value ?? 'new_orders',
            'enable_logs' => (bool) $value,
            'enable_fees_interest' => (bool) $value,
            'interest_rate' => is_numeric($value) ? $value : '0',
            'cob_type' => (string) $value,
            'fine' => is_numeric($value) ? $value : '0',
            'fine_days' => is_numeric($value) ? $value : '1',
            'enable_fees_calculation' => (bool) $value,
            'convenio' => trim((string) $value),
            'cnpj_recebedor' => preg_replace('/\D/', '', (string) $value),
            default => trim($value)
        };
    }

    /**
     * @since 1.4.0
     *
     * @param string $key
     *
     * @return string
     */
    private static function formatReceiverPixKey(string $key): string
    {
        // Check if it's a valid CPF or CNPJ
        $cpf_cnpj_pattern = '/^(\d{3}\.\d{3}\.\d{3}-\d{2}|\d{11})$|^(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{14})$/';

        if (preg_match($cpf_cnpj_pattern, $key)) {
            return preg_replace('/\D/', '', $key); // Keep only digits
        }

        // Check if it's a valid phone number
        $phone_pattern = '/^\+[1-9]{1}[0-9]{3,14}$/';

        if (preg_match($phone_pattern, $key)) {
            return preg_replace('/\D/', '', $key); // Keep only digits
        }

        return $key;
    }
}
