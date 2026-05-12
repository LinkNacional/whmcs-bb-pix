<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Repositories\ClientAutoSettingsRepositoryInterface;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use Lkn\BBPix\Helpers\InvoiceOriginHelper;
use PHPUnit\Framework\TestCase;

final class InvoiceFlowDecisionMappingTest extends TestCase
{
    /**
     * @dataProvider mappingProvider
     */
    public function testOriginAndAuthorizationMapping(string $origin, bool $hasApprovedAuth, string $expectedDecision): void
    {
        $authRepository = $this->createMock(AuthRepository::class);
        $clientAutoSettingsRepository = $this->createMock(ClientAutoSettingsRepositoryInterface::class);

        $clientAutoSettingsRepository->expects(self::once())
            ->method('isEnabledForClient')
            ->with(55)
            ->willReturn(true);

        $authRepository->expects(self::once())
            ->method('findApprovedByClientAndDueDay')
            ->with(55, 12)
            ->willReturn([
                'success' => true,
                'data' => [
                    'auth' => $hasApprovedAuth ? (object) ['id_rec' => 'REC_MAP_1'] : null,
                ],
            ]);

        $decision = (new DecisionService($authRepository))->evaluate($origin, 55, 12);

        self::assertSame($expectedDecision, $decision);
    }

    public function mappingProvider(): array
    {
        return [
            'novo pedido com auth aprovada segue regra de ouro' => [
                InvoiceOriginHelper::NOVO_PEDIDO,
                true,
                DecisionService::MANUAL_TRADICIONAL,
            ],
            'renovacao com auth aprovada agenda cobranca automatica' => [
                InvoiceOriginHelper::CRON_RENOVACAO,
                true,
                DecisionService::COBR_AUTOMATICO,
            ],
            'novo pedido sem auth vai para jornada4' => [
                InvoiceOriginHelper::NOVO_PEDIDO,
                false,
                DecisionService::JORNADA4,
            ],
            'renovacao sem auth vai para jornada4' => [
                InvoiceOriginHelper::CRON_RENOVACAO,
                false,
                DecisionService::JORNADA4,
            ],
            'manual tradicional permanece manual' => [
                InvoiceOriginHelper::MANUAL_TRADICIONAL,
                false,
                DecisionService::MANUAL_TRADICIONAL,
            ],
        ];
    }
}
