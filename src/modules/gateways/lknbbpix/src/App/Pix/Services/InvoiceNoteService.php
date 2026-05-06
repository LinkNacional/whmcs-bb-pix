<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\Helpers\Logger;

final class InvoiceNoteService
{
    public function append(int $invoiceId, string $note): array
    {
        $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        $currentNotes = trim((string) ($invoiceData['notes'] ?? ''));

        $finalNotes = $currentNotes === ''
            ? $note
            : $currentNotes . PHP_EOL . $note;

        $response = localAPI('UpdateInvoice', [
            'invoiceid' => $invoiceId,
            'notes' => $finalNotes,
        ]);

        Logger::log('Adicionar nota da fatura', ['invoiceid' => $invoiceId, 'note' => $note], $response);

        return $response;
    }
}
