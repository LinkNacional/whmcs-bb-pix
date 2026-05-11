<?php

/**
 * This file handle requests that come primary from the module configuration pages.
 *
 * These requests are made by JS fetch(). They are primary located in /resources/js
 * and others are located directly in a .tpl file.
 */

use Lkn\BBPix\App\Pix\Controllers\ApiController;
use Lkn\BBPix\App\Pix\Controllers\DiscountController;
use Lkn\BBPix\App\Pix\Exceptions\Journey4PublicException;
use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\PixController;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use Lkn\BBPix\App\Pix\Services\DiscountService;
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

$isWebhookNotRegisteredError = static function (array $result): bool {
    if (($result['success'] ?? false) === true) {
        return false;
    }

    $type = (string) ($result['data']['type'] ?? '');
    $detail = (string) ($result['data']['detail'] ?? '');

    if (
        str_contains($type, 'WebhookRecNaoEncontrado') ||
        str_contains($type, 'WebhookCobRNaoEncontrado')
    ) {
        return true;
    }

    return str_contains($type, 'OperacaoInvalida') && (
        str_contains($detail, 'Não há webhook cadastrado') ||
        str_contains($detail, 'Nao ha webhook cadastrado')
    );
};

$isRecAlreadyCancelledError = static function (array $result): bool {
    if (($result['success'] ?? false) === true) {
        return false;
    }

    $type = strtolower((string) ($result['data']['type'] ?? ''));
    $title = strtolower((string) ($result['data']['title'] ?? ''));
    $detail = strtolower((string) ($result['data']['detail'] ?? ''));
    $combined = $type . ' ' . $title . ' ' . $detail;

    return str_contains($combined, 'cancelad') || str_contains($combined, 'revogad');
};

$extractAdminCsrfContext = static function (object $request): array {
    $bodyCsrfToken = trim((string) ($request->csrfToken ?? ''));
    $bodyToken = trim((string) ($request->token ?? ''));
    $headerToken = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    $requestToken = $bodyCsrfToken !== ''
        ? $bodyCsrfToken
        : ($bodyToken !== '' ? $bodyToken : $headerToken);

    $requestTokenSource = $bodyCsrfToken !== ''
        ? 'body.csrfToken'
        : ($bodyToken !== '' ? 'body.token' : ($headerToken !== '' ? 'header.x-csrf-token' : 'none'));

    $sessionCandidates = array_values(array_filter([
        trim((string) ($_SESSION['token'] ?? '')),
        trim((string) ($_SESSION['tkval'] ?? '')),
        trim((string) ($_SESSION['csrfToken'] ?? '')),
    ], static fn (string $value): bool => $value !== ''));

    $runtimeCandidates = [];

    if (function_exists('generate_token')) {
        try {
            $runtimePlainToken = trim((string) generate_token('plain'));

            if ($runtimePlainToken !== '') {
                $runtimeCandidates[] = $runtimePlainToken;
            }

            if (empty($runtimeCandidates)) {
                $runtimeDefaultToken = trim((string) generate_token());

                if ($runtimeDefaultToken !== '') {
                    $runtimeCandidates[] = $runtimeDefaultToken;
                }
            }
        } catch (Throwable) {
            // Runtime candidate is optional and should never block request handling.
        }
    }

    $nativeCandidates = array_values(array_unique(array_merge($sessionCandidates, $runtimeCandidates)));

    $legacyToken = trim((string) ($_SESSION['lkn-bb-pix-admin'] ?? ''));

    $sessionMatch = false;
    foreach ($sessionCandidates as $sessionToken) {
        if ($requestToken !== '' && hash_equals($sessionToken, $requestToken)) {
            $sessionMatch = true;
            break;
        }
    }

    $runtimeMatch = false;
    foreach ($runtimeCandidates as $runtimeToken) {
        if ($requestToken !== '' && hash_equals($runtimeToken, $requestToken)) {
            $runtimeMatch = true;
            break;
        }
    }

    $nativeMatch = $sessionMatch || $runtimeMatch;

    $legacyMatch = $legacyToken !== '' && $requestToken !== '' && hash_equals($legacyToken, $requestToken);

    return [
        'requestToken' => $requestToken,
        'requestTokenSource' => $requestTokenSource,
        'requestTokenLength' => strlen($requestToken),
        'sessionCandidatesCount' => count($sessionCandidates),
        'runtimeCandidatesCount' => count($runtimeCandidates),
        'nativeCandidatesCount' => count($nativeCandidates),
        'legacyTokenPresent' => $legacyToken !== '',
        'sessionMatch' => $sessionMatch,
        'runtimeMatch' => $runtimeMatch,
        'nativeMatch' => $nativeMatch,
        'legacyMatch' => $legacyMatch,
        // TEMP: keep short hash fingerprint to diagnose session/token mismatch without exposing secret.
        'requestTokenFingerprint' => $requestToken !== '' ? substr(hash('sha256', $requestToken), 0, 12) : '',
    ];
};

