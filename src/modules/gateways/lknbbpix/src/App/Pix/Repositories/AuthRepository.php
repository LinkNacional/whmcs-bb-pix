<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Response;
use Throwable;

class AuthRepository extends AbstractDbRepository
{
    protected string $table = 'mod_lknbbpix_auths';

    public function findApprovedByClientAndDueDay(int $clientId, int $dueDay): array
    {
        try {
            $auth = $this->query()
                ->where('client_id', $clientId)
                ->where('due_day', $dueDay)
                ->where('status', 'APROVADA')
                ->first();

            return Response::return(true, ['auth' => $auth]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao consultar autorização Pix Automático',
                ['client_id' => $clientId, 'due_day' => $dueDay],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function findCreatedByClientAndDueDay(int $clientId, int $dueDay): array
    {
        try {
            $auth = $this->query()
                ->where('client_id', $clientId)
                ->where('due_day', $dueDay)
                ->where('status', 'CRIADA')
                ->orderByDesc('id')
                ->first();

            return Response::return(true, ['auth' => $auth]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao consultar autorização CRIADA do Pix Automático',
                ['client_id' => $clientId, 'due_day' => $dueDay],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function salvarCriada(
        int $clientId,
        string $idRec,
        int $dueDay,
        string $periodicidade,
        ?string $emvPayload = null
    ): array {
        try {
            $insertId = $this->query()->insertGetId([
                'client_id' => $clientId,
                'id_rec' => $idRec,
                'due_day' => $dueDay,
                'periodicidade' => $periodicidade,
                'status' => 'CRIADA',
                'emv_payload' => $emvPayload,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return Response::return(true, ['id' => $insertId]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao salvar autorização Pix Automático',
                [
                    'client_id' => $clientId,
                    'id_rec' => $idRec,
                    'due_day' => $dueDay,
                    'periodicidade' => $periodicidade,
                    'emv_payload' => $emvPayload,
                ],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function updateEmvPayload(string $idRec, string $emvPayload): array
    {
        try {
            $affectedRows = $this->query()
                ->where('id_rec', $idRec)
                ->update([
                    'emv_payload' => $emvPayload,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            return Response::return(true, ['affectedRows' => $affectedRows]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao atualizar EMV da autorização Pix Automático',
                ['id_rec' => $idRec],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function atualizarStatusPorIdRec(string $idRec, string $status): array
    {
        try {
            $affectedRows = $this->query()
                ->where('id_rec', $idRec)
                ->update([
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            return Response::return(true, ['affectedRows' => $affectedRows]);
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao atualizar status da autorização Pix Automático',
                ['id_rec' => $idRec, 'status' => $status],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }
}
