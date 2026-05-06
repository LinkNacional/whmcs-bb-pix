<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DecisionServiceTest extends TestCase
{
    /**
     * @dataProvider evaluateProvider
     */
    public function testEvaluateReturnsExpectedDecision(
        string $origemFatura,
        bool $hasApprovedAuth,
        string $expectedDecision
    ): void {
        $authRepository = $this->createMock(AuthRepository::class);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->with(10, 15)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => $hasApprovedAuth ? (object) ['id_rec' => 'RR123'] : null
                ]
            ]);

        $service = new DecisionService($authRepository);

        self::assertSame($expectedDecision, $service->evaluate($origemFatura, 10, 15));
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

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->willReturn([
                'success' => false,
                'data' => ['reason' => 'db offline']
            ]);

        $service = new DecisionService($authRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15)
        );
    }

    public function testEvaluateReturnsManualTraditionalWhenRepositoryThrowsException(): void
    {
        $authRepository = $this->createMock(AuthRepository::class);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->willThrowException(new RuntimeException('db offline'));

        $service = new DecisionService($authRepository);

        self::assertSame(
            DecisionService::MANUAL_TRADICIONAL,
            $service->evaluate('CRON_RENOVACAO', 10, 15)
        );
    }
}