<?php

namespace Lkn\BBPix\Helpers;

use RuntimeException;

final class BrCode
{
    private const GUI_PIX = 'br.gov.bcb.pix';
    private const MAX_LOCATION_LENGTH = 77;
    private const DEFAULT_MERCHANT_NAME = 'NAO INFORMADO';
    private const DEFAULT_MERCHANT_CITY = 'BRASILIA';

    public static function fromLocation(string $location, string $merchantName, string $merchantCity): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new RuntimeException('Pix location cannot be empty.');
        }

        if (strlen($location) > self::MAX_LOCATION_LENGTH) {
            throw new RuntimeException('Pix location exceeds allowed length (77).');
        }

        $merchantName = self::sanitizeMerchantName($merchantName);
        $merchantCity = self::sanitizeMerchantCity($merchantCity);

        $merchantAccountInfo = self::formatTag('00', self::GUI_PIX);
        $recurrenceData = self::formatTag('00', self::GUI_PIX) . self::formatTag('25', $location);

        $segments = [
            self::formatTag('00', '01'),
            self::formatTag('26', $merchantAccountInfo),
            self::formatTag('52', '0000'),
            self::formatTag('53', '986'),
            self::formatTag('58', 'BR'),
            self::formatTag('59', $merchantName),
            self::formatTag('60', $merchantCity),
            self::formatTag('62', self::formatTag('05', '***')),
            self::formatTag('80', $recurrenceData),
        ];

        $payload = implode('', $segments);
        $crc = self::crc16($payload . '6304');

        return $payload . '6304' . $crc;
    }

    public static function sanitizeMerchantName(?string $value): string
    {
        return self::sanitizeText($value, 25, self::DEFAULT_MERCHANT_NAME);
    }

    public static function sanitizeMerchantCity(?string $value): string
    {
        return self::sanitizeText($value, 15, self::DEFAULT_MERCHANT_CITY);
    }

    private static function sanitizeText(?string $value, int $maxLength, string $fallback): string
    {
        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            $value = $fallback;
        }

        $normalized = $value;
        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($value, \Normalizer::FORM_D) ?: $value;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        $upper = strtoupper($ascii);
        $sanitized = preg_replace('/[^A-Z0-9 ]/', '', $upper) ?: '';
        $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized));

        if ($sanitized === '') {
            $sanitized = $fallback;
        }

        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }

    private static function formatTag(string $id, string $value): string
    {
        $length = str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $length . $value;
    }

    private static function crc16(string $payload): string
    {
        $polynomial = 0x1021;
        $result = 0xFFFF;

        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $result ^= (ord($payload[$i]) << 8);

            for ($bit = 0; $bit < 8; $bit++) {
                if (($result & 0x8000) !== 0) {
                    $result = (($result << 1) & 0xFFFF) ^ $polynomial;
                } else {
                    $result = ($result << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($result & 0xFFFF), 4, '0', STR_PAD_LEFT));
    }
}
