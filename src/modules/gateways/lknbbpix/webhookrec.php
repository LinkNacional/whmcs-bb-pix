<?php

use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\ParserHelper;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/vendor/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$rawPayload = file_get_contents('php://input');

if ($rawPayload === false) {
    http_response_code(500);
    exit;
}

$payload = json_decode($rawPayload, true);

http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    if (!is_array($payload)) {
        Logger::log('webhookrec: payload inválido', ['rawPayload' => $rawPayload]);
        exit;
    }

    $idRec = ParserHelper::findFirstValue($payload, ['idRec', 'id_rec']);
    $status = strtoupper(ParserHelper::findFirstValue($payload, ['status', 'situacao', 'estado']));

    if ($idRec === '' || $status === '') {
        Logger::log('webhookrec: dados insuficientes', ['payload' => $payload]);
        exit;
    }

    $allowedStatuses = ['CRIADA', 'APROVADA', 'REJEITADA', 'CANCELADA', 'REVOGADA'];

    if (!in_array($status, $allowedStatuses, true)) {
        Logger::log('webhookrec: status desconhecido', ['idRec' => $idRec, 'status' => $status, 'payload' => $payload]);
        exit;
    }

    $updateResponse = (new AuthRepository())->atualizarStatusPorIdRec($idRec, $status);

    Logger::log(
        'webhookrec: status processado',
        ['idRec' => $idRec, 'status' => $status],
        $updateResponse
    );
} catch (Throwable $e) {
    Logger::log(
        'webhookrec: erro interno',
        ['rawPayload' => $rawPayload],
        ['error' => $e->getMessage()]
    );
}
