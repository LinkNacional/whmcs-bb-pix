<?php

namespace Lkn\BBPix\Helpers;

use WHMCS\Database\Capsule;

final class Invoice
{
    public static function getTransactions(int $invoiceId)
    {
        return localAPI('GetTransactions', ['invoiceid' => $invoiceId]);
    }

    public static function getTransactionByTransactionId(int $invoiceId, string $transactionId)
    {
        $postFields = [
            'invoiceid' => $invoiceId,
            'transid' => $transactionId
        ];

        return localAPI('GetTransactions', $postFields);
    }

    public static function getStatus(int $invoiceId): string
    {
        return Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first('status')->status;
    }

    public static function markAsRefunded(int $invoiceId): void
    {
        $refundResponse = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Refunded']);

        Logger::log('Mark invoice as refunded', ['invoiceId' => $invoiceId], $refundResponse);
    }

    public static function getBalance(int $invoiceId): float
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        return $invoice['balance'];
    }

    public static function getDueDate(int $invoiceId): string
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        return $invoice['duedate'];
    }

    public static function getTotal(int $invoiceId): float
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        return $invoice['total'];
    }

    public static function getClientId(int $invoiceId): int
    {
        return Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first('userid')
            ->userid;
    }

    public static function addTransac(
        int $clientId,
        int $invoiceId,
        string $transacId,
        float $paymentValue = 0.0,
        string $date = '',
        string $description = '',
        float $fees = 0.0,
        string $paymentMethod = ''
    ) {
        $date = $date === '' ? date('d/m/Y') : $date;
        $paymentMethod = $paymentMethod === '' ? Config::constant('name') : $paymentMethod;
        $data = [
            'paymentmethod' => $paymentMethod,
            'userid' => $clientId,
            'transid' => $transacId,
            'invoiceid' => $invoiceId,
            'date' => $date,
            'description' => $description,
            'fees' => $fees,
            'amountin' => $paymentValue
        ];

        $response = localAPI('AddTransaction', $data);

        Logger::log('Adicionar transação', $data, $response);

        return $response;
    }

    public static function addDiscount(
        int $invoiceId,
        float $value,
        string $description
    ): bool {
        $postData = [
            'invoiceid' => $invoiceId,
            'newitemdescription' => [$description],
            'newitemamount' => [number_format($value, 2, '.', '')]
        ];

        $response = localAPI('UpdateInvoice', $postData);

        Logger::log('Adicionar desconto', $postData, $response);

        return $response['result'] === 'success';
    }

    public static function addTax(
        int $invoiceId,
        float $value,
        string $description
    ): bool {
        $postData = [
            'invoiceid' => $invoiceId,
            'newitemdescription' => [$description],
            'newitemamount' => [number_format($value, 2, '.', '')]
        ];

        $response = localAPI('UpdateInvoice', $postData);

        Logger::log('Adicionar juros', $postData, $response);

        return $response['result'] === 'success';
    }

    public static function setStatusPaid(int $invoiceId): array
    {
        $postData = [
            'invoiceid' => $invoiceId,
            'status' => 'Paid',
            'datepaid' => date('Y-m-d')
        ];

        $response = localAPI('UpdateInvoice', $postData);

        Logger::log('Marcar fatura como paga', ['invoiceId' => $invoiceId], $response);

        return $response;
    }

    public static function appendNote(int $invoiceId, string $note): void
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        $notes = trim((($invoice['notes'] ?? '')) . "\n" . $note);

        $updateInvoiceResponse = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'notes' => $notes]);

        Logger::log(
            'Adicionar nota em fatura',
            ['invoiceId' => $invoiceId, 'note' => $note],
            ['GetInvoice' => $invoice, 'UpdateInvoice' => $updateInvoiceResponse]
        );
    }
}
