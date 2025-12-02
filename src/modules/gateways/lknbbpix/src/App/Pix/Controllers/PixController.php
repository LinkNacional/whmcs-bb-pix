<?php

namespace Lkn\BBPix\App\Pix\Controllers;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Services\CancelPixService;
use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\App\Pix\Services\CreatePixService;
use Lkn\BBPix\App\Pix\Services\CreatePixServiceLate;
use Lkn\BBPix\App\Pix\Services\InvoiceHasActivePixService;
use Lkn\BBPix\App\Pix\Services\RefundPixService;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepositoryLate;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Pix;
use Lkn\BBPix\Helpers\Response;
use Throwable;
use Exception;
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
                // Valid PIX with correct value already exists – reuse it
                return Response::return(true, [
                    'pixCode' => $invoiceHasActivePixResponse['pixCopiaECola'],
                    'pixQrCodeBase64' => $this->qrCodeTextGenerator->render($invoiceHasActivePixResponse['pixCopiaECola']),
                    'pixValue' => $invoiceHasActivePixResponse['pixValue']
                ]);
            }

            // No active PIX exists or value changed – cancel (if any) and create new one
            $this->cancelExistingPix($createPixRequest['invoiceId']);

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
        } catch (Throwable $e) {
            Logger::log($e->exceptionCode->label(), [$e]);

            return Response::return(false, ['error' => $e->exceptionCode->label()]);
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
                // Check expiration for COBV based on due date + validity period after due date
                $dueDate = $consultPixResponse['data']['calendario']['dataDeVencimento'];
                $validityAfterDueDate = $consultPixResponse['data']['calendario']['validadeAposVencimento'];
                
                if (Pix::isExpired($dueDate, $validityAfterDueDate, 'cobv')) {
                    Response::api(false, ['code' => 'pix-is-active-but-expired']);
                    return;
                }
                
                Response::api(false, ['code' => 'pix-still-active']);
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

        $latestTransac = end($transacs);

        // The last invoice transaction must have been entered by the gateway.
        if ($latestTransac['gateway'] !== Config::constant('name')) {
            return Response::return(false, ['code' => 'invoice-has-wrong-payment-method']);
        }

        $pixTaxId = PixTaxId::fromWhmcsTransId($latestTransac['transid'], $invoiceId);

        $consultPixResponse = $this->pixApiRepository->consultPix($pixTaxId);

        return Response::return(true, $consultPixResponse);
    }

    /**
     * Cancels existing active PIX before creating a new one
     * 
     * @param int $invoiceId
     * @return void
     */
    private function cancelExistingPix(int $invoiceId): void
    {
        try {
            // Get invoice transactions
            $invoiceTrans = Invoice::getTransactions($invoiceId)['transactions']['transaction'];
            
            if (empty($invoiceTrans)) {
                return;
            }

            $lastInvoiceTrans = end($invoiceTrans);

            // Check if it's a pending PIX from our gateway
            if ($lastInvoiceTrans['gateway'] !== Config::constant('name') || 
                !str_starts_with($lastInvoiceTrans['transid'], 'CRIADOx')) {
                return;
            }

            // Create cancellation service
            $cancelPixService = new CancelPixService($this->pixApiRepository);
            
            // Cancelar PIX na API
            $cancelResponse = $cancelPixService->run([
                'transacId' => $lastInvoiceTrans['transid'],
                'invoiceId' => $invoiceId
            ]);

            // Register cancellation transaction in invoice
            $clientId = Invoice::getClientId($invoiceId);
            $cancelTransacId = $cancelResponse['cancelTransId'];
            
            $addTransacResponse = Invoice::addTransac(
                $clientId,
                $invoiceId,
                $cancelTransacId,
                0.0, // valor 0 para cancelamento
                '', // data atual
                'PIX anterior cancelado automaticamente para gerar novo PIX',
                0.0 // taxa 0 para cancelamento
            );

            Logger::log('Cancelar PIX anterior ao gerar novo', [
                'status' => $addTransacResponse['result'] === 'success' ? 'PIX cancelado e transação registrada' : 'PIX cancelado mas erro ao registrar transação',
                'invoiceId' => $invoiceId,
                'transacId' => $lastInvoiceTrans['transid'],
                'cancelTransacId' => $cancelTransacId,
                'cancelResponse' => $cancelResponse,
                'addTransacResponse' => $addTransacResponse
            ]);

        } catch (PixException $e) {
            Logger::log('Cancelar PIX anterior ao gerar novo', [
                'status' => 'Erro ao cancelar PIX anterior',
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage(),
                'errorCode' => $e->exceptionCode->name
            ]);
            
            // We won't interrupt the creation of the new PIX due to cancellation error
            // Just log the error and continue
        } catch (Exception $e) {
            Logger::log('Cancelar PIX anterior ao gerar novo', [
                'status' => 'Erro ao cancelar PIX anterior',
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
