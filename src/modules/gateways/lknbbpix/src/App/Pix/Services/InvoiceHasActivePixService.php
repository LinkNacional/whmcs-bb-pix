<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;
use Lkn\BBPix\App\Pix\Services\DiscountService;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Pix;

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
        \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'start', 'invoiceId' => $invoiceId]);
        $localApiResponse = localAPI('GetTransactions', ['invoiceid' => $invoiceId]);

        $transacs = $localApiResponse['transactions']['transaction'] ?? null;

        if (!is_array($transacs) || count($transacs) === 0) {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'no-transactions', 'invoiceId' => $invoiceId]);
            return false;
        }

        // Pegar a última transação do NOSSO gateway (independente do prefixo)
        $latestGatewayTrans = null;
        for ($i = count($transacs) - 1; $i >= 0; $i--) {
            $t = $transacs[$i];
            $gateway = $t['gateway'] ?? '';
            if ($gateway === Config::constant('name')) {
                $latestGatewayTrans = $t;
                break;
            }
        }

        if (!$latestGatewayTrans) {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'no-gateway-trans', 'invoiceId' => $invoiceId]);
            return false;
        }

        // A última do nosso gateway DEVE ser CRIADOx para permitir reuso, senão não faz nada
        $latestTransId = (string) ($latestGatewayTrans['transid'] ?? '');
        if (!str_starts_with($latestTransId, 'CRIADOx')) {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'last-is-not-CRIADOx', 'invoiceId' => $invoiceId, 'transid' => $latestTransId]);
            return false;
        }

        $taxId = PixTaxId::fromWhmcsTransId($latestGatewayTrans['transid'], $invoiceId);

        $consultPixResponse = $this->pixGateway->consultPix($taxId);
        if (!is_array($consultPixResponse) || ($consultPixResponse['status'] ?? '') !== 'ATIVA') {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'pix-not-active', 'invoiceId' => $invoiceId], $consultPixResponse ?? []);
            return false;
        }

        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        // Validação de expiração básica
        $expirationDate = $consultPixResponse['calendario']['expiracao'] ?? '';
        $createdAtDateRaw = $consultPixResponse['calendario']['criacao'] ?? '';
        if ($cobType === 'cob' && !empty($expirationDate) && !empty($createdAtDateRaw)) {
            if (Pix::isExpired($expirationDate, $createdAtDateRaw)) {
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'expired-cob', 'invoiceId' => $invoiceId, 'exp' => $expirationDate, 'created' => $createdAtDateRaw]);
                return false;
            }
        }
        if ($cobType === 'cobv') {
            $dueDatePix = $consultPixResponse['calendario']['dataDeVencimento'] ?? '';
            $validityAfterDueDate = $consultPixResponse['calendario']['validadeAposVencimento'] ?? '';
            if (!empty($dueDatePix)) {
                if (Pix::isExpired($dueDatePix, $validityAfterDueDate, 'cobv')) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'expired-cobv', 'invoiceId' => $invoiceId, 'due' => $dueDatePix, 'valid' => $validityAfterDueDate]);
                    return false;
                }
            }
        }

        // Datas normalizadas (Y-m-d)
        $todayYmd = (new \DateTime())->format('Y-m-d');
        $invoiceDueYmd = (string) \Lkn\BBPix\Helpers\Invoice::getDueDate($invoiceId);
        // Tentar normalizar criacao -> Y-m-d
        $createdAtYmd = null;
        try {
            $dt = new \DateTime($createdAtDateRaw);
            $createdAtYmd = $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            $createdAtYmd = null;
        }

        // Helper para comparar multa/juros com config
        $matchesInterestAndFine = function(array $consult): bool {
            $enableFeesInterest = Config::setting('enable_fees_interest');
            if (!$enableFeesInterest) {
                return !(isset($consult['valor']['juros']) || isset($consult['valor']['multa']));
            }

            $isFinePercent = (Config::setting('cob_type') !== 'fixed');
            $fineValue = Config::setting('fine') ?? '0';
            $interestValue = Config::setting('interest_rate') ?? '0';

            $expectedMulta = null;
            if ($fineValue !== '0') {
                $expectedMulta = $isFinePercent
                    ? ['modalidade' => 2, 'valorPerc' => number_format((float) $fineValue, 2, '.', '')]
                    : ['modalidade' => 1, 'valor' => number_format((float) $fineValue, 2, '.', '')];
            }

            $expectedJuros = null;
            if ($interestValue !== '0') {
                $expectedJuros = ['modalidade' => 2, 'valorPerc' => number_format((float) $interestValue, 2, '.', '')];
            }

            $actualMulta = $consult['valor']['multa'] ?? null;
            $actualJuros = $consult['valor']['juros'] ?? null;

            $okMulta = json_encode($expectedMulta) === json_encode($actualMulta);
            $okJuros = json_encode($expectedJuros) === json_encode($actualJuros);

            return $okMulta && $okJuros;
        };

        // Valores esperados
        $discountService = new DiscountService($invoiceId);
        $paymentValueWithDiscount = $discountService->calculate();
        $pixOriginalStr = (string) ($consultPixResponse['valor']['original'] ?? '0');

        // Cenário 1: Hoje < Vencimento (antes do vencimento)
        if ($todayYmd < $invoiceDueYmd) {
            if ($cobType === 'cobv') {
                $duePix = (string) ($consultPixResponse['calendario']['dataDeVencimento'] ?? '');
                if ($duePix !== $invoiceDueYmd) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'before-due-cobv-due-mismatch', 'invoiceId' => $invoiceId, 'duePix' => $duePix, 'dueInv' => $invoiceDueYmd]);
                    return false;
                }
                if (!$matchesInterestAndFine($consultPixResponse)) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'before-due-cobv-interest-fine-mismatch', 'invoiceId' => $invoiceId]);
                    return false;
                }
                if ($pixOriginalStr !== (string) $paymentValueWithDiscount) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'before-due-cobv-value-mismatch', 'pix' => $pixOriginalStr, 'disc' => $paymentValueWithDiscount]);
                    return false;
                }
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-before-due-cobv', 'invoiceId' => $invoiceId]);
                return [
                    'location' => $consultPixResponse['location'],
                    'pixValue' => $consultPixResponse['valor']['original'],
                    'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
                ];
            } else {
                if ($pixOriginalStr !== (string) $paymentValueWithDiscount) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'before-due-cob-value-mismatch', 'pix' => $pixOriginalStr, 'disc' => $paymentValueWithDiscount]);
                    return false;
                }
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-before-due-cob', 'invoiceId' => $invoiceId]);
                return [
                    'location' => $consultPixResponse['location'],
                    'pixValue' => $consultPixResponse['valor']['original'],
                    'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
                ];
            }
        }

        // Cenário 2: Hoje >= Vencimento (no/apos vencimento)
        if ($createdAtYmd === null) {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'missing-createdAt', 'invoiceId' => $invoiceId, 'createdRaw' => $createdAtDateRaw]);
            return false;
        }

        if ($createdAtYmd <= $invoiceDueYmd) {
            if ($cobType === 'cobv') {
                $duePix = (string) ($consultPixResponse['calendario']['dataDeVencimento'] ?? '');
                if ($duePix !== $invoiceDueYmd) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'on-due-cobv-due-mismatch', 'invoiceId' => $invoiceId, 'duePix' => $duePix, 'dueInv' => $invoiceDueYmd]);
                    return false;
                }
                if (!$matchesInterestAndFine($consultPixResponse)) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'on-due-cobv-interest-fine-mismatch', 'invoiceId' => $invoiceId]);
                    return false;
                }
                $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
                $items = $invoice['items']['item'] ?? [];
                $lateFeesSum = 0.0;
                foreach ($items as $item) {
                    if (isset($item['type']) && $item['type'] === 'LateFee') {
                        $lateFeesSum += (float) ($item['amount'] ?? 0);
                    }
                }
                $targetValue = number_format(((float) $paymentValueWithDiscount) - $lateFeesSum, 2, '.', '');
                if ($pixOriginalStr !== $targetValue) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'on-due-cobv-value-mismatch', 'pix' => $pixOriginalStr, 'target' => $targetValue, 'disc' => $paymentValueWithDiscount, 'late' => $lateFeesSum]);
                    return false;
                }
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-on-due-cobv', 'invoiceId' => $invoiceId]);
                return [
                    'location' => $consultPixResponse['location'],
                    'pixValue' => $consultPixResponse['valor']['original'],
                    'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
                ];
            } else {
                if ($pixOriginalStr !== (string) $paymentValueWithDiscount) {
                    \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'on-due-cob-value-mismatch', 'pix' => $pixOriginalStr, 'disc' => $paymentValueWithDiscount]);
                    return false;
                }
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-on-due-cob', 'invoiceId' => $invoiceId]);
                return [
                    'location' => $consultPixResponse['location'],
                    'pixValue' => $consultPixResponse['valor']['original'],
                    'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
                ];
            }
        }

        if ($cobType === 'cobv') {
            $invoiceBalanceStr = number_format((float) \Lkn\BBPix\Helpers\Invoice::getBalance($invoiceId), 2, '.', '');
            if ($pixOriginalStr !== $invoiceBalanceStr) {
                \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'after-due-cobv-value-mismatch', 'pix' => $pixOriginalStr, 'balance' => $invoiceBalanceStr]);
                return false;
            }
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-after-due-cobv', 'invoiceId' => $invoiceId]);
            return [
                'location' => $consultPixResponse['location'],
                'pixValue' => $consultPixResponse['valor']['original'],
                'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
            ];
        }

        if ($pixOriginalStr !== (string) $paymentValueWithDiscount) {
            \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'after-due-cob-value-mismatch', 'pix' => $pixOriginalStr, 'disc' => $paymentValueWithDiscount]);
            return false;
        }
        \Lkn\BBPix\Helpers\Logger::log('PIX Reuse Check', ['step' => 'reuse-after-due-cob', 'invoiceId' => $invoiceId]);
        return [
            'location' => $consultPixResponse['location'],
            'pixValue' => $consultPixResponse['valor']['original'],
            'pixCopiaECola' => $consultPixResponse['pixCopiaECola']
        ];
    }
}
