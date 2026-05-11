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
        $refundAmount = $this->normalizeRefundAmount((string) ($request['refundAmount'] ?? '0'));

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
        $resolvedConsult = null;

        if ($pixE2eid !== '') {
            Logger::log(
                'RefundPixService: refund_e2e_from_transid',
                ['invoiceId' => $invoiceId, 'transacId' => $transacId],
                ['path' => 'direct', 'refundAmount' => $refundAmount]
            );

            try {
                $this->validateRefundTarget(
                    $this->pixGateway,
                    $pixE2eid,
                    'RefundPixService: pix_precheck_success',
                    'RefundPixService: pix_precheck_failed'
                );
            } catch (PixException $e) {
                if ($e->exceptionCode !== PixExceptionCodes::PIX_INELIGIBLE_FOR_REFUND) {
                    throw $e;
                }

                $recoveredRefundTarget = $this->recoverRefundTargetFromTxid($pixTxId, $pixE2eid);

                if ($recoveredRefundTarget === null) {
                    throw $e;
                }

                $refundRepository = $recoveredRefundTarget['repository'];
                $resolvedConsult = $recoveredRefundTarget['response'];
                $pixE2eid = $recoveredRefundTarget['e2eid'];

                $this->validateRefundTarget(
                    $refundRepository,
                    $pixE2eid,
                    'RefundPixService: pix_precheck_success_recovered',
                    'RefundPixService: pix_precheck_failed_recovered'
                );
            }
        } else {
            Logger::log(
                'RefundPixService: refund_e2e_from_consult',
                ['invoiceId' => $invoiceId, 'transacId' => $transacId],
                ['path' => 'fallback_consult', 'refundAmount' => $refundAmount]
            );

            $resolvedConsult = $this->resolveRepositoryForConsult($pixTxId);
            $refundRepository = $resolvedConsult['repository'];
            $response = $resolvedConsult['response'];

            $pixE2eid = $this->extractEndToEndIdFromConsultResponse($response, $pixTxId);

            if ($pixE2eid === '') {
                throw new PixException(PixExceptionCodes::COULD_NOT_CONSULT_PIX_BY_TXID);
            }

            $this->validateRefundTarget(
                $refundRepository,
                $pixE2eid,
                'RefundPixService: pix_precheck_success_fallback',
                'RefundPixService: pix_precheck_failed_fallback'
            );
        }

        try {
            $refundResponse = $refundRepository->requestRefund(
                $pixE2eid,
                $refundAmount
            );
        } catch (PixException $e) {
            Logger::log(
                'RefundPixService: refund_request_failed',
                ['e2eid' => $pixE2eid, 'amount' => $refundAmount],
                ['error' => $e->exceptionCode->label()]
            );
            throw $e;
        }

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

        $endToEndId = trim((string) $parts[2]);

        if ($endToEndId === '') {
            return '';
        }

        if (strtoupper($endToEndId[0]) !== 'E') {
            return '';
        }

        return $endToEndId;
    }

    private function normalizeRefundAmount(string $refundAmount): string
    {
        $normalized = str_replace(',', '.', trim($refundAmount));

        if (!is_numeric($normalized) || (float) $normalized <= 0) {
            throw new PixException(PixExceptionCodes::COULD_NOT_REQUEST_PIX_REFUND);
        }

        return number_format((float) $normalized, 2, '.', '');
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

    private function validateRefundTarget(
        AbstractPixApiRepository $repository,
        string $pixE2eid,
        string $successLog,
        string $failureLog
    ): void {
        try {
            $repository->consultPixByEndToEndId($pixE2eid);

            Logger::log(
                $successLog,
                ['e2eid' => $pixE2eid],
                ['eligible' => true]
            );
        } catch (PixException $e) {
            if ($e->exceptionCode === PixExceptionCodes::PIX_INELIGIBLE_FOR_REFUND) {
                Logger::log(
                    $failureLog,
                    ['e2eid' => $pixE2eid],
                    ['reason' => 'not_found_or_ineligible']
                );
            }

            throw $e;
        }
    }

    private function recoverRefundTargetFromTxid(PixTaxId $pixTxId, string $invalidE2eid): ?array
    {
        $resolvedConsult = $this->resolveRepositoryForConsult($pixTxId);
        $canonicalE2eid = $this->extractEndToEndIdFromConsultResponse($resolvedConsult['response'], $pixTxId);

        if ($canonicalE2eid === '') {
            Logger::log(
                'RefundPixService: refund_e2eid_legacy_not_recoverable',
                ['txid' => $pixTxId->getApiTransId(), 'invalidE2eid' => $invalidE2eid],
                ['reason' => 'consult_response_missing_matching_endToEndId']
            );

            return null;
        }

        Logger::log(
            'RefundPixService: refund_e2eid_legacy_recovered_from_txid',
            ['txid' => $pixTxId->getApiTransId(), 'invalidE2eid' => $invalidE2eid],
            ['canonicalE2eid' => $canonicalE2eid]
        );

        return [
            'repository' => $resolvedConsult['repository'],
            'response' => $resolvedConsult['response'],
            'e2eid' => $canonicalE2eid,
        ];
    }

    private function extractEndToEndIdFromConsultResponse(array|bool|null $response, PixTaxId $pixTxId): string
    {
        if (!is_array($response) || !isset($response['pix']) || !is_array($response['pix'])) {
            return '';
        }

        $expectedTxid = $pixTxId->getApiTransId();

        foreach ($response['pix'] as $pixTransaction) {
            if (!is_array($pixTransaction)) {
                continue;
            }

            if (($pixTransaction['txid'] ?? null) !== $expectedTxid) {
                continue;
            }

            return trim((string) ($pixTransaction['endToEndId'] ?? ''));
        }

        if (count($response['pix']) === 1 && is_array($response['pix'][0] ?? null)) {
            return trim((string) (($response['pix'][0]['endToEndId'] ?? '')));
        }

        return '';
    }
}
