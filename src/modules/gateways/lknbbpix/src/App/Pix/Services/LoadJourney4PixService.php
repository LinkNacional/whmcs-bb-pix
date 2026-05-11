<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Validator;
use RuntimeException;

final class LoadJourney4PixService
{
    private PixAutoRepository $repository;

    private AuthRepository $authRepository;

    private EnsureCreatedTransactionService $ensureCreatedTransactionService;

    public function __construct(?PixAutoRepository $repository = null, ?AuthRepository $authRepository = null)
    {
        $this->repository = $repository ?? new PixAutoRepository();
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->ensureCreatedTransactionService = new EnsureCreatedTransactionService();
    }

    public function run(int $invoiceId, int $clientId, int $dueDay, string $txid, array $payerData): array
    {
        $amount = $this->normalizeAmount(Invoice::getBalance($invoiceId));
        $dueDate = $this->normalizeDueDate(Invoice::getDueDate($invoiceId));

        if ($dueDate !== '' && $this->isDateBeforeToday($dueDate)) {
            throw new RuntimeException('Fatura vencida não pode seguir na Jornada 4. Utilize o fluxo manual.');
        }

        $cached = $this->authRepository->findCreatedByClientAndDueDay($clientId, $dueDay);

        if (($cached['success'] ?? false) && !empty($cached['data']['auth'])) {
            $cachedAuth = $cached['data']['auth'];
            $cachedEmv = trim((string) ($cachedAuth->emv_payload ?? ''));
            $cachedAmountSnapshot = $this->normalizeAmount((float) ($cachedAuth->emv_amount_snapshot ?? 0.0));
            $cachedDueDateSnapshot = $this->normalizeDueDate((string) ($cachedAuth->emv_due_date_snapshot ?? ''));

            if ($cachedEmv !== '' && $this->isCachedSnapshotValid($cachedAmountSnapshot, $cachedDueDateSnapshot, $amount, $dueDate)) {
                return ['success' => true, 'data' => ['idRec' => (string) $cachedAuth->id_rec, 'emv' => $cachedEmv, 'cached' => true]];
            }

            // Cache miss: valor ou data mudaram - atualizar dinamicamente via PATCH
            $patchPayload = [
                'valor' => ['original' => $amount],
                'calendario' => [
                    'dataDeVencimento' => $dueDate,
                    'validadeAposVencimento' => (string) Config::setting('fine_days')
                ]
            ];

            $this->extractSuccessData(
                $this->repository->patchCobv($txid, $patchPayload),
                'patchCobv-cache'
            );

            // EMV não muda (é um URL que aponta para a cobrança atualizada no BB)
            $emv = $cachedEmv;

            // Atualizar snapshot na tabela para auditoria
            $this->authRepository->updateEmvPayload(
                (string) $cachedAuth->id_rec,
                $emv,
                $amount,
                $dueDate,
                true  // incrementVersion
            );

            return ['success' => true, 'data' => ['idRec' => (string) $cachedAuth->id_rec, 'emv' => $emv, 'cached' => false]];
        }

        $cobvPayload = [
            'calendario' => [
                'dataDeVencimento' => $dueDate,
                'validadeAposVencimento' => (string) Config::setting('fine_days'),
            ],
            'valor' => [
                'original' => $amount,
            ],
            'chave' => Config::setting('receiver_pix_key'),
            'devedor' => [
                $payerData['payerDocType'] => $payerData['payerDocValue'],
                'nome' => $payerData['clientFullName'],
            ],
        ];

        $pixDescription = Config::setting('pix_descrip');

        if ($pixDescription !== '') {
            $cobvPayload['solicitacaoPagador'] = $pixDescription;
        }

        $this->extractSuccessData($this->repository->criarCobV($txid, $cobvPayload), 'criarCobV');

        $locationData = $this->extractSuccessData($this->repository->criarLocationRecorrencia(), 'criarLocationRecorrencia');
        $locationId = $this->extractLocationId($locationData);

        if ($locationId === '') {
            throw new RuntimeException('ID da location não retornado pela API do BB.');
        }

        if (!isset($payerData['clientFullName'], $payerData['payerDocType'], $payerData['payerDocValue'])) {
            throw new RuntimeException('Dados do pagador incompletos para criar recorrência.');
        }

        $payerDocType = strtolower(trim((string) $payerData['payerDocType']));
        $payerDocValue = preg_replace('/\D/', '', (string) $payerData['payerDocValue']);
        $clientFullName = trim((string) $payerData['clientFullName']);
        $convenioConfigurado = trim((string) Config::setting('convenio'));
        $recurrenceObjectName = trim((string) Config::setting('recurrence_object_name'));

        if (!in_array($payerDocType, ['cpf', 'cnpj'], true)) {
            throw new RuntimeException('Tipo de documento do pagador inválido para recorrência.');
        }

        if ($payerDocValue === '') {
            throw new RuntimeException('Documento do pagador não informado para recorrência.');
        }

        if ($payerDocType === 'cpf' && !Validator::cpf($payerDocValue)) {
            throw new RuntimeException('CPF do pagador inválido para recorrência.');
        }

        if ($payerDocType === 'cnpj' && !Validator::cnpj($payerDocValue)) {
            throw new RuntimeException('CNPJ do pagador inválido para recorrência.');
        }

        if ($clientFullName === '') {
            throw new RuntimeException('Nome do pagador não informado para recorrência.');
        }

        if ($recurrenceObjectName === '') {
            $recurrenceObjectName = 'Fatura WHMCS';
        }

        $recurrenceObjectName = function_exists('mb_substr')
            ? mb_substr($recurrenceObjectName, 0, 140)
            : substr($recurrenceObjectName, 0, 140);

        $dataInicial = date('Y-m-d', strtotime($dueDate));

        $recPayload = [
            'vinculo' => [
                'objeto' => $recurrenceObjectName,
                'contrato' => (string) $invoiceId,
                'devedor' => [
                    'nome' => function_exists('mb_substr') ? mb_substr($clientFullName, 0, 140) : substr($clientFullName, 0, 140),
                    $payerDocType => $payerDocValue,
                ],
            ],
            'calendario' => [
                'dataInicial'   => $dataInicial,
                'periodicidade' => 'MENSAL',
            ],
            'valor' => [
                'valorRec' => number_format((float) $amount, 2, '.', ''),
            ],
            'loc' => (int) $locationId,
            'politicaRetentativa' => 'NAO_PERMITE',
        ];

        if ($convenioConfigurado !== '') {
            $recPayload['recebedor'] = [
                'convenio' => $convenioConfigurado,
            ];
        }

        $recData = $this->extractSuccessData($this->repository->criarRecorrencia($recPayload), 'criarRecorrencia');
        $idRec = $this->extractIdRec($recData);

        if ($idRec === '') {
            throw new RuntimeException('idRec não retornado na criação da recorrência.');
        }

        $qrData = $this->extractSuccessData($this->repository->obterQrCodeComposto($idRec, $txid), 'obterQrCodeComposto');
        $emv = $this->extractEmv($qrData);

        if ($emv === '') {
            throw new RuntimeException('EMV não retornado na jornada 4.');
        }

        $saveResponse = $this->authRepository->salvarCriada(
            $clientId,
            $idRec,
            $dueDay,
            'MENSAL',
            $emv,
            $amount,
            $dueDate
        );

        if (!($saveResponse['success'] ?? false)) {
            throw new RuntimeException('Falha ao salvar idRec da jornada 4.');
        }

        $this->ensureCreatedTransactionService->run($invoiceId, 'CRIADOx' . strtoupper(trim($txid)), 'Pix Jornada 4 gerado');

        return ['success' => true, 'data' => ['idRec' => $idRec, 'emv' => $emv, 'cached' => false]];
    }

