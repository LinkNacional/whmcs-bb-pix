<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\App\Pix\Repositories\AutoPixAllocationRepository;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;

final class AllocatePaymentService
{
    public function run(array $payload): void
    {
        // Expect payload with metadata: invoiceid, itemid, paidAmount, endToEndId, chargeId
        $invoiceId = (int) ($payload['metadata']['invoiceid'] ?? 0);
        $itemId = isset($payload['metadata']['itemid']) ? (int) $payload['metadata']['itemid'] : null;
        $paidAmount = (float) ($payload['paidAmount'] ?? 0);
        $endToEndId = (string) ($payload['endToEndId'] ?? '');
        $chargeId = (string) ($payload['chargeId'] ?? '');

        if ($invoiceId <= 0 || $paidAmount <= 0.0) {
            Logger::log('AutoPix allocate payment: invalid payload', $payload);
            return;
        }

        $repo = new AutoPixAllocationRepository();
        $item = $repo->findInvoiceItemByMetadata($invoiceId, $itemId);

        // Description of allocation
        $description = 'PIX Automático';
        if ($item) {
            $description .= ' - Item #' . $item['id'] . ' ' . ($item['description'] ?? '');
        }

        $transId = 'AUTOPIX.' . $chargeId . '.' . $endToEndId;

        // Partial application using AddInvoicePayment with amount
        $res = localAPI('AddInvoicePayment', [
            'invoiceid' => $invoiceId,
            'transid' => $transId,
            'gateway' => 'lknbbpix',
            'amount' => number_format($paidAmount, 2, '.', ''),
            'date' => date('Y-m-d')
        ]);

        Logger::log('AutoPix allocate payment', [
            'invoiceId' => $invoiceId,
            'itemId' => $itemId,
            'amount' => $paidAmount,
            'transid' => $transId
        ], $res);
    }
}


