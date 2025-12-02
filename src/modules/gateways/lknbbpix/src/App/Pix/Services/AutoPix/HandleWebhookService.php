<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixInstructionRepository;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;
use Lkn\BBPix\App\Pix\Services\AutoPix\ApplyInvoiceFallbackService;

final class HandleWebhookService
{
    public function run(array $payload): void
    {
        Logger::log('Autopix webhook received', ['payload' => $payload]);

        // Formato real do BB: { "locrec": [ {...} ] }
        if (isset($payload['locrec']) && is_array($payload['locrec'])) {
            $this->handleLocationRecurrence($payload['locrec']);
            return;
        }

        // Instruções de pagamento (pain.013)
        $instructionEvents = $this->extractInstructionEvents($payload);
        if (!empty($instructionEvents)) {
            $this->handleInstructionEvents($instructionEvents);
            return;
        }

        // Formato legado (manter compatibilidade)
        $type = $payload['type'] ?? '';
        $data = $payload['data'] ?? [];

        Logger::log('Autopix webhook event (legacy format)', [
            'type' => $type,
            'data' => $data
        ]);

        switch ($type) {
            case 'consent.approved':
                $consentId = $data['consentId'] ?? '';
                if ($consentId !== '') {
                    (new AutoPixConsentRepository())->markActiveByPspConsentId($consentId);
                }
                break;

            case 'consent.revoked':
                $consentId = $data['consentId'] ?? '';
                if ($consentId !== '') {
                    (new AutoPixConsentRepository())->markRevokedByPspConsentId($consentId);
                }
                break;

            case 'charge.succeeded':
                $allocationKey = ($data['id'] ?? '') . '|' . ($data['endToEndId'] ?? '');
                $alreadyAllocated = $_SESSION['autopix_allocations'][$allocationKey] ?? false;
                if ($alreadyAllocated) {
                    Logger::log('Autopix charge.succeeded duplicate allocation', $data);
                    break;
                }

                $allocate = new AllocatePaymentService();
                $allocate->run([
                    'metadata' => $data['metadata'] ?? [],
                    'paidAmount' => (float) ($data['valor']['original'] ?? $data['paidAmount'] ?? 0),
                    'endToEndId' => (string) ($data['endToEndId'] ?? ''),
                    'chargeId' => (string) ($data['id'] ?? '')
                ]);
                $_SESSION['autopix_allocations'][$allocationKey] = true;
                break;

            case 'charge.failed':
            case 'charge.expired':
                Logger::log('Autopix charge not completed', $data);
                break;

            case 'refund.succeeded':
                Logger::log('Autopix refund succeeded', $data);
                break;

            default:
                Logger::log('Autopix webhook unknown event', $payload);
                break;
        }
    }

    /**
     * Processa webhook de Location de Recorrência (Jornada 2)
     * Payload: { "locrec": [ { "id": 12345, "location": "...", "status": "ATIVA", "idRec": "..." } ] }
     */
    private function handleLocationRecurrence(array $locations): void
    {
        $repo = new AutoPixConsentRepository();

        foreach ($locations as $location) {
            $locationId = (int)($location['id'] ?? 0);
            $locationUri = (string)($location['location'] ?? '');
            $status = strtoupper((string)($location['status'] ?? ''));
            $idRec = (string)($location['idRec'] ?? '');
            $idRecTipo = strlen($idRec) > 0 ? strtoupper($idRec[1]) : null; // RN ou RR

            if ($locationId <= 0) {
                Logger::log('Autopix webhook: location sem ID', ['location' => $location]);
                continue;
            }

            Logger::log('Autopix webhook: processar location', [
                'locationId' => $locationId,
                'locationUri' => $locationUri,
                'status' => $status,
                'idRec' => $idRec
            ]);

            // Buscar consent pelo psp_consent_id (que é o ID da location)
            $consent = $repo->findByPspConsentId((string)$locationId);

            if (!$consent) {
                Logger::log('Autopix webhook: consent não encontrado', ['locationId' => $locationId]);
                continue;
            }

            // Processar status
            switch ($status) {
                case 'ATIVA':
                    $repo->markActiveByPspConsentId((string)$locationId);
                    if ($idRec !== '') {
                        $repo->updateIdRecByPspConsentId((string)$locationId, $idRec, $idRecTipo);
                    }
                    Logger::log('Autopix webhook: location ativada', [
                        'locationId' => $locationId,
                        'idRec' => $idRec,
                        'consentDbId' => $consent['id']
                    ]);
                    break;

                case 'INATIVA':
                case 'CANCELADA':
                case 'REMOVIDA':
                    $repo->markRevokedByPspConsentId((string)$locationId);
                    Logger::log('Autopix webhook: location desativada', [
                        'locationId' => $locationId,
                        'status' => $status,
                        'consentDbId' => $consent['id']
                    ]);
                    break;

                case 'CRIADA':
                    // Location criada mas ainda não ativa (aguardando aprovação)
                    Logger::log('Autopix webhook: location criada (pending)', [
                        'locationId' => $locationId,
                        'consentDbId' => $consent['id']
                    ]);
                    break;

                default:
                    Logger::log('Autopix webhook: status desconhecido', [
                        'locationId' => $locationId,
                        'status' => $status
                    ]);
                    break;
            }
        }
    }

