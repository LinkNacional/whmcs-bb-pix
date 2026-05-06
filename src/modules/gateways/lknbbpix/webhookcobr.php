<?php

use Lkn\BBPix\App\Pix\Services\InvoiceNoteService;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\ParserHelper;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/vendor/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$rawPayload = file_get_contents('php://input');

if ($rawPayload === false) {
    http_response_code(500);
    exit;
}

$payload = json_decode($rawPayload, true);

http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    if (!is_array($payload)) {
        Logger::log('webhookcobr: payload inválido', ['rawPayload' => $rawPayload]);
        exit;
    }

    $txid = ParserHelper::findFirstValue($payload, ['txid', 'txId']);
    $status = strtoupper(ParserHelper::findFirstValue($payload, ['status', 'situacao', 'estado']));
    $amount = ParserHelper::findAmount($payload);

    if ($txid === '' || $status === '') {
        Logger::log('webhookcobr: dados insuficientes', ['payload' => $payload]);
        exit;
    }

    $invoiceId = ParserHelper::extractInvoiceIdFromTxid($txid);

    if ($invoiceId <= 0) {
        Logger::log('webhookcobr: invoiceId inválido no txid', ['txid' => $txid, 'payload' => $payload]);
        exit;
    }

    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(['id', 'status', 'balance']);

    if (!$invoice) {
        Logger::log('webhookcobr: fatura não encontrada', ['invoiceId' => $invoiceId, 'txid' => $txid]);
        exit;
    }

    if ($status === 'CONCLUIDA') {
        if (strtolower((string) $invoice->status) !== 'paid') {
            if ($amount <= 0.0) {
                $amount = (float) $invoice->balance;
            }

            addInvoicePayment($invoiceId, $txid, $amount, 0.0, 'lknbbpix');

            Logger::log(
                'webhookcobr: pagamento aplicado',
                ['invoiceId' => $invoiceId, 'txid' => $txid, 'amount' => $amount, 'status' => $status]
            );
        } else {
            Logger::log(
                'webhookcobr: fatura já paga, sem ação',
                ['invoiceId' => $invoiceId, 'txid' => $txid, 'status' => $status]
            );
        }

        exit;
    }

    if (in_array($status, ['REJEITADA', 'EXPIRADA'], true)) {
        $note = 'Tentativa de Pix Automático falhou/expirou. A fatura segue aberta para pagamento manual.';

        (new InvoiceNoteService())->append($invoiceId, $note);
        webhookcobrAppendOrderNote($invoiceId, $note);

        Logger::log(
            'webhookcobr: falha/expiração registrada',
            ['invoiceId' => $invoiceId, 'txid' => $txid, 'status' => $status]
        );
    }
} catch (Throwable $e) {
    Logger::log(
        'webhookcobr: erro interno',
        ['rawPayload' => $rawPayload],
        ['error' => $e->getMessage()]
    );
}

function webhookcobrAppendOrderNote(int $invoiceId, string $note): void
{
    try {
        $order = Capsule::table('tblorders')
            ->where('invoiceid', $invoiceId)
            ->first(['id', 'notes']);

        if (!$order) {
            return;
        }

        $currentNotes = trim((string) ($order->notes ?? ''));
        $finalNotes = $currentNotes === '' ? $note : $currentNotes . PHP_EOL . $note;

        Capsule::table('tblorders')
            ->where('id', $order->id)
            ->update(['notes' => $finalNotes]);
    } catch (Throwable $e) {
        Logger::log(
            'webhookcobr: erro ao anexar nota no pedido',
            ['invoiceId' => $invoiceId, 'note' => $note],
            ['error' => $e->getMessage()]
        );
    }
}
