<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use RuntimeException;

final class LoadJourney4PixService
{
    private PixAutoRepository $repository;

    private AuthRepository $authRepository;

    public function __construct(?PixAutoRepository $repository = null, ?AuthRepository $authRepository = null)
    {
        $this->repository = $repository ?? new PixAutoRepository();
        $this->authRepository = $authRepository ?? new AuthRepository();
    }

    public function run(int $invoiceId, int $clientId, int $dueDay, string $txid, array $payerData): array
    {
        $cached = $this->authRepository->findCreatedByClientAndDueDay($clientId, $dueDay);

        if (($cached['success'] ?? false) && !empty($cached['data']['auth'])) {
            $cachedAuth = $cached['data']['auth'];
            $cachedEmv = trim((string) ($cachedAuth->emv_payload ?? ''));

            if ($cachedEmv !== '') {
                return ['success' => true, 'data' => ['idRec' => (string) $cachedAuth->id_rec, 'emv' => $cachedEmv, 'cached' => true]];
            }

            $qrResponse = $this->repository->obterQrCodeComposto((string) $cachedAuth->id_rec, $txid);
            $qrData = $this->extractSuccessData($qrResponse, 'obterQrCodeComposto-cache');
            $emv = $this->extractEmv($qrData);

            if ($emv === '') {
                throw new RuntimeException('EMV não retornado no cache-hit da recorrência.');
            }

            $this->authRepository->updateEmvPayload((string) $cachedAuth->id_rec, $emv);

            return ['success' => true, 'data' => ['idRec' => (string) $cachedAuth->id_rec, 'emv' => $emv, 'cached' => true]];
        }

        $amount = number_format(Invoice::getBalance($invoiceId), 2, '.', '');
        $dueDate = Invoice::getDueDate($invoiceId);

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

        $recPayload = [
            'calendario' => [
                'expiracao' => Config::setting('pix_expiration') * 86400,
            ],
            'valor' => [
                'original' => $amount,
            ],
            'chave' => Config::setting('receiver_pix_key'),
            'location' => $locationId,
        ];

        if ($pixDescription !== '') {
            $recPayload['solicitacaoPagador'] = $pixDescription;
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

        $saveResponse = $this->authRepository->salvarCriada($clientId, $idRec, $dueDay, 'MENSAL', $emv);

        if (!($saveResponse['success'] ?? false)) {
            throw new RuntimeException('Falha ao salvar idRec da jornada 4.');
        }

        return ['success' => true, 'data' => ['idRec' => $idRec, 'emv' => $emv, 'cached' => false]];
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
        foreach (['pixCopiaECola', 'emv', 'qrCode', 'pixCopiaCola'] as $key) {
            if (!empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }
}
