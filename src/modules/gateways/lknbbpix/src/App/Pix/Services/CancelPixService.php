<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;

final class CancelPixService
{
    private PixApiRepository $pixGateway;

    public function __construct(PixApiRepository $pixGateway)
    {
        $this->pixGateway = $pixGateway;
    }

    public function run(array $request): array
    {
        $pixTxId = PixTaxId::fromWhmcsTransId(
            $request['transacId'],
            $request['invoiceId']
        );

        $cancelResponse = $this->pixGateway->cancelPix($pixTxId);

        // Validar falhas explícitas e implícitas no retorno
        if (!is_array($cancelResponse) || empty($cancelResponse)) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CANCEL_PIX);
        }

        // Formatos de erro possíveis pela API do BB
        if (
            isset($cancelResponse['error']) ||
            isset($cancelResponse['errors']) && !empty($cancelResponse['errors']) ||
            (isset($cancelResponse['type']) && $cancelResponse['type']) ||
            (isset($cancelResponse['status']) && is_numeric($cancelResponse['status']) && (int)$cancelResponse['status'] >= 400)
        ) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CANCEL_PIX);
        }

        // Considerar sucesso apenas quando a API confirmar o status correto
        if (!isset($cancelResponse['status'])) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CANCEL_PIX);
        }

        $apiStatus = (string) $cancelResponse['status'];
        if (strtoupper($apiStatus) !== 'REMOVIDA_PELO_USUARIO_RECEBEDOR') {
            throw new PixException(PixExceptionCodes::COULD_NOT_CANCEL_PIX);
        }

        return [
            'status' => $apiStatus,
            'txid' => $pixTxId->getApiTransId(),
            'cancelTransId' => 'CANCELADOx' . $pixTxId->suffix
        ];
    }
} 