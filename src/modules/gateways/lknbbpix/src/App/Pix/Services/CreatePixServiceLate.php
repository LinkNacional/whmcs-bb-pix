<?php

namespace Lkn\BBPix\App\Pix\Services;

use DateTime;
use Exception;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepositoryLate;
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

        // Pós-emissão: consulta e valida juros/multa (logar somente inconsistências)
        try {
            $consultPixResponse = $this->pixGateway->consultPix($pixTxId);

            $expectedMulta = $requestBody['valor']['multa'] ?? null;
            $expectedJuros = $requestBody['valor']['juros'] ?? null;

            $actualMulta = $consultPixResponse['valor']['multa'] ?? null;
            $actualJuros = $consultPixResponse['valor']['juros'] ?? null;

            $toNumeric = function ($cfg): float {
                if (is_array($cfg)) {
                    if (isset($cfg['valorPerc'])) {
                        return (float) $cfg['valorPerc'];
                    }
                    if (isset($cfg['valor'])) {
                        return (float) $cfg['valor'];
                    }
                }
                return is_null($cfg) ? 0.0 : (float) $cfg;
            };

            $expectedMultaNum = $toNumeric($expectedMulta);
            $expectedJurosNum = $toNumeric($expectedJuros);
            $actualMultaNum = $toNumeric($actualMulta);
            $actualJurosNum = $toNumeric($actualJuros);

            $consistent = (
                ($expectedMultaNum == 0.0 ? $actualMultaNum == 0.0 : $actualMultaNum > 0.0)
                &&
                ($expectedJurosNum == 0.0 ? $actualJurosNum == 0.0 : $actualJurosNum > 0.0)
            );

            if (!$consistent) {
                Logger::log(
                    'Emitir COBV - juros/multa INCONSISTENTE',
                    [
                        'txId' => $pixTxId->getApiTransId(),
                        'expectedNumeric' => [
                            'multa' => $expectedMultaNum,
                            'juros' => $expectedJurosNum
                        ],
                        'actualNumeric' => [
                            'multa' => $actualMultaNum,
                            'juros' => $actualJurosNum
                        ],
                        'consistentWithRequest' => $consistent
                    ],
                    $consultPixResponse
                );
            }
        } catch (\Throwable $e) {
            Logger::log(
                'Emitir COBV - verificação juros/multa falhou',
                [
                    'txId' => $pixTxId->getApiTransId(),
                    'error' => $e->getMessage()
                ]
            );
        }

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
        $discountService = new DiscountService($request['invoiceId']);
        $dueDate = Invoice::getDueDate($request['invoiceId']);

        $todayDatetime = new DateTime();
        $dueDatetime = new DateTime($dueDate);
        $enableCalculation = Config::setting('enable_fees_calculation') ?? false;
        $enableFeesInterest = Config::setting('enable_fees_interest') ?? false;

        $paymentValueWithDiscount = $discountService->calculate();

        $diffDays = 0;
        $validDueDate = $dueDate;
        $fineDays = (int) Config::setting('fine_days');
        $finalFineDays = (string) $fineDays;

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
                $finalFineDays = $fineDays;
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

        $isFinePercent = (Config::setting('cob_type') !== 'fixed');
        $fineValue = Config::setting('fine') ?? '0';
        $interestValue = Config::setting('interest_rate') ?? '0';

        // Aplica configuração de multa quando habilitado e informado
        if ($enableFeesInterest && $fineValue !== '0') {
            if ($isFinePercent) {
                // Percentual
                $requestBody['valor']['multa'] = [
                    'modalidade' => 2,
                    'valorPerc' => number_format((float) $fineValue, 2, '.', '')
                ];
            } else {
                // Fixo
                $requestBody['valor']['multa'] = [
                    'modalidade' => 1,
                    'valor' => number_format((float) $fineValue, 2, '.', '')
                ];
            }
        }

        // Aplica configuração de juros quando habilitado e informado
        if ($enableFeesInterest && $interestValue !== '0') {
            // Juros percentual ao dia
            $requestBody['valor']['juros'] = [
                'modalidade' => 2,
                'valorPerc' => number_format((float) $interestValue, 2, '.', '')
            ];
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