    private function normalizeAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizeDueDate(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    private function isDateBeforeToday(string $date): bool
    {
        $dateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        $todayObj = new \DateTimeImmutable('today');

        if ($dateObj === false) {
            return false;
        }

        return $dateObj < $todayObj;
    }

    private function isCachedSnapshotValid(
        string $cachedAmount,
        string $cachedDueDate,
        string $currentAmount,
        string $currentDueDate
    ): bool {
        if ($cachedAmount === '' || $cachedDueDate === '') {
            return false;
        }

        return $cachedAmount === $currentAmount && $cachedDueDate === $currentDueDate;
    }

    private function extractSuccessData(array|string $response, string $step): array
    {
        if (!is_array($response) || !($response['success'] ?? false)) {
            throw new RuntimeException("Falha na etapa {$step}.");
        }

        return (array) ($response['data'] ?? []);
    }

    private function extractLocationId(array $data): string
    {
        foreach (['id', 'loc', 'location', 'locationId'] as $key) {
            if (!empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    private function extractIdRec(array $data): string
    {
        foreach (['idRec', 'id_rec', 'id'] as $key) {
            if (!empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    private function extractEmv(array $data): string
    {
        // Procura no nível raiz primeiro
        foreach (['pixCopiaECola', 'emv', 'qrCode', 'pixCopiaCola'] as $key) {
            if (!empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        // Se não encontrou, procura dentro de dadosQR (resposta do BB para Jornada 4)
        if (isset($data['dadosQR']) && is_array($data['dadosQR'])) {
            foreach (['pixCopiaECola', 'emv', 'qrCode', 'pixCopiaCola'] as $key) {
                if (!empty($data['dadosQR'][$key])) {
                    return (string) $data['dadosQR'][$key];
                }
            }
        }

        return '';
    }
}
