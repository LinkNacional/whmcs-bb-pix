<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\ParserHelper;
use WHMCS\Database\Capsule;

/**
 * Responsible for making the required operations to set an invoice as paid.
 *
 * @since 1.2.0
 */
final class ConfirmPaymentService
{
    public function run(
        string $apiTxId,
        float $paidAmount,
        string $paymentDate,
        string $pixEndToEndId
    ): void {
        $pixTaxId = null;
        $invoiceId = 0;

        try {
            $pixTaxId = PixTaxId::fromApi('PAGO', $apiTxId);
            $invoiceId = $pixTaxId->invoiceId;
        } catch (\Throwable) {
            $invoiceId = 0;
        }

        if ($invoiceId <= 0) {
            $invoiceId = ParserHelper::extractInvoiceIdFromTxid($apiTxId);
        }

        if ($invoiceId <= 0) {
            Logger::log(
                'ConfirmPaymentService: invoiceId inválido no webhook',
                [
                    'apiTxId' => $apiTxId,
                    'endToEndId' => $pixEndToEndId,
                    'paymentDate' => $paymentDate,
                    'paidAmount' => $paidAmount,
                ],
                ['critical' => true, 'reason' => 'invoiceId <= 0 após fallback']
            );

            return;
        }

        $transactionId = $this->resolveTransactionId($pixEndToEndId, $apiTxId, $pixTaxId);
        $lockKey = $this->buildPaymentLockKey($invoiceId, $pixEndToEndId, $transactionId);
        $lockState = $this->acquirePaymentLock($lockKey, 5);

        if ($lockState === false) {
            Logger::log(
                'ConfirmPaymentService: lock_timeout',
                ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'endToEndId' => $pixEndToEndId],
                ['lockKey' => $lockKey]
            );

            return;
        }

