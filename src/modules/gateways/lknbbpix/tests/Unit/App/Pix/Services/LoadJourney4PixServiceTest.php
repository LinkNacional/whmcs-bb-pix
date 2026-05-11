<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Exceptions\Journey4PublicException;
use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Services\LoadJourney4PixService;
use PHPUnit\Framework\TestCase;

final class LoadJourney4PixServiceTest extends TestCase
{
    private const PAYER_DATA = [
        'clientFullName' => 'Empresa Teste LTDA',
        'payerDocType' => 'cnpj',
        'payerDocValue' => '28552001000168',
    ];

    protected function tearDown(): void
    {
        unset($GLOBALS['lknbbpix_test_invoices'][2004]);

        parent::tearDown();
    }

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

        $response = $service->run(999, 100, 15, 'LKN0000000999ABCDE1234567', self::PAYER_DATA);

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

        $response = $service->run(1000, 101, 20, 'LKN0000001000ABCDE1234567', self::PAYER_DATA);

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
            ->with('LKN0000002000ABCDE1234567', self::callback(function (array $payload): bool {
                return isset($payload['devedor']['nome'], $payload['devedor']['cnpj'])
                    && $payload['devedor']['nome'] === self::PAYER_DATA['clientFullName']
                    && $payload['devedor']['cnpj'] === self::PAYER_DATA['payerDocValue'];
            }))
            ->willReturn(['success' => true, 'data' => ['ok' => true]]);

        $pixRepo->expects(self::once())
            ->method('criarLocationRecorrencia')
            ->willReturn(['success' => true, 'data' => ['id' => 123]]);

