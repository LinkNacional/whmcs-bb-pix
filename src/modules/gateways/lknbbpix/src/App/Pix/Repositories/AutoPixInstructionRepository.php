<?php

namespace Lkn\BBPix\App\Pix\Repositories;

final class AutoPixInstructionRepository extends AbstractDbRepository
{
    protected string $table = 'mod_lknbbpix_auto_instructions';

    public function insert(array $data): void
    {
        $this->query()->insert($data);
    }

    public function findByInvoiceId(int $invoiceId): array
    {
        $rows = $this->query()->where('invoice_id', $invoiceId)->orderBy('id', 'desc')->get();

        if (!$rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array)$row;
        }

        return $result;
    }

    public function findPendingByInvoice(int $invoiceId): ?array
    {
        $row = $this->query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['pending', 'scheduled'])
            ->orderBy('id', 'desc')
            ->first();

        return $row ? (array)$row : null;
    }

    public function findPendingInstructions(int $invoiceId): array
    {
        $rows = $this->query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['pending', 'scheduled'])
            ->orderBy('attempt_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if (!$rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array)$row;
        }

        return $result;
    }

    public function findLastByStatus(int $invoiceId, array $statuses): ?array
    {
        $row = $this->query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', $statuses)
            ->orderBy('attempt_number', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $row ? (array) $row : null;
    }

    public function markLiquidatedByIdFimAFim(string $idFimAFim, array $extra = []): void
    {
        $payload = array_merge($extra, [
            'status' => 'liquidated',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->query()->where('id_fim_a_fim', $idFimAFim)->update($payload);
    }

    public function updateStatusByIdFimAFim(string $idFimAFim, string $status, array $extra = []): void
    {
        $payload = array_merge($extra, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->query()->where('id_fim_a_fim', $idFimAFim)->update($payload);
    }

    public function updateById(int $id, array $payload): void
    {
        if (!isset($payload['updated_at'])) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->query()->where('id', $id)->update($payload);
    }

    public function findByIdFimAFim(string $idFimAFim): ?array
    {
        $row = $this->query()->where('id_fim_a_fim', $idFimAFim)->first();

        return $row ? (array)$row : null;
    }
}

