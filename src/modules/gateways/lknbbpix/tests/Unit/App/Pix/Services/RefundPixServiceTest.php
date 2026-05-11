<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\AbstractPixApiRepository;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\Services\RefundPixService;
use PHPUnit\Framework\TestCase;

final class RefundPixServiceTest extends TestCase
{
    private const TXID = 'LKN0000065552EBF9CC24C6DC9';
    private const E2EID_CANONICAL = 'E18236120202605111625s085d096fe7';
    private const E2EID_UPPER = 'E18236120202605111625S085D096FE7';

    public function testRunPreservesMixedCaseE2eidFromTransactionId(): void
    {
        $repository = new class () extends AbstractPixApiRepository {
            public array $precheckCalls = [];
            public array $refundCalls = [];

            public function __construct()
            {
            }

            public function consultPix(PixTaxId $taxId): array|bool|null
            {
                return [];
            }

            public function requestRefund(string $e2eid, string $refundValue): array|bool|null
            {
                $this->refundCalls[] = ['e2eid' => $e2eid, 'refundValue' => $refundValue];

                return ['status' => 'EM_PROCESSAMENTO', 'rtrId' => 'RTR123'];
            }

            public function consultPixByEndToEndId(string $e2eid): array
            {
                $this->precheckCalls[] = $e2eid;

                return ['endToEndId' => $e2eid];
            }
        };

        $service = new RefundPixService($repository);

        $response = $service->run([
            'transacId' => 'PAGOx' . self::TXID . 'x' . self::E2EID_CANONICAL,
            'refundAmount' => '1.20',
            'invoiceId' => 65552,
        ]);

        self::assertSame([self::E2EID_CANONICAL], $repository->precheckCalls);
        self::assertSame(self::E2EID_CANONICAL, $repository->refundCalls[0]['e2eid']);
        self::assertSame('1.20', $repository->refundCalls[0]['refundValue']);
        self::assertSame('REEMBOLSOxRTR123', $response['refundTransId']);
    }

    public function testRunRecoversCanonicalE2eidFromTxidWhenLegacyTransactionIdIsUppercased(): void
    {
        $repository = new class () extends AbstractPixApiRepository {
            public array $precheckCalls = [];
            public array $refundCalls = [];

            public function __construct()
            {
            }

            public function consultPix(PixTaxId $taxId): array|bool|null
            {
                return [
                    'pix' => [
                        [
                            'txid' => RefundPixServiceTest::TXID,
                            'endToEndId' => RefundPixServiceTest::E2EID_CANONICAL,
                        ],
                    ],
                ];
            }

            public function requestRefund(string $e2eid, string $refundValue): array|bool|null
            {
                $this->refundCalls[] = ['e2eid' => $e2eid, 'refundValue' => $refundValue];

                return ['status' => 'DEVOLVIDO', 'rtrId' => 'RTR456'];
            }

            public function consultPixByEndToEndId(string $e2eid): array
            {
                $this->precheckCalls[] = $e2eid;

                if ($e2eid === RefundPixServiceTest::E2EID_UPPER) {
                    throw new PixException(PixExceptionCodes::PIX_INELIGIBLE_FOR_REFUND);
                }

                return ['endToEndId' => $e2eid];
            }
        };

        $service = new RefundPixService($repository);

        $response = $service->run([
            'transacId' => 'PAGOx' . self::TXID . 'x' . self::E2EID_UPPER,
            'refundAmount' => '1.20',
            'invoiceId' => 65552,
        ]);

        self::assertSame([self::E2EID_UPPER, self::E2EID_CANONICAL], $repository->precheckCalls);
        self::assertSame(self::E2EID_CANONICAL, $repository->refundCalls[0]['e2eid']);
        self::assertSame('REEMBOLSOxRTR456', $response['refundTransId']);
    }
}