        $pixRepo->expects(self::once())
            ->method('criarRecorrencia')
            ->with(self::callback(function (array $payload): bool {
                return isset($payload['vinculo']['objeto'])
                    && isset($payload['vinculo']['contrato'])
                    && isset($payload['vinculo']['devedor']['nome'])
                    && isset($payload['vinculo']['devedor']['cnpj'])
                    && isset($payload['calendario']['dataInicial'])
                    && isset($payload['calendario']['periodicidade'])
                    && !isset($payload['valor'])
                    && isset($payload['loc'])
                    && isset($payload['politicaRetentativa'])
                    && isset($payload['recebedor']['convenio'])
                    && $payload['vinculo']['objeto'] === 'Fatura WHMCS'
                    && $payload['vinculo']['devedor']['nome'] === self::PAYER_DATA['clientFullName']
                    && $payload['vinculo']['devedor']['cnpj'] === self::PAYER_DATA['payerDocValue']
                    && $payload['calendario']['periodicidade'] === 'MENSAL'
                    && $payload['loc'] === 123
                    && $payload['politicaRetentativa'] === 'NAO_PERMITE'
                    && $payload['recebedor']['convenio'] === '34627';
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

        $response = $service->run(2000, 102, 5, 'LKN0000002000ABCDE1234567', self::PAYER_DATA);

        self::assertTrue($response['success']);
        self::assertSame('REC_NEW_003', $response['data']['idRec']);
        self::assertSame('EMV_NEW_003', $response['data']['emv']);
        self::assertFalse($response['data']['cached']);
    }

    public function testRunThrowsWhenPayerDocTypeIsInvalid(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->method('findCreatedByClientAndDueDay')->willReturn([
            'success' => true,
            'data' => ['auth' => null],
        ]);

        $pixRepo->method('criarCobV')->willReturn(['success' => true, 'data' => ['ok' => true]]);
        $pixRepo->method('criarLocationRecorrencia')->willReturn(['success' => true, 'data' => ['id' => 1]]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tipo de documento do pagador inválido para recorrência.');

        $service->run(2001, 103, 10, 'LKN0000002001ABCDE1234567', [
            'clientFullName' => 'Cliente Inválido',
            'payerDocType' => 'ie',
            'payerDocValue' => '123',
        ]);
    }

    public function testRunDoesNotSendRecebedorWhenConvenioIsEmpty(): void
    {
        $GLOBALS['lknbbpix_gateway_variables']['convenio'] = '';

        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->method('findCreatedByClientAndDueDay')->willReturn([
            'success' => true,
            'data' => ['auth' => null],
        ]);

        $pixRepo->method('criarCobV')->willReturn(['success' => true, 'data' => ['ok' => true]]);
        $pixRepo->method('criarLocationRecorrencia')->willReturn(['success' => true, 'data' => ['id' => 2]]);

        $pixRepo->expects(self::once())
            ->method('criarRecorrencia')
            ->with(self::callback(function (array $payload): bool {
                return !isset($payload['recebedor']);
            }))
            ->willReturn(['success' => true, 'data' => ['idRec' => 'REC_NEW_004']]);

        $pixRepo->method('obterQrCodeComposto')->willReturn(['success' => true, 'data' => ['pixCopiaECola' => 'EMV_NEW_004']]);
        $authRepo->method('salvarCriada')->willReturn(['success' => true, 'data' => ['id' => 2]]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $response = $service->run(2002, 104, 10, 'LKN0000002002ABCDE1234567', self::PAYER_DATA);

        self::assertTrue($response['success']);
        self::assertSame('REC_NEW_004', $response['data']['idRec']);

        $GLOBALS['lknbbpix_gateway_variables']['convenio'] = '34627';
    }

    public function testRunUsesFallbackObjectNameWhenConfigIsEmpty(): void
    {
        $GLOBALS['lknbbpix_gateway_variables']['recurrence_object_name'] = '';

        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->method('findCreatedByClientAndDueDay')->willReturn([
            'success' => true,
            'data' => ['auth' => null],
        ]);

        $pixRepo->method('criarCobV')->willReturn(['success' => true, 'data' => ['ok' => true]]);
        $pixRepo->method('criarLocationRecorrencia')->willReturn(['success' => true, 'data' => ['id' => 99]]);

        $pixRepo->expects(self::once())
            ->method('criarRecorrencia')
            ->with(self::callback(function (array $payload): bool {
                return isset($payload['vinculo']['objeto'])
                    && $payload['vinculo']['objeto'] === 'Fatura WHMCS';
            }))
            ->willReturn(['success' => true, 'data' => ['idRec' => 'REC_NEW_005']]);

        $pixRepo->method('obterQrCodeComposto')->willReturn(['success' => true, 'data' => ['pixCopiaECola' => 'EMV_NEW_005']]);
        $authRepo->method('salvarCriada')->willReturn(['success' => true, 'data' => ['id' => 3]]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $response = $service->run(2003, 105, 12, 'LKN0000002003ABCDE1234567', self::PAYER_DATA);

        self::assertTrue($response['success']);
        self::assertSame('REC_NEW_005', $response['data']['idRec']);

        $GLOBALS['lknbbpix_gateway_variables']['recurrence_object_name'] = 'Fatura WHMCS';
    }

    public function testRunThrowsWhenInvoiceDueDateIsOverdueBeforeCallingCobv(): void
    {
        $GLOBALS['lknbbpix_test_invoices'][2004] = [
            'balance' => '100.00',
            'duedate' => date('Y-m-d', strtotime('-1 day')),
            'total' => '100.00',
            'notes' => ''
        ];

        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $pixRepo->expects(self::never())->method('criarCobV');
        $authRepo->expects(self::never())->method('findCreatedByClientAndDueDay');

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fatura vencida não pode seguir na Jornada 4. Utilize o fluxo manual.');

        $service->run(2004, 106, 10, 'LKN0000002004ABCDE1234567', self::PAYER_DATA);
    }

    public function testRunThrowsPublicValidationMessageWhenBbReturnsValidationDetail(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->expects(self::once())
            ->method('findCreatedByClientAndDueDay')
            ->willReturn([
                'success' => true,
                'data' => ['auth' => null],
            ]);

        $pixRepo->expects(self::once())
            ->method('criarCobV')
            ->willReturn([
                'success' => false,
                'data' => [
                    'statusCode' => 400,
                    'detail' => 'Número CPF ou CNPJ do Devedor não ativo na Receita Federal. (200-000) '
                ]
            ]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $this->expectException(Journey4PublicException::class);
        $this->expectExceptionMessage(
            'Erro ao gerar o PIX, motivo: Número CPF ou CNPJ do Devedor não ativo na Receita Federal. (200-000)'
        );

        $service->run(2005, 107, 10, 'LKN0000002005ABCDE1234567', self::PAYER_DATA);
    }

    public function testRunThrowsGenericPublicMessageWhenErrorIsNotValidation(): void
    {
        $pixRepo = $this->createMock(PixAutoRepository::class);
        $authRepo = $this->createMock(AuthRepository::class);

        $authRepo->expects(self::once())
            ->method('findCreatedByClientAndDueDay')
            ->willReturn([
                'success' => true,
                'data' => ['auth' => null],
            ]);

        $pixRepo->expects(self::once())
            ->method('criarCobV')
            ->willReturn([
                'success' => false,
                'data' => [
                    'statusCode' => 503,
                    'detail' => 'Serviço indisponível'
                ]
            ]);

        $service = new LoadJourney4PixService($pixRepo, $authRepo);

        $this->expectException(Journey4PublicException::class);
        $this->expectExceptionMessage('Erro interno ao gerar proposta do Pix Automático.');

        $service->run(2006, 108, 10, 'LKN0000002006ABCDE1234567', self::PAYER_DATA);
    }
}
