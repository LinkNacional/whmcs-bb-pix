<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\PixApiRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Pix;
use Throwable;

/**
 * Checks if the latest Pix for this invoice is still active.
 *
 * Checks by the taxid encountered in the invoice transactions. Returns
 * false if the latest transaction is not for the current payment method or
 * the Pix has expired or the transid is prefixed by REEMBOLSOx or PAGOx.
 *
 * @since 1.2.0
 */
final class InvoiceHasActivePixService
{
    /**
     * @since 1.2.0
     * @var PixApiRepository
     */
    private PixApiRepository $pixGateway;

    public function __construct(PixApiRepository $pixGateway)
    {
        $this->pixGateway = $pixGateway;
    }

    /**
     * @since 1.2.0
     *
     * @param int $invoiceId
     *
     * @return false|array array structure is [location => , pixValue => ].
     *                     "location" is the pix code.
     */
    public function run(int $invoiceId): false|array
    {
        $localApiResponse = localAPI('GetTransactions', ['invoiceid' => $invoiceId]);

        $transacs = $localApiResponse['transactions']['transaction'] ?? null;

        if (!is_array($transacs) || count($transacs) === 0) {
            return false;
        }

        $latestTransac = end($transacs);

        // The last invoice transaction must have been entered by the gateway.
        if ($latestTransac['gateway'] !== Config::constant('name')) {
            return false;
        }

        // "CRIADOx" means the Pix was not paid and may be not expired yet and can be reused.
        if (!str_contains($latestTransac['transid'], 'CRIADOx')) {
            return false;
        }

        try {
            $taxId = PixTaxId::fromWhmcsTransId($latestTransac['transid'], $invoiceId);
            $consultPixResponse = $this->pixGateway->consultPix($taxId);
        } catch (Throwable $e) {
            Logger::log(
                'InvoiceHasActivePixService: falha ao consultar Pix ativo',
                [
                    'invoiceId' => $invoiceId,
                    'transid' => $latestTransac['transid']
                ],
                ['error' => $e->getMessage()]
            );

            return false;
        }

        if ($consultPixResponse['status'] !== 'ATIVA') {
            return false;
        }

        $expirationDate = $consultPixResponse['calendario']['expiracao'] ?? '';
        $createdAtDate = $consultPixResponse['calendario']['criacao'] ?? '';
        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        if ($cobType === 'cobv') {
            $apiDueDate = $this->normalizeDate((string) ($consultPixResponse['calendario']['dataDeVencimento'] ?? ''));
            $invoiceDueDate = $this->normalizeDate(Invoice::getDueDate($invoiceId));

            if ($apiDueDate === '' || $apiDueDate !== $invoiceDueDate) {
                return false;
            }
        }

        if ($cobType === 'cob' && Pix::isExpired($expirationDate, $createdAtDate)) {
            return false;
        }

        $discountService = new DiscountService($invoiceId);
        $paymentValueWithDiscount = $discountService->calculate();

        if ($consultPixResponse['valor']['original'] !== $paymentValueWithDiscount) {
            return false;
        }

        return [
            'location' => $consultPixResponse['location'],
            'pixValue' => $consultPixResponse['valor']['original'],
            'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
        ];
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }
}
