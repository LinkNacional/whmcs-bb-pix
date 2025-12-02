<?php

namespace Lkn\BBPix\App\Pix\Services;

use DateTime;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Config;

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
        $pixTaxId = PixTaxId::fromApi('PAGO', $apiTxId);
        $invoiceId = $pixTaxId->invoiceId;

        $invoiceLastTransaction = Invoice::getTransactionByTransactionId($invoiceId, 'PAGOx' . $pixTaxId->suffix . 'x' . $pixEndToEndId);
        $totalResults = (int) $invoiceLastTransaction['totalresults'] ?? 0;

        if ($totalResults > 0) {
            $this->addNoteToInvoice(
                $invoiceId,
                'Pix: validação reconheceu fatura já paga, método de pagamento não permite pagamento parcial'
            );
            return;
        }

        // Trabalhar em centavos para evitar flutuações de ponto flutuante
        $invoiceBalanceCents = $this->toCents(Invoice::getBalance($invoiceId));
        $paidCents = $this->toCents($paidAmount);

        // TODO Ideal seria verificar taxa de desconto do pedido
        if ($paidCents < $invoiceBalanceCents) {
            $discount = $this->getDiscountValue($this->fromCents($paidCents), $this->fromCents($invoiceBalanceCents));
            $discountService = new DiscountService($invoiceId);
            $paymentValueWithDiscount = (float) $discountService->calculate();
            $paymentValueWithDiscountCents = $this->toCents($paymentValueWithDiscount);

            $discountAmount = $discount['discountAmount'];
            $discountPercentage = $discount['discountPercentage'];

            $addDiscountResponse = false;
            // Aplica desconto somente se bater exatamente em centavos
            if ($paymentValueWithDiscountCents === $paidCents) {
                $addDiscountResponse = Invoice::addDiscount(
                    $invoiceId,
                    $discountAmount,
                    "Pix: aplicação de {$discountPercentage}% de desconto"
                );
            }

            if (!$addDiscountResponse && $paymentValueWithDiscountCents === $paidCents) {
                $this->addNoteToInvoice(
                    $invoiceId,
                    "Pix: erro ao adicionar desconto de R$ " . number_format($discountAmount, 2, ',', '.') . " à fatura"
                );
            }
        }

        // TODO ideal seria verificar se o pagamento teve juros
        if ($paidCents > $invoiceBalanceCents) {
            $tax = $this->getTaxValue($this->fromCents($paidCents), $this->fromCents($invoiceBalanceCents));
            $taxAmount = $tax['taxAmount'] ?? 0.0;
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

        $invoiceClientId = Invoice::getClientId($invoiceId);
        $whmcsTransacId = $pixTaxId->getTransIdForWhmcs($pixEndToEndId);

        $whmcsPaymentDate = (new DateTime($paymentDate))->format('d/m/Y');

        // Taxa transacional configurável aplicada como fee nas transações PAGO
        $transactionFee = (float) (Config::setting('transaction_fee') ?? 0.0);
        if ($transactionFee < 0) {
            $transactionFee = 0.0;
        }

        $addTranscResponse = Invoice::addTransac(
            $invoiceClientId,
            $invoiceId,
            $whmcsTransacId,
            $this->fromCents($paidCents),
            $whmcsPaymentDate,
            '',
            $transactionFee
        );

        if (($addTranscResponse['result'] ?? null) !== 'success') {
            $this->addNoteToInvoice(
                $invoiceId,
                'Pix: erro ao adicionar transação à fatura'
            );
        }

        $setInvoiceAsPaidResponse = [];

        if ($paidCents >= $invoiceBalanceCents) {
            $setInvoiceAsPaidResponse = $this->setInvoiceAsPaid($invoiceId);
        }

        if (isset($setInvoiceAsPaidResponse['result']) && $setInvoiceAsPaidResponse['result'] !== 'success') {
            $this->addNoteToInvoice(
                $invoiceId,
                'Pix: erro ao marcar fatura como paga'
            );
        }
    }

    private function getDiscountValue(
        float $paidAmount,
        float $invoiceBalance
    ): array {
        $discountAmount = $paidAmount - $invoiceBalance;

        $discountPercentage = abs(($discountAmount / max($invoiceBalance, 0.01)) * 100);
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

    private function setInvoiceAsPaid(int $invoiceId)
    {
        $postData = [
            'invoiceid' => $invoiceId,
            'status' => 'Paid',
            'datepaid' => date('Y-m-d')
        ];

        $response = \localAPI('UpdateInvoice', $postData);

        Logger::log(
            'Adicionar nota sobre desconto',
            ['invoiceId' => $invoiceId],
            ['UpdateInvoice' => $response]
        );

        return $response;
    }

    private function addNoteToInvoice(int $invoiceId, string $note): void
    {
        $invoice = \localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        $notes = trim(($invoice['notes'] ?? '') . "\n" . $note);

        $updateInvoiceResponse = \localAPI(
            'UpdateInvoice',
            ['invoiceid' => $invoiceId, 'notes' => $notes]
        );

        Logger::log(
            'Adicionar nota em fatura',
            ['invoiceId' => $invoiceId, 'note' => $note],
            ['GetInvoice' => $invoice, 'UpdateInvoice' => $updateInvoiceResponse]
        );
    }

    private function toCents(float|string $value): int
    {
        // Aceita string "617.90" ou float 617.9; converte para centavos inteiros com arredondamento padrão
        $floatVal = (float) $value;
        return (int) round($floatVal * 100, 0);
    }

    private function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
