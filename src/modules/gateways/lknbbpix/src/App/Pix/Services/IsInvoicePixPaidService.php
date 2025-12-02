<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepositoryLate;

/**
 * The purpose of this service is to check if a given CRIADOx invoice
 * transaction is paid by checking on the API.
 *
 * @since 2.0.0
 */
final class IsInvoicePixPaidService
{
    private PixApiRepository $pixGatewayCob;
    private PixApiRepositoryLate $pixGatewayCobv;

    public function __construct()
    {
        $this->pixGatewayCob = new PixApiRepository();
        $this->pixGatewayCobv = new PixApiRepositoryLate();
    }

    public function run(int $invoiceId, string $whmcsTransId): bool|array
    {
        $pixTaxId = PixTaxId::fromWhmcsTransId($whmcsTransId, $invoiceId);

        // Try cobv first (vencimento/juros e multa), then cob
        $consultResponses = [];
        try {
            $consultResponses[] = $this->pixGatewayCobv->consultPix($pixTaxId) ?: [];
        } catch (\Throwable) {
            $consultResponses[] = [];
        }
        try {
            $consultResponses[] = $this->pixGatewayCob->consultPix($pixTaxId) ?: [];
        } catch (\Throwable) {
            $consultResponses[] = [];
        }

        foreach ($consultResponses as $consultPixResponse) {
            if (!is_array($consultPixResponse) || empty($consultPixResponse)) {
                continue;
            }

            $pixTransactions = $consultPixResponse['pix'] ?? [];
            $pixTransactionForTaxId = false;

            if (!empty($pixTransactions)) {
                $pixTransactionForTaxId = current(array_filter(
                    $pixTransactions,
                    fn (array $trans) => ($trans['txid'] ?? '') === $pixTaxId->getApiTransId()
                ));
            }

            if ($pixTransactionForTaxId && ($consultPixResponse['status'] ?? null) === 'CONCLUIDA') {
                // Paid amount may come as componentesValor->original->valor or as valor
                $paidAmount = $pixTransactionForTaxId['componentesValor']['original']['valor']
                    ?? $pixTransactionForTaxId['valor']
                    ?? null;

                if ($paidAmount === null) {
                    continue;
                }

                return [
                    'apiTxId' => $consultPixResponse['txid'] ?? $pixTaxId->getApiTransId(),
                    'paidAmount' => $paidAmount,
                    'paymentDate' => $pixTransactionForTaxId['horario'] ?? '',
                    'endToEndId' => $pixTransactionForTaxId['endToEndId'] ?? ''
                ];
            }
        }

        return false;
    }
}
