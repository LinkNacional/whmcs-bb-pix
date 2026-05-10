<?php

namespace Lkn\BBPix\App\Pix\Services;

use DateTime;
use Exception;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\PixApiRepositoryLate;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Validator;

final class CreatePixServiceLate extends CreatePixService
{
    private PixApiRepositoryLate $pixGateway;

    public function __construct(PixApiRepositoryLate $pixGateway)
    {
        $this->pixGateway = $pixGateway;
    }

    public function run(array $request): array
    {
        $this->validate($request);

        $pixTxId = PixTaxId::create($request['invoiceId'], 'CRIADO');
        $requestBody = $this->buildRequestBody($request);

        if (isset($requestBody['errorMsg'])) {
            throw new PixException(PixExceptionCodes::INVALID_DUE_DATE);
        }

        $createPixResponse = $this->pixGateway->createPix(
            $pixTxId->getApiTransId(),
            $requestBody
        );

        $addTransacResponse = Invoice::addTransac(
            Invoice::getClientId($request['invoiceId']),
            $request['invoiceId'],
            $pixTxId->getTransIdForWhmcs()
        );

        if ($addTransacResponse['result'] !== 'success') {
            Logger::log('Erro cobrança PIX', var_export($requestBody, true), var_export($addTransacResponse, true));

            throw new PixException(PixExceptionCodes::EXTERNAL_API_ERROR);
        }

        return [
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
        $discountService = new DiscountService($request['invoiceId'], null, 'create_pix_service_late');
        $dueDate = Invoice::getDueDate($request['invoiceId']);

        $paymentValueWithDiscount = $discountService->calculate();

        $todayDatetime = new DateTime();
        $dueDatetime = new DateTime($dueDate);
        $diffDays = 0;
        $validDueDate = $dueDate;
        $fineDays = (int) Config::setting('fine_days');
        $finalFineDays = (string) $fineDays;
        $enableCalculation = Config::setting('enable_fees_calculation') ?? false;

        // Cliente está gerando pix em uma fatura com data de vencimento anterior a data de hoje
        if ($dueDatetime < $todayDatetime) {
            // Caso cálculo de juros/multa esteja desabilitado gerar mensagem de erro apenas
            if(!$enableCalculation) {
                return [
                    'errorMsg' => 'Não foi possível gerar cobrança, data vencimento excede limite'
                ];
            }

            // Calcula quantos dias se passaram da data de vencimento
            $diffDays = $todayDatetime->diff($dueDatetime)->days ?? 0;
            $validDueDate = $todayDatetime->format('Y-m-d');
            // Calcula quantos dias após o vencimento ainda deverá ter o PIX válido
            $finalFineDays = number_format($fineDays - $diffDays, 0, '', '');

            // Caso fatura esteja vencida além da data limite não gerar PIX
            // Caso haja um erro no cálculo de data limite não gera PIX
            if ($fineDays < $diffDays) {
                return [
                    'errorMsg' => 'Não foi possível gerar cobrança, data vencimento excede limite'
                ];
            }
        }

        $requestBody = [
            'calendario' => [
                'dataDeVencimento' => $validDueDate,
                'validadeAposVencimento' => (string) $finalFineDays
            ],

            'valor' => [
                'original' => (string) $paymentValueWithDiscount
            ],

            'chave' => Config::setting('receiver_pix_key')
        ];

        $cobType = (Config::setting('cob_type') === 'fixed') ? '1' : '2';
        $fineValue = Config::setting('fine') ?? '0';
        $interestValue = Config::setting('interest_rate') ?? '0';

        // Verifica se há um valor de multa definido
        if ($fineValue !== '0') {
            // Caso geração de fatura seja anterior a data de emissão da fatura
            if ($diffDays > 0) {
                // Gera PIX com multa já calculada
                $requestBody['valor']['original'] = (float) $requestBody['valor']['original'] + ((float) $fineValue * $diffDays);
                $requestBody['valor']['original'] = number_format($requestBody['valor']['original'], 2, '.', '');
            } else {
                // Continua a fazer a requisição com o cálculo de juros/multa
                $requestBody['valor']['multa'] = [
                    'modalidade' => $cobType,
                    'valor' => $fineValue
                ];
            }
        }

        // Verifica se há um valor de juros definido
        if ($interestValue !== '0') {
            // Caso geração de fatura seja anterior a data de emissão da fatura
            if ($diffDays > 0) {
                // Gera PIX com multa já calculada
                $tempAmount = (float) $requestBody['valor']['original'] * ((float) $interestValue / 100);
                $tempAmount = $diffDays * $tempAmount;

                $requestBody['valor']['original'] = (float) $requestBody['valor']['original'] + $tempAmount;
                $requestBody['valor']['original'] = number_format($requestBody['valor']['original'], 2, '.', '');
            } else {
                // Continua a fazer a requisição com o cálculo de juros/multa
                $requestBody['valor']['juros'] = [
                    'modalidade' => '1',
                    'valor' => $interestValue
                ];
            }
        }

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
