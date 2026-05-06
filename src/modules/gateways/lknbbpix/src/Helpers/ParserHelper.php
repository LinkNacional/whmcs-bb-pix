<?php

namespace Lkn\BBPix\Helpers;

final class ParserHelper
{
    public static function findFirstValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key]) && !is_object($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::findFirstValue($value, $keys);

                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    public static function findAmount(array $payload): float
    {
        if (isset($payload['valor']) && is_array($payload['valor']) && isset($payload['valor']['original'])) {
            return (float) $payload['valor']['original'];
        }

        if (isset($payload['valor']) && is_scalar($payload['valor'])) {
            return (float) $payload['valor'];
        }

        if (isset($payload['cobr']) && is_array($payload['cobr'])) {
            return self::findAmount($payload['cobr']);
        }

        if (isset($payload['pix']) && is_array($payload['pix']) && isset($payload['pix'][0]) && is_array($payload['pix'][0])) {
            if (isset($payload['pix'][0]['valor'])) {
                return (float) $payload['pix'][0]['valor'];
            }
        }

        return 0.0;
    }

    public static function extractInvoiceIdFromTxid(string $txid): int
    {
        $txid = strtoupper(trim($txid));

        if (preg_match('/^LKN(\d{10})[A-Z0-9]{13}$/', $txid, $matches)) {
            $invoice = ltrim($matches[1], '0');

            return (int) ($invoice === '' ? '0' : $invoice);
        }

        if (preg_match('/^(\d+)x/i', $txid, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}