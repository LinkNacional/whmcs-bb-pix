<?php

use Lkn\BBPix\App\Pix\Controllers\DiscountController;
use Lkn\BBPix\App\Pix\PixAutoRepository;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\App\Pix\Services\InvoiceNoteService;
use Lkn\BBPix\App\Pix\Services\IsInvoicePixPaidService;
use Lkn\BBPix\App\Pix\Services\PixTxidService;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Services\ScheduleAutomaticChargeService;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\InvoiceOriginHelper;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\View;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/gateways/lknbbpix/vendor/autoload.php';

add_hook('AdminInvoicesControlsOutput', 1, function (array $hookParams): string {
    if ($hookParams['paymentmethod'] !== 'lknbbpix') {
        return '';
    }

    $isInvoiceUnpaid = Capsule::table('tblinvoices')
        ->where('id', $hookParams['invoiceid'])
        ->where('status', 'Unpaid')
        ->exists();

    $transactions = localAPI('GetTransactions', ['invoiceid' => $hookParams['invoiceid']]);

    $transacs = $transactions['transactions']['transaction'] ?? [];

    $latestTransac = end($transacs);

    // The last invoice transaction must have been entered by the gateway.
    if ($latestTransac['gateway'] !== Config::constant('name')) {
        return '';
    }

    if (!$isInvoiceUnpaid) {
        return '';
    }

    return View::render(
        'admin_invoices_controls_output.index',
        [
            'enable_admin_manual_check' => Config::setting('enable_admin_manual_check') ?? false
        ]
    );
});

add_hook('AdminAreaHeaderOutput', 1, function (array $vars) {
    if (str_contains($_SERVER['PHP_SELF'], 'configgateways.php')) {
        return (new DiscountController())->index();
    }
});

add_hook('InvoiceCancelled', 1, function ($vars): void {
    $invoiceId = $vars['invoiceid'];

    if (!Config::setting('enable_pix_when_invoice_cancel')) {
        return;
    }

    $invoiceTrans = Invoice::getTransactions($invoiceId)['transactions']['transaction'];

    if (empty($invoiceTrans)) {
        return;
    }

    $lastInvoiceTrans = end($invoiceTrans);

    if ($lastInvoiceTrans['gateway'] !== 'lknbbpix' || !str_starts_with($lastInvoiceTrans['transid'], 'CRIADOx')) {
        Logger::log('Verificar se fatura está paga antes de cancelar', 'A última transação da fatura não é um Pix pendente.');

        return;
    }

    $isPixPaidResponse = (new IsInvoicePixPaidService())->run($invoiceId, $lastInvoiceTrans['transid']);

    if (is_bool($isPixPaidResponse)) {
        Logger::log('Verificar se fatura está paga antes de cancelar', 'O Pix não está pago.');

        return;
    }

    $apiTxId = $isPixPaidResponse['apiTxId'];
    $paidAmount = $isPixPaidResponse['paidAmount'];
    $paymentDate = $isPixPaidResponse['paymentDate'];
    $pixEndToEndId = $isPixPaidResponse['endToEndId'];

    (new ConfirmPaymentService())->run($apiTxId, $paidAmount, $paymentDate, $pixEndToEndId);

    $updateInvoiceResponse = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Paid']);

    Logger::log('Verificar se fatura está paga antes de cancelar', ['updateInvoiceResponse' => $updateInvoiceResponse]);
});

add_hook('InvoiceCreationPreEmail', 1, function (array $vars): array {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);

        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['id', 'userid', 'duedate', 'paymentmethod']);

        if (empty($invoice) || $invoice->paymentmethod !== 'lknbbpix') {
            return [];
        }

        $clientId = (int) $invoice->userid;
        $dueDay = (int) date('d', strtotime((string) $invoice->duedate));
        $invoiceOrigin = (new InvoiceOriginHelper())->classify($invoiceId);

        if ($invoiceOrigin === InvoiceOriginHelper::MANUAL_TRADICIONAL) {
            return [];
        }

        $decision = (new DecisionService())->evaluate($invoiceOrigin, $clientId, $dueDay);

        if ($decision !== DecisionService::COBR_AUTOMATICO) {
            return [];
        }

        $txid = PixTxidService::generateForInvoice($invoiceId);
        $scheduleResponse = (new ScheduleAutomaticChargeService())->run($invoiceId, $txid);

        if (!($scheduleResponse['success'] ?? false)) {
            Logger::log(
                'Agendar cobrança automática no InvoiceCreationPreEmail',
                ['invoiceId' => $invoiceId, 'txid' => $txid],
                $scheduleResponse
            );

            return [];
        }

        $pixTaxId = PixTaxId::fromDeterministicTxid($txid, 'CRIADO');
        $addTransacResponse = Invoice::addTransac(
            $clientId,
            $invoiceId,
            $pixTaxId->getTransIdForWhmcs(),
            0.0,
            '',
            'Pix Automático agendado',
            0.0,
            'lknbbpix'
        );

        if (($addTransacResponse['result'] ?? '') !== 'success') {
            Logger::log(
                'Falha ao registrar CRIADOx para Pix Automático',
                [
                    'invoiceId' => $invoiceId,
                    'pixTaxId' => $pixTaxId->getTransIdForWhmcs(),
                    'txid' => $txid
                ],
                $addTransacResponse
            );
            // Mas continua mesmo se falhar, não retorna
        }

        $dueDate = date('d/m/Y', strtotime((string) $invoice->duedate));

        (new InvoiceNoteService())->append(
            $invoiceId,
            "Pagamento agendado via Pix Automático para o dia {$dueDate}."
        );

        Logger::log(
            'Agendar cobrança automática no InvoiceCreationPreEmail',
            ['invoiceId' => $invoiceId, 'txid' => $txid],
            ['success' => true]
        );

        return [];
    } catch (Throwable $e) {
        Logger::log(
            'Falha no hook InvoiceCreationPreEmail (Pix Automático)',
            ['hookVars' => $vars],
            ['error' => $e->getMessage()]
        );

        return [];
    }
});