$isValidAdminCsrfToken = static function (array $csrfContext): bool {
    if (($csrfContext['requestToken'] ?? '') === '') {
        return false;
    }

    return (bool) (($csrfContext['nativeMatch'] ?? false) || ($csrfContext['legacyMatch'] ?? false));
};

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

    case 'cancel-auto-auth':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado.']);
        }

        $csrfContext = $extractAdminCsrfContext($request);

        if (!$isValidAdminCsrfToken($csrfContext)) {
            Logger::log(
                'Falha de CSRF no cancel-auto-auth (temporário)',
                [
                    'action' => 'cancel-auto-auth',
                    'adminId' => (int) ($_SESSION['adminid'] ?? 0),
                    'clientId' => (int) ($request->clientId ?? 0),
                    'idRec' => trim((string) ($request->idRec ?? '')),
                    'sessionIdPresent' => session_id() !== '',
                    'csrf' => [
                        'source' => (string) ($csrfContext['requestTokenSource'] ?? 'none'),
                        'requestTokenLength' => (int) ($csrfContext['requestTokenLength'] ?? 0),
                        'requestTokenFingerprint' => (string) ($csrfContext['requestTokenFingerprint'] ?? ''),
                        'sessionCandidatesCount' => (int) ($csrfContext['sessionCandidatesCount'] ?? 0),
                        'runtimeCandidatesCount' => (int) ($csrfContext['runtimeCandidatesCount'] ?? 0),
                        'nativeCandidatesCount' => (int) ($csrfContext['nativeCandidatesCount'] ?? 0),
                        'legacyTokenPresent' => (bool) ($csrfContext['legacyTokenPresent'] ?? false),
                        'sessionMatch' => (bool) ($csrfContext['sessionMatch'] ?? false),
                        'runtimeMatch' => (bool) ($csrfContext['runtimeMatch'] ?? false),
                        'nativeMatch' => (bool) ($csrfContext['nativeMatch'] ?? false),
                        'legacyMatch' => (bool) ($csrfContext['legacyMatch'] ?? false),
                    ],
                ]
            );

            http_response_code(403);
            Response::api(false, ['error' => 'Token CSRF inválido.']);
        }

        $idRec = trim((string) ($request->idRec ?? ''));
        $clientId = (int) ($request->clientId ?? 0);

        if ($idRec === '' || !preg_match('/^[A-Za-z0-9]{1,35}$/', $idRec)) {
            http_response_code(422);
            Response::api(false, ['error' => 'idRec inválido.']);
        }

        try {
            $authRow = Capsule::table('mod_lknbbpix_auths')
                ->where('id_rec', $idRec)
                ->first(['client_id', 'id_rec', 'status']);

            if (!$authRow) {
                http_response_code(404);
                Response::api(false, ['error' => 'Autorização não encontrada.']);
            }

            if ($clientId > 0 && (int) $authRow->client_id !== $clientId) {
                http_response_code(403);
                Response::api(false, ['error' => 'Autorização não pertence ao cliente informado.']);
            }

            $currentStatus = strtoupper(trim((string) ($authRow->status ?? '')));

            if (in_array($currentStatus, ['CANCELADA', 'REVOGADA', 'REJEITADA'], true)) {
                http_response_code(200);
                Response::api(true, [
                    'idRec' => $idRec,
                    'status' => $currentStatus,
                    'message' => 'Autorização já estava finalizada.',
                ]);
            }

            if (!in_array($currentStatus, ['CRIADA', 'APROVADA'], true)) {
                http_response_code(409);
                Response::api(false, ['error' => 'Status atual não permite cancelamento manual.']);
            }

            $bbCancelResponse = (new PixAutoRepository())->cancelarRecorrencia($idRec);
            $bbSuccess = (bool) ($bbCancelResponse['success'] ?? false);

            if (!$bbSuccess && !$isRecAlreadyCancelledError($bbCancelResponse)) {
                Logger::log(
                    'Cancelar autorização Pix Automático no admin',
                    [
                        'action' => 'cancel-auto-auth',
                        'idRec' => $idRec,
                        'clientId' => (int) $authRow->client_id,
                        'adminId' => (int) ($_SESSION['adminid'] ?? 0),
                        'currentStatus' => $currentStatus,
                    ],
                    ['bbResponse' => $bbCancelResponse]
                );

                http_response_code(502);
                Response::api(false, ['error' => 'Banco do Brasil recusou o cancelamento da autorização.']);
            }

            $updateResponse = (new AuthRepository())->atualizarStatusPorIdRec($idRec, 'CANCELADA');

            if (!($updateResponse['success'] ?? false)) {
                Logger::log(
                    'Falha ao persistir cancelamento de autorização Pix Automático',
                    [
                        'action' => 'cancel-auto-auth',
                        'idRec' => $idRec,
                        'clientId' => (int) $authRow->client_id,
                        'adminId' => (int) ($_SESSION['adminid'] ?? 0),
                        'currentStatus' => $currentStatus,
                    ],
                    ['updateResponse' => $updateResponse, 'bbResponse' => $bbCancelResponse]
                );

                http_response_code(500);
                Response::api(false, ['error' => 'Falha ao atualizar status local da autorização.']);
            }

            Logger::log(
                'Autorização Pix Automático cancelada no admin',
                [
                    'action' => 'cancel-auto-auth',
                    'idRec' => $idRec,
                    'clientId' => (int) $authRow->client_id,
                    'adminId' => (int) ($_SESSION['adminid'] ?? 0),
                    'fromStatus' => $currentStatus,
                    'toStatus' => 'CANCELADA',
                ],
                ['bbResponse' => $bbCancelResponse, 'updateResponse' => $updateResponse]
            );

            http_response_code(200);
            Response::api(true, [
                'idRec' => $idRec,
                'status' => 'CANCELADA',
                'message' => 'Autorização cancelada com sucesso.',
                'requiresRefresh' => false,
            ]);
        } catch (Throwable $e) {
            Logger::log(
                'Falha no cancelamento de autorização Pix Automático no admin',
                [
                    'action' => 'cancel-auto-auth',
                    'idRec' => $idRec,
                    'clientId' => $clientId,
                    'adminId' => (int) ($_SESSION['adminid'] ?? 0),
                ],
                ['error' => $e->getMessage(), 'exception' => get_class($e)]
            );

            http_response_code(500);
            Response::api(false, ['error' => 'Erro interno ao cancelar autorização.']);
        }

        break;

    case 'get-webhooks':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado.']);
        }

        try {
            $repo = new PixAutoRepository();
            $recResult  = $repo->consultarWebhookRec();
            $cobrResult = $repo->consultarWebhookCobr();

            http_response_code(200);
            Response::api(true, ['rec' => $recResult, 'cobr' => $cobrResult]);
        } catch (Throwable $e) {
            Logger::log(
                'Falha ao consultar webhooks na API do BB',
                ['action' => 'get-webhooks'],
                [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]
            );

            http_response_code(500);
            Response::api(false, ['error' => $e->getMessage()]);
        }

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

            $recOk = (bool) ($recResult['success'] ?? false) || $isWebhookNotRegisteredError($recResult);
            $cobrOk = (bool) ($cobrResult['success'] ?? false) || $isWebhookNotRegisteredError($cobrResult);
            $overallSuccess = $recOk && $cobrOk;

            $warnings = [];

            if (!$recOk) {
                $warnings[] = 'Falha ao remover webhookrec.';
            }

            if (!$cobrOk) {
                $warnings[] = 'Falha ao remover webhookcobr.';
            }

            if (!$overallSuccess) {
                Logger::log(
                    'Falha parcial ao remover webhooks na API do BB',
                    ['action' => 'remove-webhooks'],
                    ['rec' => $recResult, 'cobr' => $cobrResult]
                );
            }

            http_response_code(200);
            Response::api($overallSuccess, [
                'rec' => $recResult,
                'cobr' => $cobrResult,
                'warnings' => $warnings,
            ]);
        } catch (Throwable $e) {
            Logger::log(
                'Falha ao remover webhooks na API do BB',
                ['action' => 'remove-webhooks'],
                [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]
            );

            http_response_code(500);
            Response::api(false, ['error' => $e->getMessage()]);
        }

        break;

    case 'register-webhooks':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            http_response_code(403);
            Response::api(false, ['error' => 'Acesso negado.']);
        }

        $systemUrl = (string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value');

        if (!str_starts_with($systemUrl, 'https://')) {
            http_response_code(422);
            Response::api(false, ['error' => 'SystemURL não utiliza HTTPS. O Banco do Brasil exige URL segura para registrar webhooks.']);
        }

        try {
            $repo = new PixAutoRepository();
            $recResult  = $repo->registrarWebhookRec();
            $cobrResult = $repo->registrarWebhookCobr();

            $recOk = (bool) ($recResult['success'] ?? false);
            $cobrOk = (bool) ($cobrResult['success'] ?? false);
            $overallSuccess = $recOk && $cobrOk;

            $warnings = [];

            if (!$recOk) {
                $warnings[] = 'Falha ao registrar webhookrec.';
            }

            if (!$cobrOk) {
                $warnings[] = 'Falha ao registrar webhookcobr.';
            }

            if (!$overallSuccess) {
                Logger::log(
                    'Falha parcial ao registrar webhooks na API do BB',
                    ['action' => 'register-webhooks', 'systemUrl' => $systemUrl],
                    ['rec' => $recResult, 'cobr' => $cobrResult]
                );
            }

            http_response_code(200);
            Response::api($overallSuccess, [
                'rec' => $recResult,
                'cobr' => $cobrResult,
                'warnings' => $warnings,
            ]);
        } catch (Throwable $e) {
            Logger::log(
                'Falha ao registrar webhooks na API do BB',
                ['action' => 'register-webhooks', 'systemUrl' => $systemUrl],
                [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]
            );

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
            $dueDate = date('Y-m-d', strtotime((string) $invoice->duedate));
            $dueDay = (int) date('d', strtotime((string) $invoice->duedate));

            if (!Config::setting('enable_pix_automatic')) {
                http_response_code(409);
                Response::api(false, ['error' => 'Pix Automático está desabilitado nas configurações do gateway.']);
            }

            $invoiceOrigin = (new InvoiceOriginHelper())->classify($invoiceId);

            $decision = $invoiceOrigin === InvoiceOriginHelper::MANUAL_TRADICIONAL
                ? DecisionService::MANUAL_TRADICIONAL
                : (new DecisionService())->evaluate($invoiceOrigin, $clientId, $dueDay, $dueDate);

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

            $invoiceApiData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

            if (($invoiceApiData['result'] ?? '') !== 'success') {
                Logger::log(
                    'Falha ao consultar fatura no load-journey4-qrcode',
                    ['invoiceId' => $invoiceId],
                    $invoiceApiData
                );
            }

            $invoiceValue = (float) ($invoiceApiData['balance'] ?? 0.0);
            $pixValue = (float) (new DiscountService($invoiceId))->calculate();
            $discountPercentage = null;
            $taxAmount = null;

            if ($pixValue < $invoiceValue && $invoiceValue > 0.0) {
                $discountAmount = $pixValue - $invoiceValue;
                $discountPercentage = abs(($discountAmount / $invoiceValue) * 100);
                $discountPercentage = number_format($discountPercentage, 0, ',', '.');
            }

            if ($pixValue > $invoiceValue) {
                $taxAmount = $pixValue - $invoiceValue;
                $taxAmount = number_format($taxAmount, 2, ',', '.');
            }

            http_response_code(200);
            Response::api(true, [
                'qrCodeText' => $emv,
                'qrCodeBase64' => $qrCodeBase64,
                'idRec' => $journeyResponse['data']['idRec'] ?? null,
                'cached' => $journeyResponse['data']['cached'] ?? false,
                'invoiceValue' => $invoiceValue,
                'pixValue' => $pixValue,
                'discountPercentage' => $discountPercentage,
                'taxAmount' => $taxAmount,
            ]);
        } catch (Journey4PublicException $e) {
            Logger::log(
                'Erro de validação ao carregar Jornada 4 via API',
                [
                    'invoiceId' => $invoiceId,
                    'step' => $e->getStep(),
                    'statusCode' => $e->getStatusCode(),
                ],
                ['error' => $e->getMessage()]
            );

            http_response_code($e->getStatusCode());
            Response::api(false, ['error' => $e->getMessage()]);
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
