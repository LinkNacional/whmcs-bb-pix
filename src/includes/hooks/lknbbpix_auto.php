<?php
/**
 * Hook PIX Automático - Banco do Brasil
 * 
 * Cancela e remove consentimentos quando há alterações em:
 * - Preço (amount, recurringamount)
 * - Ciclo de pagamento (billingcycle)
 * - Vencimento (nextduedate)
 * 
 * @package WHMCS
 * @subpackage Hooks
 */

use WHMCS\Database\Capsule;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Carregar autoload do módulo
$autoloadPath = __DIR__ . '/../../modules/gateways/lknbbpix/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

require_once ROOTDIR . '/includes/invoicefunctions.php';

const LKNBBPIX_AUTO_SPLIT_FLAG = '[AutoPix Split]';

if (!function_exists('lknbbpix_auto_log_split')) {
    function lknbbpix_auto_log_split(string $message, array $context = [], ?int $clientId = null, ?int $invoiceId = null): void
    {
        Logger::log($message, $context);

        if ($clientId) {
            $activity = $message;
            if (!empty($context)) {
                $activity .= ' :: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            logActivity($activity, $clientId, $invoiceId);
        }
    }
}

if (!function_exists('lknbbpix_auto_should_split_invoices')) {
    function lknbbpix_auto_should_split_invoices(): bool
    {
        try {
            return (bool) Config::setting('autopix_split');
        } catch (\Throwable $e) {
            Logger::log('AutoPix split: falha ao ler configuração', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

add_hook('InvoiceCreation', 1, function (array $vars): void {
    if (!lknbbpix_auto_should_split_invoices()) {
        return;
    }

    $invoiceId = (int) ($vars['invoiceid'] ?? 0);

    if ($invoiceId <= 0) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if (!$invoice) {
        return;
    }

    if ($invoice->paymentmethod !== 'lknbbpix_auto') {
        return;
    }

    $adminNotes = (string) ($invoice->adminnotes ?? '');
    if (strpos($adminNotes, LKNBBPIX_AUTO_SPLIT_FLAG) !== false) {
        return;
    }

    $items = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->orderBy('id')
        ->get();

    if ($items->count() <= 1) {
        return;
    }

    // marcar fatura original para evitar processamento repetido
    $newNotes = trim($adminNotes . (strlen($adminNotes) > 0 ? PHP_EOL : '') . LKNBBPIX_AUTO_SPLIT_FLAG);
    Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->update(['adminnotes' => $newNotes]);

    $firstItem = true;

    lknbbpix_auto_log_split(
        'AutoPix split: iniciando divisão de fatura',
        [
            'invoiceId' => $invoiceId,
            'items' => $items->count(),
        ],
        (int) $invoice->userid,
        $invoiceId
    );

    foreach ($items as $item) {
        if ($firstItem) {
            $firstItem = false;
            continue;
        }

        $lineItemPayload = [
            [
                'description' => $item->description,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'taxed' => (int) ($item->taxed ?? 0),
                'type' => $item->type,
                'relid' => (int) ($item->relid ?? 0),
                'duedate' => $item->duedate ?: $invoice->duedate,
            ]
        ];

        $payload = [
            'userid' => $invoice->userid,
            'date' => $invoice->date,
            'duedate' => $item->duedate ?: $invoice->duedate,
            'paymentmethod' => $invoice->paymentmethod,
            'status' => $invoice->status,
            'sendinvoice' => '0',
            'adminnotes' => LKNBBPIX_AUTO_SPLIT_FLAG,
            'taxrate' => $invoice->taxrate,
            'taxrate2' => $invoice->taxrate2,
            'lineitems' => base64_encode(serialize($lineItemPayload)),
        ];

        if (!empty($invoice->notes)) {
            $payload['notes'] = $invoice->notes;
        }

        $response = localAPI('CreateInvoice', $payload, 'AutoPix Split');

        if (($response['result'] ?? '') !== 'success') {
            lknbbpix_auto_log_split(
                'AutoPix split: falha ao criar fatura individual',
                [
                    'invoiceId' => $invoiceId,
                    'itemId' => $item->id,
                    'response' => $response,
                ],
                (int) $invoice->userid,
                $invoiceId
            );

            continue;
        }

        $newInvoiceId = (int) ($response['invoiceid'] ?? 0);

        Capsule::table('tblinvoiceitems')->where('id', $item->id)->delete();

        lknbbpix_auto_log_split(
            'AutoPix split: item movido para nova fatura',
            [
                'originalInvoice' => $invoiceId,
                'newInvoice' => $newInvoiceId,
                'itemId' => $item->id,
            ],
            (int) $invoice->userid,
            $newInvoiceId > 0 ? $newInvoiceId : $invoiceId
        );
    }

    updateInvoiceTotal($invoiceId);

    lknbbpix_auto_log_split(
        'AutoPix split: fatura original atualizada após distribuição',
        [
            'invoiceId' => $invoiceId,
        ],
        (int) $invoice->userid,
        $invoiceId
    );
});

/**
 * Cancela consentimento PIX Automático e remove do banco
 */
function lknbbpix_auto_cancelConsent(int $serviceId = 0, int $domainId = 0, string $reason = ''): void
{
    try {
        $repo = new \Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository();
        
        // Buscar consentimento (qualquer status)
        $consent = null;
        
        if ($serviceId > 0) {
            $consent = Capsule::table('mod_lknbbpix_auto_consents')
                ->where('serviceid', $serviceId)
                ->orderBy('id', 'desc')
                ->first();
        } elseif ($domainId > 0) {
            $consent = Capsule::table('mod_lknbbpix_auto_consents')
                ->where('domainid', $domainId)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$consent) {
            return; // Não há consentimento ativo
        }

        $consent = (array) $consent;
        $pspConsentId = $consent['psp_consent_id'] ?? '';
        $consentId = $consent['id'] ?? 0;

        \Lkn\BBPix\Helpers\Logger::log('AutoPix: Cancelando consentimento por alteração', [
            'consent_id' => $consentId,
            'psp_consent_id' => $pspConsentId,
            'service_id' => $serviceId,
            'domain_id' => $domainId,
            'reason' => $reason
        ]);

        // 1. Restaurar método de pagamento ANTES de apagar
        $restoreService = new \Lkn\BBPix\App\Pix\Services\AutoPix\RestorePaymentMethodService();
        $restoreService->run($consent);

        // 2. Cancelar na API do BB
        if (!empty($pspConsentId)) {
            try {
                $api = new \Lkn\BBPix\App\Pix\Repositories\AutoPixApiRepository();
                $api->revokeConsent($pspConsentId);
                
                \Lkn\BBPix\Helpers\Logger::log('AutoPix: Consentimento cancelado na API BB', [
                    'psp_consent_id' => $pspConsentId
                ]);
            } catch (\Exception $e) {
                \Lkn\BBPix\Helpers\Logger::log('AutoPix: Erro ao cancelar na API BB', [
                    'psp_consent_id' => $pspConsentId,
                    'error' => $e->getMessage()
                ]);
                // Continua mesmo se falhar na API
            }
        }

        // 3. Remover registro do banco
        Capsule::table('mod_lknbbpix_auto_consents')
            ->where('id', $consentId)
            ->delete();

        \Lkn\BBPix\Helpers\Logger::log('AutoPix: Consentimento removido do banco', [
            'consent_id' => $consentId,
            'reason' => $reason
        ]);

        // 4. Registrar na atividade do cliente
        if ($consent['clientid'] ?? 0) {
            logActivity(
                "[PIX Automático] Consentimento cancelado e removido. Motivo: {$reason}",
                $consent['clientid']
            );
        }

    } catch (\Exception $e) {
        logActivity("[PIX Automático] Erro ao cancelar consentimento: " . $e->getMessage());
    }
}

// ====================================================================
// HOOK AUXILIAR: Marcar pagamentos de fatura
// ====================================================================

add_hook('InvoicePaid', 1, function($vars) {
    $invoiceId = $vars['invoiceid'] ?? 0;
    
    if (!$invoiceId) {
        return;
    }
    
    // Marcar que uma fatura foi paga (validade 10 segundos)
    $_SESSION['lknbbpix_auto_invoice_paid_' . $invoiceId] = time();
    
    // Buscar itens da fatura para marcar serviços/domínios
    $items = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('type', 'Hosting')
        ->get();
    
    foreach ($items as $item) {
        if ($item->relid > 0) {
            $_SESSION['lknbbpix_auto_service_renewed_' . $item->relid] = time();
        }
    }
    
    // Buscar domínios
    $domainItems = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->whereIn('type', ['Domain', 'DomainRegister', 'DomainTransfer', 'DomainRenewal'])
        ->get();
    
    foreach ($domainItems as $item) {
        if ($item->relid > 0) {
            $_SESSION['lknbbpix_auto_domain_renewed_' . $item->relid] = time();
        }
    }
});

// ====================================================================
// HOOK 1: Monitorar UPGRADES e DOWNGRADES
// ====================================================================

// Capturar dados ANTES do upgrade
add_hook('PreUpgradeCheckout', 1, function($vars) {
    $serviceId = $vars['serviceId'] ?? 0;

    if (!$serviceId) {
        return;
    }

    // Verificar se tem PIX Automático
    $hasAutoPix = Capsule::table('mod_lknbbpix_auto_consents')
        ->where('serviceid', $serviceId)
        ->exists();

    if (!$hasAutoPix) {
        return;
    }

    // Salvar dados atuais para comparação
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first();

    if ($service) {
        $_SESSION['lknbbpix_auto_pre_upgrade_service_' . $serviceId] = [
            'amount' => $service->amount,
            'billingcycle' => $service->billingcycle,
            'nextduedate' => $service->nextduedate,
        ];
    }
});

// Verificar DEPOIS do upgrade
add_hook('AfterServiceUpgrade', 1, function($vars) {
    $upgradeId = $vars['upgradeId'] ?? 0;
    $serviceId = $vars['serviceId'] ?? 0;
    $clientId = $vars['clientId'] ?? 0;

    if (!$serviceId) {
        return;
    }

    // Recuperar dados anteriores
    $oldData = $_SESSION['lknbbpix_auto_pre_upgrade_service_' . $serviceId] ?? null;

    if (!$oldData) {
        return; // Não tinha PIX Automático ou PreUpgradeCheckout não capturou
    }

    // Buscar dados atualizados
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->first();

    if (!$service) {
        unset($_SESSION['lknbbpix_auto_pre_upgrade_service_' . $serviceId]);
        return;
    }

    // Comparar campos importantes
    $changes = [];

    // Verificar mudança de VALOR
    if ((float) $oldData['amount'] !== (float) $service->amount) {
        $changes[] = sprintf(
            'Valor alterado de R$ %s para R$ %s',
            number_format($oldData['amount'], 2, ',', '.'),
            number_format($service->amount, 2, ',', '.')
        );
    }

    // Verificar mudança de CICLO
    if ($oldData['billingcycle'] !== $service->billingcycle) {
        $changes[] = sprintf(
            'Ciclo alterado de %s para %s',
            $oldData['billingcycle'],
            $service->billingcycle
        );
    }

    // Verificar mudança de VENCIMENTO
    if ($oldData['nextduedate'] !== $service->nextduedate) {
        // Verificar se foi por pagamento de fatura (renovação)
        $wasRenewed = isset($_SESSION['lknbbpix_auto_service_renewed_' . $serviceId]) 
            && (time() - $_SESSION['lknbbpix_auto_service_renewed_' . $serviceId]) < 10;
        
        if (!$wasRenewed) {
            // Alteração manual de vencimento
            $changes[] = sprintf(
                'Vencimento alterado de %s para %s',
                $oldData['nextduedate'],
                $service->nextduedate
            );
        }
    }

    // Limpar sessão
    unset($_SESSION['lknbbpix_auto_pre_upgrade_service_' . $serviceId]);
    unset($_SESSION['lknbbpix_auto_service_renewed_' . $serviceId]);

    // SE NADA MUDOU, MANTER O CONSENTIMENTO
    if (empty($changes)) {
        logActivity(
            "[PIX Automático] Upgrade realizado sem alteração de valor/ciclo/vencimento - Consentimento MANTIDO",
            $clientId
        );
        return; // NÃO cancela o consentimento
    }

    // SE HOUVE MUDANÇA, CANCELAR O CONSENTIMENTO
    // Buscar detalhes do upgrade para log
    $upgrade = Capsule::table('tblupgrades')
        ->where('id', $upgradeId)
        ->first();

    $changeType = ($upgrade && (float) $upgrade->recurringchange > 0) ? 'UPGRADE' : 'DOWNGRADE';
    $reason = sprintf(
        '%s de produto - %s',
        $changeType,
        implode(', ', $changes)
    );

    // Cancelar consentimento
    lknbbpix_auto_cancelConsent($serviceId, 0, $reason);
});

// ====================================================================
// HOOK 2: Monitorar EDIÇÕES MANUAIS em Serviços
// ====================================================================

add_hook('PreServiceEdit', 1, function($vars) {
    $serviceid = $vars['serviceid'] ?? 0;

    if (!$serviceid) {
        return;
    }

    // Verificar se tem PIX Automático
    $hasAutoPix = Capsule::table('mod_lknbbpix_auto_consents')
        ->where('serviceid', $serviceid)
        ->exists();

    if (!$hasAutoPix) {
        return;
    }

    // Salvar dados atuais para comparação
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceid)
        ->first();

    if ($service) {
        $_SESSION['lknbbpix_auto_pre_edit_service_' . $serviceid] = [
            'amount' => $service->amount,
            'billingcycle' => $service->billingcycle,
            'nextduedate' => $service->nextduedate,
            'firstpaymentamount' => $service->firstpaymentamount,
        ];
    }
});

add_hook('ServiceEdit', 1, function($vars) {
    $serviceid = $vars['serviceid'] ?? 0;
    $userid = $vars['userid'] ?? 0;

    if (!$serviceid) {
        return;
    }

    // Recuperar dados anteriores
    $oldData = $_SESSION['lknbbpix_auto_pre_edit_service_' . $serviceid] ?? null;
    
    if (!$oldData) {
        return; // Não tinha PIX Automático ou PreServiceEdit não capturou
    }

    // Buscar dados atualizados
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceid)
        ->first();

    if (!$service) {
        unset($_SESSION['lknbbpix_auto_pre_edit_service_' . $serviceid]);
        return;
    }

    // Comparar campos importantes
    $changes = [];

    if ((float) $oldData['amount'] !== (float) $service->amount) {
        $changes[] = sprintf(
            'Preço alterado de R$ %s para R$ %s',
            number_format($oldData['amount'], 2, ',', '.'),
            number_format($service->amount, 2, ',', '.')
        );
    }

    if ($oldData['billingcycle'] !== $service->billingcycle) {
        $changes[] = sprintf(
            'Ciclo alterado de %s para %s',
            $oldData['billingcycle'],
            $service->billingcycle
        );
    }

    if ($oldData['nextduedate'] !== $service->nextduedate) {
        // Verificar se foi por pagamento de fatura (renovação)
        $wasRenewed = isset($_SESSION['lknbbpix_auto_service_renewed_' . $serviceid]) 
            && (time() - $_SESSION['lknbbpix_auto_service_renewed_' . $serviceid]) < 10;
        
        if (!$wasRenewed) {
            // Alteração manual de vencimento
            $changes[] = sprintf(
                'Vencimento alterado de %s para %s',
                $oldData['nextduedate'],
                $service->nextduedate
            );
        }
    }

    // Limpar sessão
    unset($_SESSION['lknbbpix_auto_pre_edit_service_' . $serviceid]);
    unset($_SESSION['lknbbpix_auto_service_renewed_' . $serviceid]);

    // Se houve alterações, cancelar consentimento
    if (!empty($changes)) {
        $reason = 'Edição manual: ' . implode(', ', $changes);
        lknbbpix_auto_cancelConsent($serviceid, 0, $reason);
    }
});

// ====================================================================
// HOOK 3: Monitorar EDIÇÕES em Domínios
// ====================================================================

add_hook('DomainEdit', 1, function($vars) {
    $domainid = $vars['domainid'] ?? 0;

    if (!$domainid) {
        return;
    }

    // Verificar se tem PIX Automático
    $consent = Capsule::table('mod_lknbbpix_auto_consents')
        ->where('domainid', $domainid)
        ->first();

    if (!$consent) {
        return;
    }

    // Buscar histórico de mudanças no log de atividades (última entrada)
    $lastLog = Capsule::table('tblactivitylog')
        ->where('date', '>=', date('Y-m-d H:i:s', strtotime('-5 seconds')))
        ->where('description', 'like', "%Domain ID: {$domainid}%")
        ->orderBy('id', 'desc')
        ->first();

    if (!$lastLog) {
        return;
    }

    $description = $lastLog->description;
    $changes = [];

    // Verificar mudança de VALOR (Recurring Amount)
    if (preg_match('/Recurring Amount changed from [\'"]([0-9.]+)[\'"] to [\'"]([0-9.]+)[\'"]/', $description, $matches)) {
        $oldAmount = (float) $matches[1];
        $newAmount = (float) $matches[2];
        
        if (abs($oldAmount - $newAmount) > 0.001) {
            $changes[] = sprintf(
                'Valor alterado de R$ %s para R$ %s',
                number_format($oldAmount, 2, ',', '.'),
                number_format($newAmount, 2, ',', '.')
            );
        }
    }

    // Verificar mudança de VENCIMENTO (Next Due Date)
    if (preg_match('/Next Due Date changed from [\'"]([0-9\-: ]+)[\'"] to [\'"]([0-9\-: ]+)[\'"]/', $description, $matches)) {
        $oldDate = date('Y-m-d', strtotime($matches[1]));
        $newDate = date('Y-m-d', strtotime($matches[2]));
        
        if ($oldDate !== $newDate) {
            // Verificar se foi por pagamento de fatura (renovação)
            $wasRenewed = isset($_SESSION['lknbbpix_auto_domain_renewed_' . $domainid]) 
                && (time() - $_SESSION['lknbbpix_auto_domain_renewed_' . $domainid]) < 10;
            
            if (!$wasRenewed) {
                // Alteração manual de vencimento
                $changes[] = sprintf(
                    'Vencimento alterado de %s para %s',
                    $oldDate,
                    $newDate
                );
            }
        }
    }

    // Limpar marcador de renovação
    unset($_SESSION['lknbbpix_auto_domain_renewed_' . $domainid]);

    // Se houve alterações, cancelar consentimento
    if (!empty($changes)) {
        $reason = 'Edição de domínio: ' . implode(', ', $changes);
        lknbbpix_auto_cancelConsent(0, $domainid, $reason);
    }
});

// ====================================================================
// HOOK 4: Monitorar ADDON Upgrades
// ====================================================================

add_hook('AfterAddonUpgrade', 1, function($vars) {
    $upgradeid = $vars['upgradeid'] ?? 0;

    if (!$upgradeid) {
        return;
    }

    // Buscar upgrade
    $upgrade = Capsule::table('tblupgrades')
        ->where('id', $upgradeid)
        ->first();

    if (!$upgrade || $upgrade->type !== 'addon') {
        return;
    }

    // Buscar addon
    $addon = Capsule::table('tblhostingaddons')
        ->where('id', $upgrade->relid)
        ->first();

    if (!$addon) {
        return;
    }

    $serviceId = $addon->hostingid ?? 0;

    if (!$serviceId) {
        return;
    }

    // Verificar se o serviço pai tem PIX Automático
    $hasAutoPix = Capsule::table('mod_lknbbpix_auto_consents')
        ->where('serviceid', $serviceId)
        ->exists();

    if (!$hasAutoPix) {
        return;
    }

    $reason = sprintf(
        'Upgrade de addon (mudança de R$ %s)',
        number_format(abs($upgrade->recurringchange ?? 0), 2, ',', '.')
    );

    // Cancelar consentimento do serviço pai
    lknbbpix_auto_cancelConsent($serviceId, 0, $reason);
});

// ====================================================================
// HOOK 5: Monitorar MUDANÇAS DE CONFIG OPTIONS
// ====================================================================

add_hook('AfterConfigOptionsUpgrade', 1, function($vars) {
    $upgradeid = $vars['upgradeid'] ?? 0;

    if (!$upgradeid) {
        return;
    }

    // Buscar upgrade
    $upgrade = Capsule::table('tblupgrades')
        ->where('id', $upgradeid)
        ->first();

    if (!$upgrade) {
        return;
    }

    $serviceId = $upgrade->relid ?? 0;

    if (!$serviceId) {
        return;
    }

    // Verificar se o serviço tem PIX Automático
    $hasAutoPix = Capsule::table('mod_lknbbpix_auto_consents')
        ->where('serviceid', $serviceId)
        ->exists();

    if (!$hasAutoPix) {
        return;
    }

    $reason = sprintf(
        'Alteração de opção configurável (mudança de R$ %s)',
        number_format(abs($upgrade->recurringchange ?? 0), 2, ',', '.')
    );

    // Cancelar consentimento
    lknbbpix_auto_cancelConsent($serviceId, 0, $reason);
});

