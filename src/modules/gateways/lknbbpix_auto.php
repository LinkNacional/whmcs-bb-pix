<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Resilient autoloader: try module vendor first, then WHMCS root vendor
$moduleVendor = __DIR__ . '/lknbbpix/vendor/autoload.php';
$rootVendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($moduleVendor)) {
    require_once $moduleVendor;
} elseif (file_exists($rootVendor)) {
    require_once $rootVendor;
}

// PSR-4 fallback for module classes when Composer autoload is unavailable
if (!class_exists('Lkn\\BBPix\\Helpers\\Logger')) {
    spl_autoload_register(function ($class) {
        $prefix = 'Lkn\\BBPix\\';
        $baseDir = __DIR__ . '/lknbbpix/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

require_once dirname(__DIR__, 2) . '/includes/gatewayfunctions.php';

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\App\Pix\Services\AutoPix\RevokeConsentService;

function lknbbpix_auto_MetaData()
{
    return [
        'DisplayName' => 'Pix Automático - Banco do Brasil (oculto)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

// Reuse: this gateway is hidden and delegates all configs to lknbbpix
function lknbbpix_auto_config($params)
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Pix Automático - Banco do Brasil'
        ],
        '' => [
            'Description' => '<div style="padding:8px 0; font-style: italic;">Este gateway reutiliza as configurações do lknbbpix e não possui exibição no checkout.</div>'
        ]
    ];
}

function lknbbpix_auto_link($params)
{
    // Hidden: do not render anything on invoice/checkout
    return '';
}

// Subscription Management: allow WHMCS to request cancellation of automatic charges/consent
// https://developers.whmcs.com/payment-gateways/subscription-management/
function lknbbpix_auto_cancelSubscription($params)
{
    try {
        // Expect a subscription identifier to be stored in tblhosting.subscriptionid
        // For Pix Automático, this can be the consentId at BB (psp_consent_id)
        $subscriptionId = $params['subscriptionid'] ?? '';

        if ($subscriptionId === '') {
            return ['status' => 'error', 'rawdata' => ['message' => 'subscriptionid vazio']];
        }

        Logger::log('AutoPix cancel subscription requested', [
            'serviceid' => $params['serviceid'] ?? null,
            'subscriptionid' => $subscriptionId
        ]);

        $result = (new RevokeConsentService())->run($subscriptionId);

        if ($result['success'] === true) {
            return ['status' => 'success'];
        }

        return ['status' => 'error', 'rawdata' => ['message' => $result['error'] ?? 'revocation failed']];
    } catch (Throwable $e) {
        Logger::log('AutoPix cancel subscription error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return ['status' => 'error', 'rawdata' => ['message' => $e->getMessage()]];
    }
}

