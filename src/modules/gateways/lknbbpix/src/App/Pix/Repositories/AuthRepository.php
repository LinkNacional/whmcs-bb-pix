<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Response;
use WHMCS\Database\Capsule;
use Throwable;

class AuthRepository extends AbstractDbRepository
{
    protected string $table = 'mod_lknbbpix_auths';

    private ?bool $hasEmvAmountSnapshotColumn = null;

    private ?bool $hasEmvDueDateSnapshotColumn = null;

    private ?bool $hasEmvVersionColumn = null;

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
        ?string $emvPayload = null,
        ?string $emvAmountSnapshot = null,
        ?string $emvDueDateSnapshot = null
    ): array {
        try {
            $insertData = [
                'client_id' => $clientId,
                'id_rec' => $idRec,
                'due_day' => $dueDay,
                'periodicidade' => $periodicidade,
                'status' => 'CRIADA',
                'emv_payload' => $emvPayload,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($this->hasEmvAmountSnapshotColumn()) {
                $insertData['emv_amount_snapshot'] = $emvAmountSnapshot;
            }

            if ($this->hasEmvDueDateSnapshotColumn()) {
                $insertData['emv_due_date_snapshot'] = $emvDueDateSnapshot;
            }

            $insertId = $this->query()->insertGetId($insertData);

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
                    'emv_amount_snapshot' => $emvAmountSnapshot,
                    'emv_due_date_snapshot' => $emvDueDateSnapshot,
                ],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function updateEmvPayload(
        string $idRec,
        string $emvPayload,
        ?string $emvAmountSnapshot = null,
        ?string $emvDueDateSnapshot = null,
        bool $incrementVersion = false
    ): array
    {
        try {
            $updateData = [
                'emv_payload' => $emvPayload,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($emvAmountSnapshot !== null && $this->hasEmvAmountSnapshotColumn()) {
                $updateData['emv_amount_snapshot'] = $emvAmountSnapshot;
            }

            if ($emvDueDateSnapshot !== null && $this->hasEmvDueDateSnapshotColumn()) {
                $updateData['emv_due_date_snapshot'] = $emvDueDateSnapshot;
            }

            if ($incrementVersion && $this->hasEmvVersionColumn()) {
                $updateData['emv_version'] = Capsule::raw('emv_version + 1');
            }

            $affectedRows = $this->query()
                ->where('id_rec', $idRec)
                ->update($updateData);

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

    private function hasEmvAmountSnapshotColumn(): bool
    {
        if ($this->hasEmvAmountSnapshotColumn === null) {
            $this->hasEmvAmountSnapshotColumn = Capsule::schema()->hasColumn($this->table, 'emv_amount_snapshot');
        }

        return $this->hasEmvAmountSnapshotColumn;
    }

    private function hasEmvDueDateSnapshotColumn(): bool
    {
        if ($this->hasEmvDueDateSnapshotColumn === null) {
            $this->hasEmvDueDateSnapshotColumn = Capsule::schema()->hasColumn($this->table, 'emv_due_date_snapshot');
        }

        return $this->hasEmvDueDateSnapshotColumn;
    }

    private function hasEmvVersionColumn(): bool
    {
        if ($this->hasEmvVersionColumn === null) {
            $this->hasEmvVersionColumn = Capsule::schema()->hasColumn($this->table, 'emv_version');
        }

        return $this->hasEmvVersionColumn;
    }
}
