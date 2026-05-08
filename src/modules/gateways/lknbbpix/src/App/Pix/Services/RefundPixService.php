<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\AbstractPixApiRepository;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\PixApiRepository;
use Lkn\BBPix\App\Pix\PixApiRepositoryLate;
use Lkn\BBPix\Helpers\Logger;
use Throwable;

final class RefundPixService
{
    private AbstractPixApiRepository $pixGateway;

    public function __construct(AbstractPixApiRepository $pixGateway)
    {
        $this->pixGateway = $pixGateway;
    }

    public function run(array $request): array
    {
        $transacId = (string) ($request['transacId'] ?? '');
        $invoiceId = (int) ($request['invoiceId'] ?? 0);
        $refundAmount = (string) ($request['refundAmount'] ?? '0');

        if (!$this->isValidWhmcsTransacId($transacId) || $invoiceId <= 0) {
            throw new PixException(PixExceptionCodes::COULD_NOT_REQUEST_PIX_REFUND);
        }

        try {
            $pixTxId = PixTaxId::fromWhmcsTransId($transacId, $invoiceId);
        } catch (Throwable) {
            throw new PixException(PixExceptionCodes::COULD_NOT_REQUEST_PIX_REFUND);
        }

        $pixE2eid = $this->extractEndToEndIdFromTransacId($transacId);
        $refundRepository = $this->pixGateway;

        if ($pixE2eid !== '') {
            Logger::log(
                'RefundPixService: refund_e2e_from_transid',
                ['invoiceId' => $invoiceId, 'transacId' => $transacId],
                ['path' => 'direct']
            );
        } else {
            Logger::log(
                'RefundPixService: refund_e2e_from_consult',
                ['invoiceId' => $invoiceId, 'transacId' => $transacId],
                ['path' => 'fallback_consult']
            );

            $resolvedConsult = $this->resolveRepositoryForConsult($pixTxId);
            $refundRepository = $resolvedConsult['repository'];
            $response = $resolvedConsult['response'];

            if (!is_array($response) || !isset($response['pix'][0]['endToEndId'])) {
                throw new PixException(PixExceptionCodes::COULD_NOT_CONSULT_PIX_BY_TXID);
            }

            $pixE2eid = strtoupper(trim((string) $response['pix'][0]['endToEndId']));
        }

        $refundResponse = $refundRepository->requestRefund(
            $pixE2eid,
            $refundAmount
        );

        if (!is_array($refundResponse) || !isset($refundResponse['status'], $refundResponse['rtrId'])) {
            throw new PixException(PixExceptionCodes::COULD_NOT_REQUEST_PIX_REFUND);
        }

        return [
            'status' => $refundResponse['status'],
            'reason' => 'outros',
            'refundTransId' => 'REEMBOLSOx' . $refundResponse['rtrId']
        ];
    }

    private function isValidWhmcsTransacId(string $transacId): bool
    {
        $parts = explode('x', $transacId, 3);

        return count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '';
    }

    private function extractEndToEndIdFromTransacId(string $transacId): string
    {
        $parts = explode('x', trim($transacId), 3);

        if (count($parts) < 3) {
            return '';
        }

        $endToEndId = strtoupper(trim((string) $parts[2]));

        if ($endToEndId === '') {
            return '';
        }

        if ($endToEndId[0] !== 'E') {
            return '';
        }

        return $endToEndId;
    }

    private function resolveRepositoryForConsult(PixTaxId $pixTxId): array
    {
        try {
            $response = $this->pixGateway->consultPix($pixTxId);

            return [
                'repository' => $this->pixGateway,
                'response' => $response
            ];
        } catch (PixException $e) {
            if ($e->exceptionCode !== PixExceptionCodes::COULD_NOT_CONSULT_PIX_BY_TXID) {
                throw $e;
            }

            $fallbackRepository = $this->pixGateway instanceof PixApiRepository
                ? new PixApiRepositoryLate()
                : new PixApiRepository();

            Logger::log(
                'RefundPixService: fallback de consulta cob/cobv',
                [
                    'primaryRepository' => $this->pixGateway instanceof PixApiRepository ? 'cob' : 'cobv'
                ],
                [
                    'fallbackRepository' => $fallbackRepository instanceof PixApiRepository ? 'cob' : 'cobv'
                ]
            );

            return [
                'repository' => $fallbackRepository,
                'response' => $fallbackRepository->consultPix($pixTxId)
            ];
        }
    }
}
