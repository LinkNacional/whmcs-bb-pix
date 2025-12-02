<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use WHMCS\Database\Capsule;

final class AutoPixAllocationRepository extends AbstractDbRepository
{
    protected string $table = 'tblinvoices';

    public function findInvoiceItemByMetadata(int $invoiceId, ?int $itemId): ?array
    {
        $row = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->when($itemId, function ($q) use ($itemId) {
            $q->where('id', $itemId);
        })->first();

        return $row ? (array) $row : null;
    }
}


