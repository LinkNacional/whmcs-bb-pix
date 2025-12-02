<?php

use Lkn\BBPix\App\Pix\Controllers\DiscountController;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Services\CancelPixService;
use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use Lkn\BBPix\App\Pix\Services\IsInvoicePixPaidService;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\PixApiRepositoryLate;
use Lkn\BBPix\App\Pix\PixController;
use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\Helpers\Formatter;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Lang;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\View;
use Lkn\BBPix\Helpers\Validator;
use WHMCS\Database\Capsule;
use WHMCS\View\Menu\Item as MenuItem;

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

// Add AutoPix link to the Billing menu when lknbbpix_auto is active
add_hook('ClientAreaPrimaryNavbar', 1, function (MenuItem $primaryNavbar) {
    // Verificar se o gateway AutoPix está ativo
    $isAutoPixActive = Capsule::table('tblpaymentgateways')->where('gateway', 'lknbbpix_auto')->exists();
    if (!$isAutoPixActive) {
        return;
    }

    $billing = $primaryNavbar->getChild('Financial');
    if (!$billing instanceof MenuItem) {
        return;
    }

    // Load translations for menu
    Lang::load();
    $menuLabel = Lang::trans('autopix_title');

    $systemUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
    $url = $systemUrl . 'pixautomatico.php';

    if ($billing->getChild('AutoPix') === null) {
        $billing->addChild('AutoPix', [
            'label' => $menuLabel,
            'uri' => $url,
            'order' => 95,
            'icon' => 'fa-bolt'
        ]);
    }
});

add_hook('InvoiceCancelled', 1, function ($vars): void {
    $invoiceId = (int) $vars['invoiceid'];

    $verifyPix = Config::setting('enable_pix_when_invoice_cancel');
    $cancelPix = Config::setting('enable_pix_cancel_when_invoice_cancel');

    // Se nenhuma das opções está habilitada, sair.
    if (!$verifyPix && !$cancelPix) {
        return;
    }

    $transactions = Invoice::getTransactions($invoiceId)['transactions']['transaction'] ?? [];

    if (empty($transactions)) {
        Logger::log('InvoiceCancelled', [
            'invoiceId' => $invoiceId,
            'status' => 'Nenhuma transação na fatura'
        ]);
        return;
    }

    foreach ($transactions as $transaction) {
        if ($transaction['gateway'] !== Config::constant('name')) {
            continue; // Não é transação do nosso gateway
        }

        $transId = $transaction['transid'];

        if (!str_starts_with($transId, 'CRIADOx')) {
            continue; // Não é PIX pendente
        }

        try {
            // Primeiro: verificar se o PIX foi pago
            if ($verifyPix) {
                $isPaidResponse = (new IsInvoicePixPaidService())->run($invoiceId, $transId);

                if (is_array($isPaidResponse)) {
                    // PIX pago – confirmar pagamento
                    (new ConfirmPaymentService())->run(
                        $isPaidResponse['apiTxId'],
                        $isPaidResponse['paidAmount'],
                        $isPaidResponse['paymentDate'],
                        $isPaidResponse['endToEndId']
                    );

                    Logger::log('InvoiceCancelled', [
                        'invoiceId' => $invoiceId,
                        'transacId' => $transId,
                        'status' => 'PIX já pago – confirmado'
                    ]);
                    // Se está pago, NÃO cancelar mesmo que $cancelPix seja true
                    continue;
                }
            }

            // Se chegou aqui, o PIX não está pago
            if ($cancelPix) {
                $isLatePix = str_contains($transId, 'LATE');
                $pixRepo = $isLatePix ? new PixApiRepositoryLate() : new PixApiRepository();

                $cancelService = new CancelPixService($pixRepo);
                $cancelResp = $cancelService->run([
                    'transacId' => $transId,
                    'invoiceId' => $invoiceId
                ]);

                // Registrar transação de cancelamento
                $clientId = Invoice::getClientId($invoiceId);
                Invoice::addTransac(
                    $clientId,
                    $invoiceId,
                    $cancelResp['cancelTransId'],
                    0.0,
                    '',
                    'PIX cancelado automaticamente ao cancelar fatura',
                    0.0
                );

                Logger::log('InvoiceCancelled', [
                    'invoiceId' => $invoiceId,
                    'transacId' => $transId,
                    'cancelTransId' => $cancelResp['cancelTransId'],
                    'status' => 'PIX não pago – cancelado'
                ]);
            }
        } catch (PixException $e) {
            Logger::log('InvoiceCancelled', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error' => $e->getMessage(),
                'code'  => $e->exceptionCode->name
            ]);
        } catch (\Throwable $e) {
            Logger::log('InvoiceCancelled', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error' => $e->getMessage()
            ]);
        }
    }
});

