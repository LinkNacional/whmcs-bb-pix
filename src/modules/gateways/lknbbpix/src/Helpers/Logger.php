<?php

namespace Lkn\BBPix\Helpers;

use stdClass;

final class Logger
{
    public static function log(string $result, array|string|object $request, string|bool|array|stdClass $response = []): void
    {
        if (Config::setting('enable_logs')) {
            $log = ['request' => self::sanitize($request)];

            if (!empty($response)) {
                $log['response'] = self::sanitize($response);
            }

            logTransaction(
                'lknbbpix',
                json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $result
            );
        }
    }

    private static function sanitize(array|string|object|bool $data): array|string|object|bool
    {
        if (is_array($data)) {
            $sanitized = [];

            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveKey($key) && is_string($value)) {
                    $sanitized[$key] = self::maskIdentifier($value);
                    continue;
                }

                if (is_array($value) || is_object($value) || is_string($value) || is_bool($value)) {
                    $sanitized[$key] = self::sanitize($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }

            return $sanitized;
        }

        if (is_object($data)) {
            $arrayData = (array) $data;
            $sanitized = self::sanitize($arrayData);

            return (object) $sanitized;
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, [
            'txid',
            'tx_id',
            'txid_deterministico',
            'txiddeterministico',
            'e2eid',
            'endtoendid',
            'end_to_end_id',
            'rtrid',
            'idrec',
            'id_rec'
        ], true);
    }

    private static function maskIdentifier(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
    }
}
