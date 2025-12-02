<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;

final class RefundPixService
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

        $response = $this->pixGateway->consultPix($pixTxId);

        $pixE2eid = $response['pix'][0]['endToEndId'];

        $refundResponse = $this->pixGateway->requestRefund(
            $pixE2eid,
            $request['refundAmount']
        );

        return [
            'status' => $refundResponse['status'],
            'reason' => 'outros',
            'refundTransId' => 'REEMBOLSOx' . $refundResponse['rtrId']
        ];
    }
}
