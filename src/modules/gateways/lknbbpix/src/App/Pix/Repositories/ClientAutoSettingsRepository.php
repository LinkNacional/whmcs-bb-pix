<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Response;
use WHMCS\Database\Capsule;
use Throwable;

final class ClientAutoSettingsRepository extends AbstractDbRepository implements ClientAutoSettingsRepositoryInterface
{
    protected string $table = 'mod_lknbbpix_client_auto_settings';

    public function isEnabledForClient(int $clientId): bool
    {
        if ($clientId <= 0) {
            return true;
        }

        try {
            $record = $this->query()
                ->where('client_id', $clientId)
                ->first(['auto_enabled']);

            if (!$record) {
                return true;
            }

            return ((int) ($record->auto_enabled ?? 1)) === 1;
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao consultar configuração de Pix Automático por cliente',
                ['client_id' => $clientId],
                ['error' => $th->getMessage()]
            );

            return true;
        }
    }

    public function setEnabledForClient(int $clientId, bool $enabled): array
    {
        try {
            $now = date('Y-m-d H:i:s');

            $this->query()->updateOrInsert(
                ['client_id' => $clientId],
                [
                    'auto_enabled' => $enabled ? 1 : 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            return Response::return(true, [
                'clientId' => $clientId,
                'enabled' => $enabled,
            ]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao salvar configuração de Pix Automático por cliente',
                ['client_id' => $clientId, 'enabled' => $enabled],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function hasBlockingActiveAuth(int $clientId): bool
    {
        try {
            return Capsule::table('mod_lknbbpix_auths')
                ->where('client_id', $clientId)
                ->whereIn('status', ['CRIADA', 'APROVADA'])
                ->exists();
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao consultar autorizações ativas do cliente',
                ['client_id' => $clientId],
                ['error' => $th->getMessage()]
            );

            return true;
        }
    }
}