    /**
     * Extração genérica dos eventos de instrução de pagamento
     */
    private function extractInstructionEvents(array $payload): array
    {
        $candidates = [
            'instruction',
            'instructions',
            'instrucao',
            'instrucoes',
            'instrucaoPagamento',
            'instrucao_pagamento',
            'instrucaoPagamentoArray',
            'instrucao_pagamento_array',
            'pagamento',
            'pagamentos',
            'charge',
            'charges'
        ];

        foreach ($candidates as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = $payload[$key];

            if (is_array($value) && isset($value[0])) {
                return $value;
            }

            if (is_array($value)) {
                return [$value];
            }
        }

        return [];
    }

    private function handleInstructionEvents(array $events): void
    {
        $repo = new AutoPixInstructionRepository();
        $fallbackService = new ApplyInvoiceFallbackService();

        foreach ($events as $event) {
            $idFimAFim = $this->extractInstructionId($event);
            if ($idFimAFim === '') {
                Logger::log('AutoPix webhook: instrução sem idFimAFim', ['event' => $event]);
                continue;
            }

            $statusRaw = strtoupper((string)($event['status'] ?? $event['statusInstrucao'] ?? $event['situacao'] ?? ''));
            if ($statusRaw === '') {
                Logger::log('AutoPix webhook: instrução sem status', ['event' => $event]);
                continue;
            }

        $instruction = $repo->findByIdFimAFim($idFimAFim);
        if (!$instruction) {
                Logger::log('AutoPix webhook: instrução não encontrada', [
                    'idFimAFim' => $idFimAFim,
                    'event' => $event
                ]);
                continue;
            }

            $mapped = $this->mapInstructionStatus($statusRaw);
            if ($mapped === null) {
                Logger::log('AutoPix webhook: status de instrução desconhecido', [
                    'statusRaw' => $statusRaw,
                    'event' => $event
                ]);
                continue;
            }

        if ($instruction['status'] === 'liquidated' && $mapped !== 'liquidated') {
            Logger::log('AutoPix webhook: instrução já liquidada, ignorando atualização', [
                'idFimAFim' => $idFimAFim,
                'status' => $instruction['status'],
                'receivedStatus' => $mapped
            ]);
            continue;
        }

            $extra = [
                'api_response' => json_encode([
                    'event' => $event,
                    'received_at' => date('c')
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ];

            switch ($mapped) {
                case 'scheduled':
                    $repo->updateStatusByIdFimAFim($idFimAFim, 'scheduled', $extra);
                    Logger::log('AutoPix webhook: instrução agendada', [
                        'idFimAFim' => $idFimAFim,
                        'invoiceId' => $instruction['invoice_id']
                    ]);
                    break;

                case 'liquidated':
                    $repo->markLiquidatedByIdFimAFim($idFimAFim, $extra);
                    $this->applyInstructionPayment($instruction, $event);
                    break;

                case 'failed':
                    $repo->updateStatusByIdFimAFim($idFimAFim, 'failed', $extra);
                    Logger::log('AutoPix webhook: instrução não liquidada', [
                        'idFimAFim' => $idFimAFim,
                        'invoiceId' => $instruction['invoice_id'],
                        'statusRaw' => $statusRaw
                    ]);
                    $this->triggerFallbackIfNeeded($repo, $fallbackService, $instruction);
                    break;

                case 'cancelled':
                    $repo->updateStatusByIdFimAFim($idFimAFim, 'cancelled', $extra);
                    Logger::log('AutoPix webhook: instrução cancelada', [
                        'idFimAFim' => $idFimAFim,
                        'invoiceId' => $instruction['invoice_id']
                    ]);
                    $this->triggerFallbackIfNeeded($repo, $fallbackService, $instruction);
                    break;
            }
        }
    }

    private function triggerFallbackIfNeeded(
        AutoPixInstructionRepository $repo,
        ApplyInvoiceFallbackService $fallbackService,
        array $instruction
    ): void {
        $invoiceId = (int)$instruction['invoice_id'];
        if ($invoiceId <= 0) {
            return;
        }

        $pending = $repo->findPendingByInvoice($invoiceId);
        if ($pending) {
            return; // ainda existem tentativas pendentes/agendadas
        }

        $lastFailed = $repo->findLastByStatus($invoiceId, ['failed', 'cancelled']);
        if (!$lastFailed || (int)$lastFailed['id'] !== (int)$instruction['id']) {
            return; // apenas a última tentativa falha/cancelada deve disparar
        }

        $clientId = (int)($instruction['client_id'] ?? 0);
        if ($clientId === 0) {
            $clientId = $this->resolveInvoiceClientId($invoiceId);
        }

        $fallbackService->run([
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            'reason' => 'webhook',
            'source' => 'webhook',
        ]);
    }

    private function resolveInvoiceClientId(int $invoiceId): int
    {
        try {
            $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
                ->select('userid')
                ->where('id', $invoiceId)
                ->first();

            return $invoice ? (int) $invoice->userid : 0;
        } catch (\Exception $e) {
            Logger::log('AutoPix webhook: erro ao resolver cliente da fatura', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function extractInstructionId(array $event): string
    {
        $candidates = [
            'idFimAFim',
            'id_fim_a_fim',
            'id',
            'instructionId',
            'instruction_id',
            'e2eid',
            'endToEndId'
        ];

        foreach ($candidates as $key) {
            if (!empty($event[$key])) {
                return (string) $event[$key];
            }
        }

        return '';
    }

    private function mapInstructionStatus(string $status): ?string
    {
        $status = strtoupper($status);

        $map = [
            'AGENDADA' => 'scheduled',
            'AGENDADO' => 'scheduled',
            'SCHEDULED' => 'scheduled',
            'EM_PROCESSAMENTO' => 'scheduled',
            'LIQUIDADA' => 'liquidated',
            'LIQUIDADO' => 'liquidated',
            'PAGA' => 'liquidated',
            'PAID' => 'liquidated',
            'CONCLUIDA' => 'liquidated',
            'NAO_LIQUIDADA' => 'failed',
            'NAO_REALIZADA' => 'failed',
            'NAOREALIZADA' => 'failed',
            'FAILED' => 'failed',
            'FALHA' => 'failed',
            'FALHOU' => 'failed',
            'EXPIRADA' => 'failed',
            'CANCELADA' => 'cancelled',
            'CANCELADO' => 'cancelled',
            'CANCELLED' => 'cancelled',
        ];

        return $map[$status] ?? null;
    }

    private function applyInstructionPayment(array $instruction, array $event): void
    {
        $invoiceId = (int) $instruction['invoice_id'];
        if ($invoiceId <= 0) {
            return;
        }

        $transId = 'AUTOPIX:' . $instruction['id_fim_a_fim'];

        $transactionExists = Capsule::table('tblaccounts')->where('transid', $transId)->exists();
        if ($transactionExists) {
            Logger::log('AutoPix webhook: pagamento já registrado', [
                'invoiceId' => $invoiceId,
                'transId' => $transId
            ]);
            return;
        }

        $amount = $this->extractLiquidatedAmount($event, $instruction);
        if ($amount <= 0) {
            Logger::log('AutoPix webhook: valor de liquidação inválido', [
                'invoiceId' => $invoiceId,
                'event' => $event
            ]);
            return;
        }

        $result = localAPI('AddInvoicePayment', [
            'invoiceid' => $invoiceId,
            'transid' => $transId,
            'gateway' => 'lknbbpix_auto',
            'amount' => number_format($amount, 2, '.', ''),
            'date' => date('Y-m-d')
        ]);

        Logger::log('AutoPix webhook: liquidação aplicada à fatura', [
            'invoiceId' => $invoiceId,
            'transId' => $transId,
            'amount' => $amount,
            'result' => $result
        ]);
    }

    private function extractLiquidatedAmount(array $event, array $instruction): float
    {
        $candidates = [
            $event['valorLiquidado'] ?? null,
            $event['valor']['liquidado'] ?? null,
            $event['valor']['original'] ?? null,
            $event['valor'] ?? null,
            $instruction['amount'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null) {
                continue;
            }

            $amount = (float) str_replace(',', '.', (string) $value);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }
}
