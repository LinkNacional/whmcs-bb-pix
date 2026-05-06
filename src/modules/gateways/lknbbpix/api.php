<?php

/**
 * This file handle requests that come primary from the module configuration pages.
 *
 * These requests are made by JS fetch(). They are primary located in /resources/js
 * and others are located directly in a .tpl file.
 */

use Lkn\BBPix\App\Pix\Controllers\ApiController;
use Lkn\BBPix\App\Pix\Controllers\DiscountController;
use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\PixController;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use Lkn\BBPix\App\Pix\Services\LoadJourney4PixService;
use Lkn\BBPix\App\Pix\Services\PixTxidService;
use Lkn\BBPix\Helpers\Auth;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\InvoiceOriginHelper;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\PayerResolver;
use Lkn\BBPix\Helpers\Response;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/vendor/autoload.php';

$request = json_decode(file_get_contents('php://input')) ?? (object) ($_POST);

header('Content-Type: application/json;');

$authState = new CurrentUser();

switch ($request->action) {
    // The form request this endpoint to check if the invoice is paid each 18 seconds.
    case 'check-invoice-status':
        if (!isset($request->token) || $request->token !== $_SESSION['lkn-bb-pix']) {
            exit('token invalido.');
        }

        $invoiceId = $request->invoiceId;

        $isInvoicePaid = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->where('status', 'Paid')
            ->exists();

        http_response_code(200);
        Response::api(true, ['isInvoicePaid' => $isInvoicePaid]);

        break;

    case 'manual-payment-confirmation':

        if (!($authState->admin() || $authState->client())) {
            exit;
        }

        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        http_response_code(200);
        return (new PixController($cobType))->checkAndConfirmInvoicePayment($request->invoiceId);

        break;

    case 'save-discount':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            exit;
        }

        http_response_code(200);
        (new DiscountController())->createOrUpdate($request->productId, $request->percentage);

        break;

    case 'delete-discount':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            exit;
        }

        http_response_code(200);
        (new DiscountController())->delete($request->productId);

        break;

    case 'remove-webhooks':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado.']);
        }

        try {
            $repo = new PixAutoRepository();
            $recResult  = $repo->removerWebhookRec();
            $cobrResult = $repo->removerWebhookCobr();

            http_response_code(200);
            Response::api(true, ['rec' => $recResult, 'cobr' => $cobrResult]);
        } catch (Throwable $e) {
            http_response_code(500);
            Response::api(false, ['error' => $e->getMessage()]);
        }

        break;

    case 'load-journey4-qrcode':
        if (!($authState->admin() || $authState->client())) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado.']);
        }

        if (!isset($request->token) || $request->token !== ($_SESSION['lkn-bb-pix'] ?? '')) {
            http_response_code(403);
            Response::api(false, ['error' => 'Token inválido.']);
        }

        $invoiceId = (int) ($request->invoiceId ?? 0);

        if ($invoiceId <= 0) {
            http_response_code(422);
            Response::api(false, ['error' => 'invoiceId inválido.']);
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['id', 'userid', 'paymentmethod', 'duedate']);

        if (empty($invoice) || $invoice->paymentmethod !== 'lknbbpix') {
            http_response_code(403);
            Response::api(false, ['error' => 'Fatura inválida para este gateway.']);
        }

        $sessionClientId = (int) ($_SESSION['uid'] ?? 0);

        if (!$authState->admin() && $sessionClientId > 0 && $sessionClientId !== (int) $invoice->userid) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado à fatura.']);
        }

        try {
            $clientId = (int) $invoice->userid;
            $dueDay = (int) date('d', strtotime((string) $invoice->duedate));

            if (!Config::setting('enable_pix_automatic')) {
                http_response_code(409);
                Response::api(false, ['error' => 'Pix Automático está desabilitado nas configurações do gateway.']);
            }

            $invoiceOrigin = (new InvoiceOriginHelper())->classify($invoiceId);

            $decision = $invoiceOrigin === InvoiceOriginHelper::MANUAL_TRADICIONAL
                ? DecisionService::MANUAL_TRADICIONAL
                : (new DecisionService())->evaluate($invoiceOrigin, $clientId, $dueDay);

            if ($decision !== DecisionService::JORNADA4) {
                http_response_code(409);
                Response::api(false, ['error' => 'A fatura não está no fluxo Jornada 4.', 'decision' => $decision]);
            }

            $txid = PixTxidService::generateForInvoice($invoiceId);
            $payerResolution = PayerResolver::resolveForClientId($clientId, true);

            if (!($payerResolution['success'] ?? false)) {
                http_response_code(422);
                Response::api(false, $payerResolution['data'] ?? PayerResolver::profileUpdateRequired()['data']);
            }

            $journeyResponse = (new LoadJourney4PixService())->run(
                $invoiceId,
                $clientId,
                $dueDay,
                $txid,
                $payerResolution['data']
            );

            if (!($journeyResponse['success'] ?? false)) {
                http_response_code(500);
                Response::api(false, ['error' => 'Falha ao carregar proposta do Pix Automático.']);
            }

            $emv = (string) ($journeyResponse['data']['emv'] ?? '');

            if ($emv === '') {
                http_response_code(500);
                Response::api(false, ['error' => 'EMV não retornado pela jornada 4.']);
            }

            $qrOptions = new QROptions();
            $qrOptions->eccLevel = QRCode::ECC_M;
            $qrOptions->outputType = QRCode::OUTPUT_IMAGE_PNG;
            $qrOptions->pngCompression = 0;

            $qrCodeBase64 = (new QRCode($qrOptions))->render($emv);

            http_response_code(200);
            Response::api(true, [
                'qrCodeText' => $emv,
                'qrCodeBase64' => $qrCodeBase64,
                'idRec' => $journeyResponse['data']['idRec'] ?? null,
                'cached' => $journeyResponse['data']['cached'] ?? false,
            ]);
        } catch (Throwable $e) {
            Logger::log(
                'Erro ao carregar Jornada 4 via API',
                ['invoiceId' => $invoiceId],
                ['error' => $e->getMessage()]
            );

            http_response_code(500);
            Response::api(false, ['error' => 'Erro interno ao gerar proposta do Pix Automático.']);
        }

        break;

    case 'update-private-cert':
    case 'update-public-cert':
        if (!$authState->admin()) {
            exit;
        }

        $certType = $request->action === 'update-private-cert' ? 'private' : 'public';
        http_response_code(200);
        (new ApiController())->updateCert($certType, $_FILES['cert']);

        break;

    default:
        http_response_code(404);

        break;
}