add_hook('ClientAreaPageViewInvoice', 1, function (array $vars): array {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);

        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['id', 'userid', 'duedate', 'paymentmethod']);

        if (empty($invoice) || $invoice->paymentmethod !== 'lknbbpix') {
            return [];
        }

        $clientId = (int) $invoice->userid;
        $dueDay = (int) date('d', strtotime((string) $invoice->duedate));
        $invoiceOrigin = (new InvoiceOriginHelper())->classify($invoiceId);

        $decision = $invoiceOrigin === InvoiceOriginHelper::MANUAL_TRADICIONAL
            ? DecisionService::MANUAL_TRADICIONAL
            : (new DecisionService())->evaluate($invoiceOrigin, $clientId, $dueDay);

        return [
            'lknbbpixFlowDecision' => $decision,
            'lknbbpixFlowOrigin' => $invoiceOrigin,
        ];
    } catch (Throwable $e) {
        Logger::log(
            'Falha no hook ClientAreaPageViewInvoice (Pix Automático)',
            ['hookVars' => $vars],
            ['error' => $e->getMessage()]
        );

        return [];
    }
});

/**
 * Automatically register (or remove) BB Pix Automático webhooks whenever the
 * gateway configuration is saved in the WHMCS admin area.
 *
 * - If essential credentials (client_id / client_secret) are missing after the
 *   save, we treat it as a deactivation and attempt to remove the webhooks.
 * - Otherwise we (re-)register both webhooks so the URL is always up-to-date
 *   if the WHMCS SystemURL ever changes.
 * - HTTPS validation: the BB API mandates HTTPS webhook URLs. If the WHMCS
 *   SystemURL starts with "http://" we abort registration and log a warning.
 * - Errors from the BB API are only logged — they must never interrupt the
 *   WHMCS config-save flow.
 */
add_hook('UpdateAdminPaymentGateway', 1, function (array $vars): void {
    $gateway = (string) ($vars['gateway'] ?? '');

    Logger::log(
        'UpdateAdminPaymentGateway disparado',
        ['gateway' => $gateway],
        ['willHandle' => $gateway === 'lknbbpix']
    );

    if ($gateway !== 'lknbbpix') {
        return;
    }

    $clientId     = (string) (Config::setting('client_id') ?? '');
    $clientSecret = (string) (Config::setting('client_secret') ?? '');
    $credentialsMissing = ($clientId === '' || $clientSecret === '');

    if ($credentialsMissing) {
        // Treat as deactivation: attempt to remove existing webhooks.
        try {
            $repo = new PixAutoRepository();
            $recResult  = $repo->removerWebhookRec();
            $cobrResult = $repo->removerWebhookCobr();

            Logger::log(
                'Remover webhooks Pix Automático (desativação detectada)',
                ['clientId' => $clientId],
                ['rec' => $recResult, 'cobr' => $cobrResult]
            );
        } catch (Throwable $e) {
            Logger::log(
                'Remover webhooks Pix Automático (desativação detectada)',
                ['clientId' => $clientId],
                'Falha ao remover webhooks: ' . $e->getMessage()
            );
        }

        return;
    }

    // Validate SystemURL protocol before attempting registration.
    $systemUrl = (string) Capsule::table('tblconfiguration')
        ->where('setting', 'SystemURL')
        ->value('value');

    if (!str_starts_with($systemUrl, 'https://')) {
        Logger::log(
            'Registrar webhooks Pix Automático',
            ['systemUrl' => $systemUrl],
            'Registro abortado: SystemURL não utiliza HTTPS. O Banco do Brasil exige URLs seguras para webhook Pix. Configure HTTPS no WHMCS e salve novamente.'
        );

        return;
    }

    // (Re-)register webhooks with the current SystemURL.
    try {
        $repo = new PixAutoRepository();
        $recResult  = $repo->registrarWebhookRec();
        $cobrResult = $repo->registrarWebhookCobr();

        Logger::log(
            'Registrar webhooks Pix Automático',
            ['systemUrl' => $systemUrl],
            ['rec' => $recResult, 'cobr' => $cobrResult]
        );
    } catch (Throwable $e) {
        Logger::log(
            'Registrar webhooks Pix Automático',
            ['systemUrl' => $systemUrl],
            'Falha ao registrar webhooks: ' . $e->getMessage()
        );
    }
});
