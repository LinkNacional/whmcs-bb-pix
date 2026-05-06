<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\Helpers\Logger;
use Throwable;

final class DecisionService
{
    public const MANUAL_TRADICIONAL = 'MANUAL_TRADICIONAL';

    public const COBR_AUTOMATICO = 'COBR_AUTOMATICO';

    public const JORNADA4 = 'JORNADA4';

    private AuthRepository $authRepository;

    public function __construct(?AuthRepository $authRepository = null)
    {
        $this->authRepository = $authRepository ?? new AuthRepository();
    }

    public function evaluate(string $origemFatura, int $clientId, int $dueDay): string
    {
        try {
            $origemFatura = strtoupper(trim($origemFatura));

            $approvedAuthResponse = $this->authRepository->findApprovedByClientAndDueDay($clientId, $dueDay);

            if (!is_array($approvedAuthResponse) || !($approvedAuthResponse['success'] ?? false)) {
                Logger::log(
                    'Falha ao decidir fluxo do Pix Automático',
                    [
                        'origemFatura' => $origemFatura,
                        'clientId' => $clientId,
                        'dueDay' => $dueDay
                    ],
                    $approvedAuthResponse
                );

                return self::MANUAL_TRADICIONAL;
            }

            $hasApprovedAuth = !empty($approvedAuthResponse['data']['auth']);

            if ($origemFatura === 'NOVO_PEDIDO' && $hasApprovedAuth) {
                return self::MANUAL_TRADICIONAL;
            }

            if ($origemFatura === 'CRON_RENOVACAO' && $hasApprovedAuth) {
                return self::COBR_AUTOMATICO;
            }

            if (in_array($origemFatura, ['NOVO_PEDIDO', 'CRON_RENOVACAO'], true)) {
                return self::JORNADA4;
            }

            return self::MANUAL_TRADICIONAL;
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao decidir fluxo do Pix Automático',
                [
                    'origemFatura' => $origemFatura,
                    'clientId' => $clientId,
                    'dueDay' => $dueDay
                ],
                ['error' => $th->getMessage()]
            );

            return self::MANUAL_TRADICIONAL;
        }
    }
}