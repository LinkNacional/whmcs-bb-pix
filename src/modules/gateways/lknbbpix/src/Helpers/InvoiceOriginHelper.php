<?php

namespace Lkn\BBPix\Helpers;

use WHMCS\Database\Capsule;

class InvoiceOriginHelper
{
    public const NOVO_PEDIDO = 'NOVO_PEDIDO';

    public const CRON_RENOVACAO = 'CRON_RENOVACAO';

    public const MANUAL_TRADICIONAL = 'MANUAL_TRADICIONAL';

    public function classify(int $invoiceId): string
    {
        if ($this->hasOrder($invoiceId)) {
            return self::NOVO_PEDIDO;
        }

        if ($this->hasRecurringItems($invoiceId)) {
            return self::CRON_RENOVACAO;
        }

        return self::MANUAL_TRADICIONAL;
    }

    protected function hasOrder(int $invoiceId): bool
    {
        return Capsule::table('tblorders')
            ->where('invoiceid', $invoiceId)
            ->exists();
    }

    protected function hasRecurringItems(int $invoiceId): bool
    {
        return Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->whereIn('type', ['Hosting', 'Domain', 'Addon', 'Upgrade'])
            ->exists();
    }
}