        if ($lockState === true) {
            Logger::log(
                'ConfirmPaymentService: lock_acquired',
                ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'endToEndId' => $pixEndToEndId],
                ['lockKey' => $lockKey]
            );
        } else {
            Logger::log(
                'ConfirmPaymentService: lock_unavailable',
                ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'endToEndId' => $pixEndToEndId],
                ['lockKey' => $lockKey]
            );
        }

        try {
            if ($this->isInvoiceAlreadyPaid($invoiceId)) {
                Logger::log(
                    'ConfirmPaymentService: duplicate_detected_after_lock (fatura paga)',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'apiTxId' => $apiTxId]
                );

                return;
            }

            if ($this->transactionExists($invoiceId, $transactionId)) {
                Logger::log(
                    'ConfirmPaymentService: duplicate_detected_after_lock (transação existente)',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'apiTxId' => $apiTxId]
                );

                return;
            }

            $invoiceLastTransaction = Invoice::getTransactionByTransactionId($invoiceId, $transactionId);
            $totalResults = (int) ($invoiceLastTransaction['totalresults'] ?? 0);

            // Para evitar que um mesmo pedido seja pago múltiplas vezes
            // Com isso a verificação do webhook e do front-end não duplicam o pagamento
            if ($totalResults > 0) {
                Logger::log(
                    'ConfirmPaymentService: duplicate_detected_after_lock (transação via GetTransactions)',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'totalResults' => $totalResults]
                );

                $this->addNoteToInvoice(
                    $invoiceId,
                    'Pix: validação reconheceu fatura já paga, método de pagamento não permite pagamento parcial'
                );
                return;
            }

            $invoiceBalance = Invoice::getBalance($invoiceId);
            $invoiceBalance = bcadd($invoiceBalance, '0.005', 2);

            $paidAmount = bcadd($paidAmount, '0.005', 2);

            // TODO Ideal seria verificar taxa de desconto do pedido
            if ($paidAmount < $invoiceBalance) {
                $discount = $this->getDiscountValue($paidAmount, $invoiceBalance);
                $discountService = new DiscountService($invoiceId);
                $paymentValueWithDiscount = (float) $discountService->calculate();
                $paymentValueWithDiscount = bcadd($paymentValueWithDiscount, '0.005', 2);

                $discountAmount = $discount['discountAmount'];
                $discountPercentage = $discount['discountPercentage'];

                $addDiscountResponse = false;
                // Valida se valor pago com desconto é equivalente
                // Ao valor recebido via webhook
                // TODO fazer calculo com números inteiros e não comparar strings
                // Usar bcmath
                if ($paymentValueWithDiscount === $paidAmount) {
                    $addDiscountResponse = Invoice::addDiscount(
                        $invoiceId,
                        $discountAmount,
                        "Pix: aplicação de {$discountPercentage}% de desconto"
                    );
                }

                if (!$addDiscountResponse && $paymentValueWithDiscount === $paidAmount) {
                    $this->addNoteToInvoice(
                        $invoiceId,
                        "Pix: erro ao adicionar desconto de R$ {$discountAmount} à fatura"
                    );
                }
            }

            // TODO ideal seria verificar se o pagamento teve juros
            if ($paidAmount > $invoiceBalance) {
                $tax = $this->getTaxValue($paidAmount, $invoiceBalance);
                $taxAmount = $tax['taxAmount'] ?? 0;
                $taxAmountLabel = number_format($taxAmount, 2, ',', '.');

                $addTaxResponse = Invoice::addTax(
                    $invoiceId,
                    $taxAmount,
                    "Pix: aplicação de {$taxAmountLabel} de juros"
                );

                if (!$addTaxResponse) {
                    $this->addNoteToInvoice(
                        $invoiceId,
                        "Pix: erro ao adicionar taxa de R$ {$taxAmountLabel} à fatura"
                    );
                }
            }

            if (!function_exists('addInvoicePayment')) {
                Logger::log(
                    'ConfirmPaymentService: addInvoicePayment indisponível',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'paidAmount' => $paidAmount],
                    ['critical' => true]
                );

                return;
            }

            $this->ensureCreatedTransactionExists($invoiceId, $pixTaxId, $apiTxId);

            try {
                addInvoicePayment($invoiceId, $transactionId, (float) $paidAmount, 0.0, 'lknbbpix');

                Logger::log(
                    'ConfirmPaymentService: baixa aplicada via addInvoicePayment',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'paidAmount' => $paidAmount]
                );
            } catch (\Throwable $e) {
                Logger::log(
                    'ConfirmPaymentService: erro ao aplicar baixa via addInvoicePayment',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'paidAmount' => $paidAmount],
                    ['error' => $e->getMessage()]
                );
            }
        } finally {
            if ($lockState === true) {
                $released = $this->releasePaymentLock($lockKey);

                Logger::log(
                    'ConfirmPaymentService: lock_released',
                    ['invoiceId' => $invoiceId, 'transactionId' => $transactionId, 'endToEndId' => $pixEndToEndId],
                    ['lockKey' => $lockKey, 'released' => $released]
                );
            }
        }
    }

    private function resolveTransactionId(string $pixEndToEndId, string $apiTxId, ?PixTaxId $pixTaxId): string
    {
        $normalizedEndToEndId = trim($pixEndToEndId);

        if ($pixTaxId instanceof PixTaxId && $pixTaxId->suffix !== '') {
            $transactionId = 'PAGOx' . $pixTaxId->suffix;

            if ($normalizedEndToEndId !== '') {
                return $transactionId . 'x' . $normalizedEndToEndId;
            }

            return $transactionId;
        }

        if ($normalizedEndToEndId !== '') {
            return $normalizedEndToEndId;
        }

        return strtoupper(trim($apiTxId));
    }

    private function isInvoiceAlreadyPaid(int $invoiceId): bool
    {
        try {
            $status = Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->value('status');

            return strtolower((string) $status) === 'paid';
        } catch (\Throwable) {
            return false;
        }
    }

    private function transactionExists(int $invoiceId, string $transactionId): bool
    {
        try {
            return Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->where('transid', $transactionId)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildPaymentLockKey(int $invoiceId, string $pixEndToEndId, string $transactionId): string
    {
        $normalizedEndToEndId = strtoupper(trim($pixEndToEndId));
        $normalizedTransactionId = strtoupper(trim($transactionId));

        $baseKey = $normalizedEndToEndId !== ''
            ? $normalizedEndToEndId
            : ($normalizedTransactionId !== '' ? $normalizedTransactionId : (string) $invoiceId);

        $key = 'lknbbpix:confirm:' . $invoiceId . ':' . $baseKey;

        // GET_LOCK aceita até 64 caracteres.
        if (strlen($key) > 64) {
            return substr($key, 0, 32) . md5($key);
        }

        return $key;
    }

    /**
     * @return bool|null true=lock adquirido, false=timeout, null=indisponível
     */
    private function acquirePaymentLock(string $lockKey, int $timeoutSeconds): ?bool
    {
        try {
            $result = Capsule::selectOne('SELECT GET_LOCK(?, ?) AS lock_result', [$lockKey, $timeoutSeconds]);
            $value = $this->extractScalarValue($result, 'lock_result');

            if ($value === 1) {
                return true;
            }

            if ($value === 0) {
                return false;
            }

            return null;
        } catch (\Throwable $e) {
            Logger::log(
                'ConfirmPaymentService: lock_acquire_error',
                ['lockKey' => $lockKey],
                ['error' => $e->getMessage()]
            );

            return null;
        }
    }

    private function releasePaymentLock(string $lockKey): bool
    {
        try {
            $result = Capsule::selectOne('SELECT RELEASE_LOCK(?) AS lock_result', [$lockKey]);
            $value = $this->extractScalarValue($result, 'lock_result');

            return $value === 1;
        } catch (\Throwable $e) {
            Logger::log(
                'ConfirmPaymentService: lock_release_error',
                ['lockKey' => $lockKey],
                ['error' => $e->getMessage()]
            );

            return false;
        }
    }

    private function extractScalarValue(mixed $result, string $field): int
    {
        if (is_object($result) && isset($result->{$field})) {
            return (int) $result->{$field};
        }

        if (is_array($result) && isset($result[$field])) {
            return (int) $result[$field];
        }

        return -1;
    }

    private function getDiscountValue(
        float $paidAmount,
        float $invoiceBalance
    ): array {
        $discountAmount = $paidAmount - $invoiceBalance;

        $discountPercentage = abs(($discountAmount / $invoiceBalance) * 100);
        $discountPercentage = number_format($discountPercentage, 2, ',', '.');

        return [
            'discountAmount' => $discountAmount,
            'discountPercentage' => $discountPercentage
        ];
    }

    private function getTaxValue(
        float $paidAmount,
        float $invoiceBalance
    ): array {
        $taxAmount = $paidAmount - $invoiceBalance;

        return [
            'taxAmount' => $taxAmount
        ];
    }

    private function addNoteToInvoice(int $invoiceId, string $note): void
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        $notes = trim($invoice['notes'] . "\n" . $note);

        $updateInvoiceResponse = localAPI(
            'UpdateInvoice',
            ['invoiceid' => $invoiceId, 'notes' => $notes]
        );

        Logger::log(
            'Adicionar nota em fatura',
            ['invoiceId' => $invoiceId, 'note' => $note],
            ['GetInvoice' => $invoice, 'UpdateInvoice' => $updateInvoiceResponse]
        );
    }

    private function ensureCreatedTransactionExists(int $invoiceId, ?PixTaxId $pixTaxId, string $apiTxId): void
    {
        $createdTransId = $this->resolveCreatedTransactionId($pixTaxId, $apiTxId);

        if ($createdTransId === '' || $this->transactionExists($invoiceId, $createdTransId)) {
            return;
        }

        $clientId = Invoice::getClientId($invoiceId);

        $addTransacResponse = Invoice::addTransac(
            $clientId,
            $invoiceId,
            $createdTransId,
            0.0,
            '',
            'Pix criado (registro automático de consistência)',
            0.0,
            'lknbbpix'
        );

        if (($addTransacResponse['result'] ?? '') !== 'success') {
            Logger::log(
                'ConfirmPaymentService: falha ao registrar transação CRIADOx automática',
                ['invoiceId' => $invoiceId, 'createdTransId' => $createdTransId],
                $addTransacResponse
            );
        }
    }

    private function resolveCreatedTransactionId(?PixTaxId $pixTaxId, string $apiTxId): string
    {
        if ($pixTaxId instanceof PixTaxId && $pixTaxId->suffix !== '') {
            return 'CRIADOx' . $pixTaxId->suffix;
        }

        if (!str_contains($apiTxId, 'x')) {
            return '';
        }

        $parts = explode('x', $apiTxId, 2);
        $suffix = trim($parts[1] ?? '');

        return $suffix === '' ? '' : 'CRIADOx' . $suffix;
    }
}
