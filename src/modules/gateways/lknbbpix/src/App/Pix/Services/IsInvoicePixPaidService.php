<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\PixApiRepository;
use Lkn\BBPix\Helpers\Logger;
use Throwable;

/**
 * The purpose of this service is to check if a given CRIADOx invoice
 * transaction is paid by checking on the API.
 *
 * @since 2.0.0
 */
final class IsInvoicePixPaidService
{
    private PixApiRepository $pixGateway;

    public function __construct()
    {
        $this->pixGateway = new PixApiRepository();
    }

    public function run(int $invoiceId, string $whmcsTransId): bool|array
    {
        try {
            $pixTaxId = PixTaxId::fromWhmcsTransId($whmcsTransId, $invoiceId);
            $consultPixResponse = $this->pixGateway->consultPix($pixTaxId);
        } catch (Throwable $e) {
            Logger::log(
                'IsInvoicePixPaidService: falha ao consultar Pix pago',
                [
                    'invoiceId' => $invoiceId,
                    'whmcsTransId' => $whmcsTransId
                ],
                ['error' => $e->getMessage()]
            );

            return false;
        }

        $pixTransactions = $consultPixResponse['pix'];
        $pixTransactionForTaxId = false;

        if (!empty($pixTransactions)) {
            $pixTransactionForTaxId = current(array_filter(
                $pixTransactions,
                fn (array $trans) => $trans['txid'] === $pixTaxId->getApiTransId()
            ));
        }

        if ($pixTransactionForTaxId && isset($consultPixResponse['status']) && $consultPixResponse['status'] === 'CONCLUIDA') {
            return [
                'apiTxId' => $consultPixResponse['txid'],
                'paidAmount' => $pixTransactionForTaxId['componentesValor']['original']['valor'],
                'paymentDate' => $pixTransactionForTaxId['horario'],
                'endToEndId' => $pixTransactionForTaxId['endToEndId']
            ];
        }

        return false;
    }
}
