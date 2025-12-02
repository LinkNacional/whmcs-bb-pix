<?php

/**
 * Handles webhook call for automatic payment confirmation.
 *
 * @see Webhook documentation https://apoio.developers.bb.com.br/referency/post/6125045d8378f10012877468
 * @see Pay Pix in sandbox: https://apoio.developers.bb.com.br/referency/post/61bcdd19b6164800123d7654
 */

use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\App\Pix\Repositories\WebhookEventRepository;
use Lkn\BBPix\App\Pix\Services\AutoPix\HandleWebhookService;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

function lknbbpix_early_ack(string $body = 'ok'): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain');
        header('Connection: close');
        http_response_code(200);
    }

    ignore_user_abort(true);

    if (ob_get_level() === 0) {
        ob_start();
    }

    echo $body;
    $size = ob_get_length();
    if (!headers_sent()) {
        header('Content-Length: ' . $size);
    }
    ob_end_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

$type = $_GET['type'] ?? '';

$rawBody = file_get_contents('php://input');

if ($type === 'autopix') {
    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        Logger::log('autopix webhook: invalid payload', ['raw' => $rawBody]);
        lknbbpix_early_ack('ok');
        exit;
    }

    $eventId = $_SERVER['HTTP_X_EVENT_ID'] ?? ($payload['eventId'] ?? hash('sha256', $rawBody));
    $eventType = $payload['type'] ?? 'unknown';

    $webhookRepo = new WebhookEventRepository();

    if ($webhookRepo->isProcessed($eventId)) {
        lknbbpix_early_ack('duplicate');
        exit;
    }

    $webhookRepo->markReceived($eventId, $eventType, $payload);

    // Early ACK to Banco do Brasil
    lknbbpix_early_ack('ok');

    // Continue processing after ACK
    (new HandleWebhookService())->run($payload);

    $webhookRepo->markProcessed($eventId);

    exit;
}

$request = json_decode($rawBody);

Logger::log('webhook', ['request' => $request]);

if (!isset($request->pix)) {
    Logger::log('webhook: requisição inválida', ['request' => $request]);
    lknbbpix_early_ack('ok');
    exit;
}

// Early ACK for standard cob/cobv path
lknbbpix_early_ack('ok');

$pix = $request->pix[0];

$apiTxId = $pix->txid;
$paidAmount = $pix->valor;
$paymentDate = $pix->horario;
$endToEndId = $pix->endToEndId;

$confirmPaymentService = new ConfirmPaymentService();

$confirmPaymentService->run($apiTxId, $paidAmount, $paymentDate, $endToEndId);
