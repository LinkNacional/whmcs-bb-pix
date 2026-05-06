<?php

namespace Lkn\BBPix\App\Pix\Services;

final class PixTxidService
{
    private const PREFIX = 'LKN';

    private const HASH_SALT = 'seu_salt_secreto_aqui';

    public static function generateForInvoice(int|string $invoiceId): string
    {
        $invoiceId = (string) $invoiceId;
        $paddedInvoice = str_pad($invoiceId, 10, '0', STR_PAD_LEFT);
        $hash = substr(hash('sha256', $invoiceId . self::HASH_SALT), 0, 13);

        return strtoupper(self::PREFIX . $paddedInvoice . $hash);
    }
}