add_hook('InvoicePaid', 1, function ($vars): void {
    $invoiceId = (int) $vars['invoiceid'];

    $transactions = Invoice::getTransactions($invoiceId)['transactions']['transaction'] ?? [];

    if (empty($transactions)) {
        Logger::log('InvoicePaid', [
            'invoiceId' => $invoiceId,
            'status'    => 'Nenhuma transação na fatura'
        ]);
        return;
    }

    foreach ($transactions as $transaction) {
        if ($transaction['gateway'] !== Config::constant('name')) {
            continue; // Não é transação do nosso gateway
        }

        $transId = $transaction['transid'];

        // Verifica apenas PIX pendentes (prefixo CRIADOx)
        if (!str_starts_with($transId, 'CRIADOx')) {
            continue;
        }

        try {
            // Se já existir PAGOx para o mesmo PIX (mesmo suffix), não cancelar
            $taxId = PixTaxId::fromWhmcsTransId($transId, $invoiceId);
            $suffix = $taxId->suffix;
            $alreadyPaidLocally = false;
            foreach ($transactions as $t) {
                $tid = $t['transid'] ?? '';
                if (is_string($tid) && str_starts_with($tid, 'PAGOx') && str_contains($tid, 'PAGOx' . $suffix . 'x')) {
                    $alreadyPaidLocally = true;
                    break;
                }
            }
            if ($alreadyPaidLocally) {
                Logger::log('InvoicePaid', [
                    'invoiceId' => $invoiceId,
                    'transacId' => $transId,
                    'status'    => 'PIX já pago (local) – não cancelado'
                ]);
                continue;
            }

            // Antes de cancelar, confirmar via API se o PIX já foi pago
            $isPaid = (new IsInvoicePixPaidService())->run($invoiceId, $transId);

            if (is_array($isPaid)) {
                Logger::log('InvoicePaid', [
                    'invoiceId' => $invoiceId,
                    'transacId' => $transId,
                    'status'    => 'PIX já pago – não cancelado'
                ]);
                continue;
            }

            // PIX ainda não pago – prosseguir com cancelamento
            $isLatePix = str_contains($transId, 'LATE');
            $pixRepo   = $isLatePix ? new PixApiRepositoryLate() : new PixApiRepository();

            $cancelService = new CancelPixService($pixRepo);
            $cancelResp    = $cancelService->run([
                'transacId' => $transId,
                'invoiceId' => $invoiceId
            ]);

            // Registrar transação de cancelamento
            $clientId = Invoice::getClientId($invoiceId);
            Invoice::addTransac(
                $clientId,
                $invoiceId,
                $cancelResp['cancelTransId'],
                0.0,
                '',
                'PIX cancelado automaticamente após pagamento da fatura',
                0.0
            );

            Logger::log('InvoicePaid', [
                'invoiceId'      => $invoiceId,
                'transacId'      => $transId,
                'cancelTransId'  => $cancelResp['cancelTransId'],
                'status'         => 'PIX não pago – cancelado'
            ]);
        } catch (PixException $e) {
            Logger::log('InvoicePaid', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error'     => $e->getMessage(),
                'code'      => $e->exceptionCode->name
            ]);
        } catch (\Throwable $e) {
            Logger::log('InvoicePaid', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error'     => $e->getMessage()
            ]);
        }
    }
});

add_hook('InvoiceChangeGateway', 1, function ($vars): void {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    $newGateway = (string) ($vars['paymentmethod'] ?? '');

    // Se o novo método é o nosso próprio, não há o que cancelar
    if ($newGateway === Config::constant('name')) {
        return;
    }

    if ($invoiceId <= 0) {
        return;
    }

    $transactions = Invoice::getTransactions($invoiceId)['transactions']['transaction'] ?? [];

    if (empty($transactions)) {
        Logger::log('InvoiceChangeGateway', [
            'invoiceId' => $invoiceId,
            'status' => 'Nenhuma transação na fatura'
        ]);
        return;
    }

    foreach ($transactions as $transaction) {
        if (($transaction['gateway'] ?? '') !== Config::constant('name')) {
            continue; // Não é transação do nosso gateway
        }

        $transId = $transaction['transid'] ?? '';

        // Cancelar apenas PIX pendentes (prefixo CRIADOx)
        if (!is_string($transId) || !str_starts_with($transId, 'CRIADOx')) {
            continue;
        }

        try {
            // Antes de cancelar, confirmar via API se o PIX já foi pago
            $isPaid = (new IsInvoicePixPaidService())->run($invoiceId, $transId);
            if (is_array($isPaid)) {
                // PIX pago – confirmar pagamento e não cancelar
                (new ConfirmPaymentService())->run(
                    $isPaid['apiTxId'],
                    $isPaid['paidAmount'],
                    $isPaid['paymentDate'],
                    $isPaid['endToEndId']
                );

                Logger::log('InvoiceChangeGateway', [
                    'invoiceId' => $invoiceId,
                    'transacId' => $transId,
                    'status' => 'PIX já pago – confirmado'
                ]);
                continue;
            }

            // PIX ainda não pago – prosseguir com cancelamento
            $isLatePix = str_contains($transId, 'LATE');
            $pixRepo   = $isLatePix ? new PixApiRepositoryLate() : new PixApiRepository();

            $cancelService = new CancelPixService($pixRepo);
            $cancelResp    = $cancelService->run([
                'transacId' => $transId,
                'invoiceId' => $invoiceId
            ]);

            // Registrar transação de cancelamento
            $clientId = Invoice::getClientId($invoiceId);
            Invoice::addTransac(
                $clientId,
                $invoiceId,
                $cancelResp['cancelTransId'],
                0.0,
                '',
                'PIX cancelado automaticamente ao mudar método de pagamento da fatura',
                0.0
            );

            Logger::log('InvoiceChangeGateway', [
                'invoiceId'      => $invoiceId,
                'transacId'      => $transId,
                'cancelTransId'  => $cancelResp['cancelTransId'],
                'status'         => 'PIX não pago – cancelado'
            ]);
        } catch (PixException $e) {
            Logger::log('InvoiceChangeGateway', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error' => $e->getMessage(),
                'code'      => $e->exceptionCode->name
            ]);
        } catch (\Throwable $e) {
            Logger::log('InvoiceChangeGateway', [
                'invoiceId' => $invoiceId,
                'transacId' => $transId,
                'error' => $e->getMessage()
            ]);
        }
    }
});
