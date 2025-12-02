<?php

use Lkn\BBPix\App\Pix\Controllers\AutoPixController;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../../../init.php';

$action = $_GET['action'] ?? 'index';
$controller = new AutoPixController();

if ($action === 'index') {
    $clientId = (int) $_SESSION['uid'];
    $serviceId = isset($_GET['serviceid']) ? (int) $_GET['serviceid'] : null;
    $domainId = isset($_GET['domainid']) ? (int) $_GET['domainid'] : null;

    echo $controller->index($clientId, $serviceId, $domainId);
    exit;
}

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    echo json_encode($controller->start($input));
    exit;
}

if ($action === 'recover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    echo json_encode($controller->recover($input));
    exit;
}

if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $consentId = (string) ($input['psp_consent_id'] ?? '');
    echo json_encode($controller->revoke($consentId));
    exit;
}

http_response_code(404); 