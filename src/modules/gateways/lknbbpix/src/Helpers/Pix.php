<?php

namespace Lkn\BBPix\Helpers;

use DateInterval;
use DateTime;

final class Pix
{
    public static function isExpired(string $expirationDate, string $createdAtDate, string $cobType = 'cob'): bool
    {
        $today = new DateTime();

        if ($cobType === 'cobv') {
            // For COBV, expiration is based on due date + validity period after due date
            // $expirationDate is the due date
            // $createdAtDate is the validity period after due date (in days)
            
            // Validate due date
            if (empty($expirationDate) || !self::isValidDate($expirationDate)) {
                return true; // Consider expired if invalid date
            }
            
            $dueDate = new DateTime($expirationDate);
            $expiryDate = clone $dueDate;
            
            // Validate validity period
            if (!empty($createdAtDate) && is_numeric($createdAtDate)) {
                $expiryDate->add(new DateInterval('P' . $createdAtDate . 'D'));
            }

            return $today > $expiryDate;
        }

        // For conventional COB (original behavior)
        // Validate expiration period
        if (empty($expirationDate) || !is_numeric($expirationDate) || (int)$expirationDate <= 0) {
            return true; // Consider expired if invalid expiration
        }
        
        // Validate creation date
        if (empty($createdAtDate) || !self::isValidDate($createdAtDate)) {
            return true; // Consider expired if invalid date
        }
        
        $pixValidityPeriod = new DateInterval('PT' . $expirationDate . 'S');
        $expiryDate = new DateTime($createdAtDate);
        $expiryDate->add($pixValidityPeriod);

        return $today > $expiryDate;
    }
    
    /**
     * Extract interest and fine information from a consultPix response.
     * Also infers cob/cobv based on calendario fields.
     */
    public static function extractInterestPenalty(array $consultPix): array
    {
        $isCobv = isset($consultPix['calendario']['dataDeVencimento']);

        $jurosCfg = $consultPix['valor']['juros'] ?? null;
        $multaCfg = $consultPix['valor']['multa'] ?? null;

        $jurosVal = 0.0;
        if (is_array($jurosCfg)) {
            $jurosVal = (float) ($jurosCfg['valor'] ?? $jurosCfg['valorPerc'] ?? 0);
        } elseif ($jurosCfg !== null) {
            $jurosVal = (float) $jurosCfg;
        }

        $multaVal = 0.0;
        if (is_array($multaCfg)) {
            $multaVal = (float) ($multaCfg['valor'] ?? $multaCfg['valorPerc'] ?? 0);
        } elseif ($multaCfg !== null) {
            $multaVal = (float) $multaCfg;
        }

        return [
            'isCobv' => $isCobv,
            'jurosVal' => $jurosVal,
            'multaVal' => $multaVal,
            'hasInterestPenalty' => ($jurosVal > 0.0 || $multaVal > 0.0),
            'dueDate' => $consultPix['calendario']['dataDeVencimento'] ?? null
        ];
    }

    /**
     * Check if a date string is valid
     */
    private static function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $date);
        if ($d === false) {
            $d = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $date);
        }
        if ($d === false) {
            $d = DateTime::createFromFormat('Ymd', $date);
        }
        if ($d === false) {
            $d = DateTime::createFromFormat('Y-m-d', $date);
        }
        
        return $d !== false && $d->format('Y-m-d\TH:i:s.u\Z') === $date ||
               $d !== false && $d->format('Y-m-d\TH:i:s\Z') === $date ||
               $d !== false && $d->format('Ymd') === $date ||
               $d !== false && $d->format('Y-m-d') === $date;
    }
}
