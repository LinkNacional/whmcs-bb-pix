<?php

/**
 * Handles webhook call for automatic payment confirmation.
 *
 * @see Webhook documentation https://apoio.developers.bb.com.br/referency/post/6125045d8378f10012877468
 * @see Pay Pix in sandbox: https://apoio.developers.bb.com.br/referency/post/61bcdd19b6164800123d7654
 */

use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\Helpers\Logger;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$rawPayload = file_get_contents('php://input');

http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$request = json_decode((string) $rawPayload);

Logger::log('webhook', ['request' => $request]);

if (!isset($request->pix)) {
    Logger::log('webhook: requisição inválida', ['request' => $request]);

    exit;
}

$pix = $request->pix[0];

$apiTxId = $pix->txid;
$paidAmount = $pix->valor;
$paymentDate = $pix->horario;
$endToEndId = $pix->endToEndId;

try {
    $confirmPaymentService = new ConfirmPaymentService();

    $confirmPaymentService->run($apiTxId, $paidAmount, $paymentDate, $endToEndId);
} catch (Throwable $e) {
    Logger::log(
        'webhook: erro interno no processamento',
        [
            'rawPayload' => $rawPayload,
            'apiTxId' => $apiTxId,
            'endToEndId' => $endToEndId,
        ],
        ['error' => $e->getMessage()]
    );
}
