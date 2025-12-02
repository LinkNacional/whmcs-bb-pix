<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use DateInterval;
use DateTime;
use Exception;
use Lkn\BBPix\App\Pix\Repositories\AutoPixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixInstructionRepository;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Invoice as WHMCSInvoice;

final class CreatePaymentInstructionService
{
    private AutoPixApiRepository $api;
    private AutoPixInstructionRepository $instructions;
    private AutoPixConsentRepository $consents;

    public function __construct()
    {
        $this->api = new AutoPixApiRepository();
        $this->instructions = new AutoPixInstructionRepository();
        $this->consents = new AutoPixConsentRepository();
    }

    /**
     * Cria instrução de pagamento (pain.013) para PCI Pix Automático.
     *
     * @param array $data
     * @return array
     */
    public function run(array $data): array
    {
        try {
            $invoiceId = (int)($data['invoice_id'] ?? 0);
            $attemptNumber = (int)($data['attempt_number'] ?? 1);
            $scheduledDate = $data['scheduled_date'] ?? null; // YYYY-MM-DD
            $finalidade = $attemptNumber === 1 ? 'AGND' : 'NTAG';
            $offset = (int)($data['offset'] ?? 0);

            if ($invoiceId <= 0) {
                throw new Exception('Fatura inválida');
            }

            $invoice = WHMCSInvoice::find($invoiceId);
            if (!$invoice) {
                throw new Exception('Fatura não encontrada');
            }

            if ($invoice->status === 'Paid') {
                throw new Exception('Fatura já está paga');
            }

            $invoiceData = $invoice->toArray();
            $clientId = (int)$invoiceData['userid'];

            // Buscar consent ativo
            $consent = $this->findActiveConsentForInvoice($invoiceData);
            if (!$consent) {
                throw new Exception('Consentimento PIX Automático não encontrado ou inativo');
            }

            $idRec = $consent['id_rec'] ?? '';
            if ($idRec === '') {
                throw new Exception('Consentimento sem idRec - impossível gerar instrução');
            }

            // Verificar instrução pendente
            $existingPending = $this->instructions->findPendingByInvoice($invoiceId);
            if ($existingPending) {
                throw new Exception('Já existe instrução pendente/agendada para esta fatura');
            }

            $existingInstructions = $this->instructions->findByInvoiceId($invoiceId);
            if (count($existingInstructions) >= 3) {
                throw new Exception('Número máximo de tentativas atingido para esta fatura');
            }

            $invoiceDueDate = new DateTime($invoiceData['duedate']);
            $today = new DateTime('today');

            if ($offset > 0) {
                throw new Exception('Offset inválido: instruções pós-vencimento não são suportadas');
            }

            // Determinar data de liquidação planejada
            $scheduledDateObj = $scheduledDate ? new DateTime($scheduledDate) : clone $invoiceDueDate;

            if ($scheduledDateObj > $invoiceDueDate) {
                throw new Exception('Data de liquidação não pode ultrapassar a data de vencimento');
            }

            // Validar janela de envio (2 a 10 dias corridos antes da data de liquidação)
            $diffDays = (int)$today->diff($scheduledDateObj)->format('%r%a');
            if ($diffDays < 2 || $diffDays > 10) {
                throw new Exception(sprintf(
                    'Janela de envio inválida para data %s. Hoje: %s. Diferença: %d dias',
                    $scheduledDateObj->format('Y-m-d'),
                    $today->format('Y-m-d'),
                    $diffDays
                ));
            }

            $currency = $invoice->currencyrel ?: 1;
            $amount = (float)$invoiceData['balance'];
            if ($amount <= 0.0) {
                throw new Exception('Valor pendente inválido para instrução');
            }

            $instructionPayload = $this->buildInstructionPayload([
                'invoice' => $invoiceData,
                'consent' => $consent,
                'attempt_number' => $attemptNumber,
                'finalidade' => $finalidade,
                'scheduled_date' => $scheduledDateObj->format('Y-m-d'),
                'amount' => $amount,
                'offset' => $offset,
            ]);

            Logger::log('AutoPix: enviando instrução de pagamento', [
                'invoiceId' => $invoiceId,
                'payload' => $instructionPayload,
                'attempt' => $attemptNumber
            ]);

            $response = $this->api->sendPaymentInstruction($instructionPayload);

            if (!isset($response['idFimAFim'])) {
                Logger::log('AutoPix: instrução sem idFimAFim', $response);
                throw new Exception('Resposta inválida ao criar instrução (idFimAFim ausente)');
            }

            $idFimAFim = $response['idFimAFim'];

            $this->instructions->insert([
                'invoice_id' => $invoiceId,
                'consent_id' => $consent['id'],
                'attempt_number' => $attemptNumber,
                'finalidade' => $finalidade,
                'scheduled_date' => $scheduledDateObj->format('Y-m-d'),
                'id_fim_a_fim' => $idFimAFim,
            'amount' => $amount,
            'status' => 'pending',
            'api_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ]);

            Logger::log('AutoPix: instrução criada com sucesso', [
                'invoiceId' => $invoiceId,
                'idFimAFim' => $idFimAFim,
                'attempt' => $attemptNumber
            ]);

            return [
                'success' => true,
                'idFimAFim' => $idFimAFim,
                'response' => $response,
                'invoice_id' => $invoiceId,
                'client_id' => $clientId,
                'attempt_number' => $attemptNumber,
                'scheduled_date' => $scheduledDateObj->format('Y-m-d'),
                'amount' => $amount,
                'offset' => $offset,
            ];
        } catch (Exception $e) {
            Logger::log('AutoPix: erro ao criar instrução', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function findActiveConsentForInvoice(array $invoice): ?array
    {
        $serviceId = (int)($invoice['serviceid'] ?? 0);
        $domainId = (int)($invoice['domainid'] ?? 0);

        $query = $this->consents->query();

        if ($serviceId > 0) {
            $query->where('serviceid', $serviceId);
        } elseif ($domainId > 0) {
            $query->where('domainid', $domainId);
        } else {
            return null;
        }

        $row = $query->where('status', 'active')->orderBy('id', 'desc')->first();

        return $row ? (array)$row : null;
    }

    private function buildInstructionPayload(array $context): array
    {
        $invoice = $context['invoice'];
        $consent = $context['consent'];
        $attempt = (int)$context['attempt_number'];
        $finalidade = $context['finalidade'];
        $scheduledDate = $context['scheduled_date'];
        $amount = number_format((float)$context['amount'], 2, '.', '');
        $offset = (int)($context['offset'] ?? 0);

        $idRec = $consent['id_rec'];
        $isRetryEnabled = strtoupper($consent['id_rec_tipo'] ?? 'RN') === 'RR';

        if ($attempt > 1 && !$isRetryEnabled) {
            throw new Exception('Recorrência não permite retentativas pós-vencimento (idRecTipo != RR)');
        }

        $idFimAFim = $this->generateIdFimAFim($invoice['id'], $attempt);

        return [
            'idFimAFim' => $idFimAFim,
            'valor' => [
                'original' => $amount
            ],
            'idRecorrencia' => $idRec,
            'finalidadeDoAgendamento' => $finalidade,
            'dataDeVencimento' => (new DateTime($invoice['duedate']))->format('Y-m-d'),
            'dataPrevistaLiquidacao' => $scheduledDate,
            'idConciliacaoRecebedor' => (string)$invoice['id'],
            'descricao' => sprintf('Fatura #%d - Tentativa %d', $invoice['id'], $attempt),
            'informacoesAdicionais' => [
                [
                    'nome' => 'faturaId',
                    'valor' => (string)$invoice['id']
                ],
                [
                    'nome' => 'tentativa',
                    'valor' => (string)$attempt
                ]
            ]
        ];
    }

    private function generateIdFimAFim(int $invoiceId, int $attempt): string
    {
        return sprintf('AUTOPIX-%d-%d-%s', $invoiceId, $attempt, bin2hex(random_bytes(6)));
    }
}

