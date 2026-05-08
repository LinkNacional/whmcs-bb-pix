<?php

namespace Lkn\BBPix\App\Pix;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\App\Pix\Services\CreatePixService;
use Lkn\BBPix\App\Pix\Services\CreatePixServiceLate;
use Lkn\BBPix\App\Pix\Services\InvoiceHasActivePixService;
use Lkn\BBPix\App\Pix\Services\RefundPixService;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Pix;
use Lkn\BBPix\Helpers\Response;
use Throwable;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class PixController
{
    private readonly object $pixApiRepository;
    private readonly object $createPixService;
    private readonly RefundPixService $refundPixService;
    private readonly InvoiceHasActivePixService $invoiceHasActivePixService;
    private readonly QRCode $qrCodeTextGenerator;

    public function __construct($cobType = 'cob')
    {
        if ($cobType === 'cob') {
            $this->pixApiRepository = new PixApiRepository();
            $this->createPixService = new CreatePixService($this->pixApiRepository);
        } else {
            $this->pixApiRepository = new PixApiRepositoryLate();
            $this->createPixService = new CreatePixServiceLate($this->pixApiRepository);
        }

        $this->refundPixService = new RefundPixService($this->pixApiRepository);
        $this->invoiceHasActivePixService = new InvoiceHasActivePixService($this->pixApiRepository);

        $qrCodeOptions = new QROptions();
        $qrCodeOptions->eccLevel = QRCode::ECC_M;
        $qrCodeOptions->outputType = QRCode::OUTPUT_IMAGE_PNG;
        $qrCodeOptions->pngCompression = 0;

        $this->qrCodeTextGenerator = new QRCode($qrCodeOptions);
    }

    /**
     * Undocumented function
     *
     * @since
     *
     * @param array $createPixRequest structure must be [
     *                                clientFullName => ,
     *                                payerDocType => ,
     *                                payerDocValue => ,
     *                                invoiceId => ,
     *                                paymentValue => ,
     *                                clientId =>
     *                                ]
     *
     * @return array
     */
    public function create(array $createPixRequest): array
    {
        try {
            $invoiceHasActivePixResponse = $this->invoiceHasActivePixService->run($createPixRequest['invoiceId']);

            if (is_array($invoiceHasActivePixResponse)) {
                return Response::return(true, [
                    'pixCode' => $invoiceHasActivePixResponse['pixCopiaECola'],
                    'pixQrCodeBase64' => $this->qrCodeTextGenerator->render($invoiceHasActivePixResponse['pixCopiaECola']),
                    'pixValue' => $invoiceHasActivePixResponse['pixValue']
                ]);
            }

            $createPixServiceResponse = $this->createPixService->run($createPixRequest);

            return Response::return(true, [
                'pixCode' => $createPixServiceResponse['pixCopiaECola'],
                'pixQrCodeBase64' => $this->qrCodeTextGenerator->render($createPixServiceResponse['pixCopiaECola']),
                'pixValue' => $createPixServiceResponse['pixValue']
            ]);
        } catch (PixException $e) {
            Logger::log($e->exceptionCode->label(), [$e]);

            return Response::return(false, ['error' => $e->exceptionCode->label()]);
        }
    }

    public function refund(array $refundPixRequest): array
    {
        try {
            $response = $this->refundPixService->run($refundPixRequest);

            return Response::return(true, [
                'status' => $response['status'],
                'refundTransId' => $response['refundTransId']
            ]);
        } catch (PixException $e) {
            Logger::log($e->exceptionCode->label(), [$e]);

            return Response::return(false, [
                'error' => $e->exceptionCode->label(),
                'errorMsg' => $e->exceptionCode->label()
            ]);
        } catch (Throwable $e) {
            Logger::log('Refund Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return Response::return(false, [
                'error' => 'Não foi possível solicitar a devolução do Pix.',
                'errorMsg' => 'Não foi possível solicitar a devolução do Pix.'
            ]);
        }
    }

    public function checkAndConfirmInvoicePayment(int $invoiceId): void
    {
        $consultPixResponse = $this->getPix($invoiceId);

        if (!$consultPixResponse['success']) {
            Response::api(false, ['code' => $consultPixResponse['data']['code']]);
            return;
        }

        $pixStatus = $consultPixResponse['data']['status'];

        if ($pixStatus === 'ATIVA') {
            $expirationDate = $consultPixResponse['data']['calendario']['expiracao'];
            $createdAtDate = $consultPixResponse['data']['calendario']['criacao'];
            $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

            if ($cobType === 'cobv') {
                return;
            }

            if (Pix::isExpired($expirationDate, $createdAtDate)) {
                Response::api(false, ['code' => 'pix-is-active-but-expired']);
                return;
            }

            Response::api(false, ['code' => 'pix-still-active']);
            return;
        }

        if ($pixStatus === 'REMOVIDA_PELO_USUARIO_RECEBEDOR') {
            Response::api(false, ['code' => 'pix-removed-by-issuer']);
            return;
        }

        if ($pixStatus === 'REMOVIDA_PELO_PSP') {
            Response::api(false, ['code' => 'pix-removed-by-psp']);
            return;
        }

        $invoiceStatus = Invoice::getStatus($invoiceId);

        if ($invoiceStatus !== 'Unpaid') {
            Response::api(false, ['code' => 'invoice-status-is-not-unpaid']);
            return;
        }

        if (!isset($consultPixResponse['data']['pix'][0]['valor'])) {
            Response::api(false, ['code' => 'pix-is-concluded-and-not-paid']);
            return;
        }

        $apiTxId = $consultPixResponse['data']['txid'];
        $paidValue = $consultPixResponse['data']['pix'][0]['valor'];
        $paymentDate = $consultPixResponse['data']['pix'][0]['horario'];
        $pixEndToEndId = $consultPixResponse['data']['pix'][0]['endToEndId'];

        $confirmPaymentService = new ConfirmPaymentService();

        $confirmPaymentService->run($apiTxId, $paidValue, $paymentDate, $pixEndToEndId);

        Response::api(true, ['code' => 'payment-confirmed']);
        return;
    }

    private function getPix(int $invoiceId): array
    {
        $localApiResponse = localAPI('GetTransactions', ['invoiceid' => $invoiceId]);

        $transacs = $localApiResponse['transactions']['transaction'] ?? [];

        if (!is_array($transacs) || count($transacs) === 0) {
            return Response::return(false, ['code' => 'invoice-has-no-transactions']);
        }

        $latestTransac = end($transacs);

        if (!is_array($latestTransac) || !isset($latestTransac['gateway'], $latestTransac['transid'])) {
            return Response::return(false, ['code' => 'invoice-has-invalid-transactions']);
        }

        // The last invoice transaction must have been entered by the gateway.
        if ($latestTransac['gateway'] !== Config::constant('name')) {
            return Response::return(false, ['code' => 'invoice-has-wrong-payment-method']);
        }

        try {
            $pixTaxId = PixTaxId::fromWhmcsTransId($latestTransac['transid'], $invoiceId);
            $consultPixResponse = $this->pixApiRepository->consultPix($pixTaxId);
        } catch (Throwable $e) {
            Logger::log(
                'PixController: erro ao consultar Pix da fatura',
                [
                    'invoiceId' => $invoiceId,
                    'transid' => $latestTransac['transid']
                ],
                ['error' => $e->getMessage()]
            );

            return Response::return(false, ['code' => 'could-not-consult-pix']);
        }

        return Response::return(true, $consultPixResponse);
    }
}
