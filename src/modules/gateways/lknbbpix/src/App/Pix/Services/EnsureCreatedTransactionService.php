<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

final class EnsureCreatedTransactionService
{
    public function run(int $invoiceId, string $transId, string $description = 'Pix criado'): void
    {
        if ($invoiceId <= 0 || trim($transId) === '') {
            return;
        }

        if ($this->transactionExists($invoiceId, $transId)) {
            return;
        }

        $clientId = Invoice::getClientId($invoiceId);

        $response = Invoice::addTransac(
            $clientId,
            $invoiceId,
            $transId,
            0.0,
            '',
            $description,
            0.0,
            'lknbbpix'
        );

        if (($response['result'] ?? '') !== 'success') {
            Logger::log(
                'EnsureCreatedTransactionService: falha ao registrar CRIADOx',
                [
                    'invoiceId' => $invoiceId,
                    'transId' => $transId,
                    'description' => $description,
                ],
                $response
            );
        }
    }

    private function transactionExists(int $invoiceId, string $transId): bool
    {
        try {
            return Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->where('transid', $transId)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
