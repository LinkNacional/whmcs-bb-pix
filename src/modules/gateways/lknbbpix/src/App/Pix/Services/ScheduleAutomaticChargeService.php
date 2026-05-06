<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\Helpers\Invoice;

final class ScheduleAutomaticChargeService
{
    private PixAutoRepository $repository;

    public function __construct(?PixAutoRepository $repository = null)
    {
        $this->repository = $repository ?? new PixAutoRepository();
    }

    public function run(int $invoiceId, string $txid): array
    {
        $dueDate = Invoice::getDueDate($invoiceId);
        $amount = number_format(Invoice::getBalance($invoiceId), 2, '.', '');

        $payload = [
            'calendario' => [
                'dataDeVencimento' => $dueDate,
            ],
            'valor' => [
                'original' => $amount,
            ],
        ];

        $response = $this->repository->agendarCobrancaAutomatica($txid, $payload);

        return is_array($response) ? $response : ['success' => false, 'data' => ['reason' => 'invalid_response']];
    }
}
