<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Services\LoadJourney4PixService;
use PHPUnit\Framework\TestCase;

final class LoadJourney4PixServiceTest extends TestCase
{
    public function testRunReturnsCachedEmvWhenCreatedAuthorizationAlreadyHasPayload(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->expects(self::once())
            ->method('findCreatedByClientAndDueDay')
            ->with(100, 15)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => (object) [
                        'id_rec' => 'REC_001',
                        'emv_payload' => 'EMV_CACHED_001',
                    ]
                ]
            ]);

        $pixRepo->expects(self::never())->method('obterQrCodeComposto');

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $response = $service->run(999, 100, 15, 'LKN0000000999ABCDE1234567');

        self::assertTrue($response['success']);
        self::assertSame('REC_001', $response['data']['idRec']);
        self::assertSame('EMV_CACHED_001', $response['data']['emv']);
        self::assertTrue($response['data']['cached']);
    }

    public function testRunLoadsAndPersistsEmvWhenCacheHasIdRecWithoutPayload(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->expects(self::once())
            ->method('findCreatedByClientAndDueDay')
            ->with(101, 20)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => (object) [
                        'id_rec' => 'REC_002',
                        'emv_payload' => null,
                    ]
                ]
            ]);

        $pixRepo->expects(self::once())
            ->method('obterQrCodeComposto')
            ->with('REC_002', 'LKN0000001000ABCDE1234567')
            ->willReturn([
                'success' => true,
                'data' => ['pixCopiaECola' => 'EMV_FETCHED_002']
            ]);

        $authRepo->expects(self::once())
            ->method('updateEmvPayload')
            ->with('REC_002', 'EMV_FETCHED_002')
            ->willReturn(['success' => true]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $response = $service->run(1000, 101, 20, 'LKN0000001000ABCDE1234567');

        self::assertTrue($response['success']);
        self::assertSame('REC_002', $response['data']['idRec']);
        self::assertSame('EMV_FETCHED_002', $response['data']['emv']);
        self::assertTrue($response['data']['cached']);
    }

    public function testRunCreatesJourneyAndPersistsIdRecAndEmvOnCacheMiss(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->expects(self::once())
            ->method('findCreatedByClientAndDueDay')
            ->with(102, 5)
            ->willReturn([
                'success' => true,
                'data' => ['auth' => null]
            ]);

        $pixRepo->expects(self::once())
            ->method('criarCobV')
            ->with('LKN0000002000ABCDE1234567', self::isType('array'))
            ->willReturn(['success' => true, 'data' => ['ok' => true]]);

        $pixRepo->expects(self::once())
            ->method('criarLocationRecorrencia')
            ->willReturn(['success' => true, 'data' => ['location' => 'LOC_123']]);

        $pixRepo->expects(self::once())
            ->method('criarRecorrencia')
            ->with(self::callback(function (array $payload): bool {
                return isset($payload['location']) && $payload['location'] === 'LOC_123';
            }))
            ->willReturn(['success' => true, 'data' => ['idRec' => 'REC_NEW_003']]);

        $pixRepo->expects(self::once())
            ->method('obterQrCodeComposto')
            ->with('REC_NEW_003', 'LKN0000002000ABCDE1234567')
            ->willReturn(['success' => true, 'data' => ['pixCopiaECola' => 'EMV_NEW_003']]);

        $authRepo->expects(self::once())
            ->method('salvarCriada')
            ->with(102, 'REC_NEW_003', 5, 'MENSAL', 'EMV_NEW_003')
            ->willReturn(['success' => true, 'data' => ['id' => 1]]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $response = $service->run(2000, 102, 5, 'LKN0000002000ABCDE1234567');

        self::assertTrue($response['success']);
        self::assertSame('REC_NEW_003', $response['data']['idRec']);
        self::assertSame('EMV_NEW_003', $response['data']['emv']);
        self::assertFalse($response['data']['cached']);
    }
}
