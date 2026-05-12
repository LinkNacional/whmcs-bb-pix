<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Repositories\ClientAutoSettingsRepositoryInterface;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DecisionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['lknbbpix_gateway_variables']['enable_pix_automatic'] = 'on';
    }

    protected function tearDown(): void
    {
        $GLOBALS['lknbbpix_gateway_variables']['enable_pix_automatic'] = 'on';

        parent::tearDown();
    }

    /**
     * @dataProvider evaluateProvider
     */
    public function testEvaluateReturnsExpectedDecision(
        string $origemFatura,
        bool $hasApprovedAuth,
        string $expectedDecision
    ): void {
        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $clientAutoSettingsRepository->expects(self::once())
            ->method('isEnabledForClient')
            ->with(10)
            ->willReturn(true);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->with(10, 15)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => $hasApprovedAuth ? (object) ['id_rec' => 'RR123'] : null
                ]
            ]);

        $service = new DecisionService($authRepository, $clientAutoSettingsRepository);

        self::assertSame($expectedDecision, $service->evaluate($origemFatura, 10, 15, '2099-12-31'));
    }

    public function evaluateProvider(): array
    {
        return [
            'regra de ouro novo pedido com autorizacao aprovada' => [
                'origemFatura' => 'NOVO_PEDIDO',
                'hasApprovedAuth' => true,
                'expectedDecision' => DecisionService::MANUAL_TRADICIONAL,
            ],
            'cron renovacao com autorizacao aprovada' => [
                'origemFatura' => 'CRON_RENOVACAO',
                'hasApprovedAuth' => true,
                'expectedDecision' => DecisionService::COBR_AUTOMATICO,
            ],
            'novo pedido sem autorizacao aprovada' => [
                'origemFatura' => 'NOVO_PEDIDO',
                'hasApprovedAuth' => false,
                'expectedDecision' => DecisionService::JORNADA4,
            ],
            'cron renovacao sem autorizacao aprovada' => [
                'origemFatura' => 'CRON_RENOVACAO',
                'hasApprovedAuth' => false,
                'expectedDecision' => DecisionService::JORNADA4,
            ],
            'origem desconhecida' => [
                'origemFatura' => 'OUTRA_ORIGEM',
                'hasApprovedAuth' => false,
                'expectedDecision' => DecisionService::MANUAL_TRADICIONAL,
            ],
        ];
    }

    public function testEvaluateReturnsManualTraditionalWhenRepositoryReturnsFailure(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $clientAutoSettingsRepository->expects(self::once())
            ->method('isEnabledForClient')
            ->with(10)
            ->willReturn(true);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->willReturn([
                'success' => false,
                'data' => ['reason' => 'db offline']
            ]);

        $service = new DecisionService($authRepository, $clientAutoSettingsRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15, '2099-12-31')
        );
    }

    public function testEvaluateReturnsManualTraditionalWhenPixAutomaticIsDisabled(): void
    {
        $GLOBALS['lknbbpix_gateway_variables']['enable_pix_automatic'] = 'off';

        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $authRepository->expects(self::never())
            ->method('findApprovedByClientAndDueDay');

        $clientAutoSettingsRepository->expects(self::never())
            ->method('isEnabledForClient');

        $service = new DecisionService($authRepository, $clientAutoSettingsRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15)
        );
    }

    public function testEvaluateReturnsManualTraditionalWhenClientAutoPixIsDisabled(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $clientAutoSettingsRepository->expects(self::once())
            ->method('isEnabledForClient')
            ->with(10)
            ->willReturn(false);

        $authRepository->expects(self::never())
            ->method('findApprovedByClientAndDueDay');

        $service = new DecisionService($authRepository, $clientAutoSettingsRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15, '2099-12-31')
        );
    }

    public function testEvaluateReturnsManualTraditionalWhenRepositoryThrowsException(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $clientAutoSettingsRepository->expects(self::once())
            ->method('isEnabledForClient')
            ->with(10)
            ->willReturn(true);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->willThrowException(new RuntimeException('db offline'));

        $service = new DecisionService($authRepository, $clientAutoSettingsRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15, '2099-12-31')
        );
    }

    public function testEvaluateReturnsManualTraditionalWhenDueDateIsOverdue(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);

        $authRepository->expects(self::never())
            ->method('findApprovedByClientAndDueDay');

        $service = new DecisionService($authRepository);
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15, $yesterday)
        );
    }

    public function testEvaluateKeepsAutomaticFlowEligibilityWhenDueDateIsToday(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->with(10, 15)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => null
                ]
            ]);

        $service = new DecisionService($authRepository);
        $today = date('Y-m-d');

        self::assertSame(
            DecisionService::JORNADA4,
            $service->evaluate('CRON_RENOVACAO', 10, 15, $today)
        );
    }
}