<?php

namespace Lkn\BBPix\App\Pix\Services;

use Exception;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Validator;

class CreatePixService
{
    private PixApiRepository $pixGateway;

    public function __construct(PixApiRepository $pixGateway)
    {
        $this->pixGateway = $pixGateway;
    }

    public function run(array $request): array
    {
        $this->validate($request);

        $pixTxId = PixTaxId::create($request['invoiceId'], 'CRIADO');
        $requestBody = $this->buildRequestBody($request);

        $createPixResponse = $this->pixGateway->createPix(
            $pixTxId->getApiTransId(),
            $requestBody
        );

        if (empty($createPixResponse['location'])) {
            throw new PixException(PixExceptionCodes::EXTERNAL_API_ERROR);
        }

        $addTransacResponse = Invoice::addTransac(
            Invoice::getClientId($request['invoiceId']),
            $request['invoiceId'],
            $pixTxId->getTransIdForWhmcs()
        );

        if ($addTransacResponse['result'] !== 'success') {
            throw new PixException(PixExceptionCodes::EXTERNAL_API_ERROR);
        }

        return [
            'location' => $createPixResponse['location'],
            'pixValue' => $createPixResponse['valor']['original'],
            'pixCopiaECola' => $createPixResponse['pixCopiaECola']
        ];
    }

    /**
     * Builds the request body according to the API requirements.
     *
     * @since 1.2.0
     *
     * @param array $request
     *
     * @return array
     */
    private function buildRequestBody(array $request): array
    {
        $discountService = new DiscountService($request['invoiceId']);

        $paymentValueWithDiscount = $discountService->calculate();

        $requestBody = [
            'calendario' => [
                'expiracao' => Config::setting('pix_expiration') * 86400
            ],

            'valor' => [
                'original' => (string) $paymentValueWithDiscount
            ],

            'chave' => Config::setting('receiver_pix_key')
        ];

        if (
            Config::setting('send_payer_doc_and_name')
            && !empty($request['payerDocType'])
            && !empty($request['payerDocValue'])
            && !empty($request['clientFullName'])
        ) {
            $requestBody['devedor'] = [
                $request['payerDocType'] => $request['payerDocValue'],
                'nome' => $request['clientFullName']
            ];
        }

        $pixDescription = Config::setting('pix_descrip');

        if (!empty($pixDescription)) {
            // TODO: remove special chars. Keeponly letters with no accents and numbers.
            $requestBody['solicitacaoPagador'] = $pixDescription;
        }

        return $requestBody;
    }

    /**
     * @since 1.2.0
     *
     * @param array $request
     *
     * @throws Exception when an error is found in the payment request.
     *
     * @return void
     */
    private function validate(array $request): void
    {
        $invoiceStatus = strtolower(Invoice::getStatus($request['invoiceId']));

        if ($invoiceStatus !== 'unpaid') {
            throw new PixException(PixExceptionCodes::TRIED_TO_PAY_INVOICE_WITH_STATUS_OTHER_THAN_UNPAID);
        }

        $invoiceBalance = Invoice::getBalance($request['invoiceId']);

        if ($request['paymentValue'] > $invoiceBalance) {
            throw new PixException(PixExceptionCodes::PAYMENT_VALUE_EXCEEDS_INVOICE_BALANCE);
        }

        if (Config::setting('send_payer_doc_and_name')) {
            if ($request['payerDocType'] === 'cpf') {
                if (!Validator::cpf($request['payerDocValue'])) {
                    throw new PixException(PixExceptionCodes::INVALID_CPF);
                }
            } elseif (!Validator::cnpj($request['payerDocValue'])) {
                throw new PixException(PixExceptionCodes::INVALID_CNPJ);
            }
        }
    }
}
