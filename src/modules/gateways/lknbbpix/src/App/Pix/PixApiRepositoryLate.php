<?php

namespace Lkn\BBPix\App\Pix;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\Helpers\Logger;

/**
 * Provides methods for communicating with Chatwoot API.
 *
 * The methods return the raw response of the API.
 *
 * @since 1.2.0
 */
final class PixApiRepositoryLate extends AbstractPixApiRepository
{
    public function __construct()
    {
        parent::__construct('pix.read pix.write cobv.read cobv.write');
    }

    public function createPix(string $txId, array $body): array|bool|null
    {
        $response = $this->request('PUT', "cobv/$txId", $body);

        Logger::log(
            'Criar cobrança Pix',
            ['txId' => $txId, 'body' => $body],
            $response
        );

        return $this->getResponseData($response);
    }

    public function consultPix(PixTaxId $taxId): array|bool|null
    {
        $taxId = $taxId->getApiTransId();

        $response = $this->request('GET', "cobv/$taxId");

        Logger::log('Consultar Pix', ['txId' => $taxId], $response);

        return $this->getResponseData($response);
    }

    public function requestRefund(
        string $e2eid,
        string $refundValue
    ): array|bool|null {
        $txid = bin2hex(random_bytes(17));

        $response = $this->request(
            'PUT',
            "pix/$e2eid/devolucao/$txid",
            ['valor' => $refundValue]
        );

        Logger::log(
            'Solicitar reembolso',
            [
                'e2eid' => $e2eid,
                'txId' => $txid,
                'refundValue' => $refundValue
            ],
            $response
        );

        return $this->getResponseData($response);
    }
}
