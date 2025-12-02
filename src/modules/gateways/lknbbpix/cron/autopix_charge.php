<?php

use Lkn\BBPix\App\Pix\Repositories\AutoPixInstructionRepository;
use Lkn\BBPix\App\Pix\Services\AutoPix\CreatePaymentInstructionService;
use Lkn\BBPix\App\Pix\Services\AutoPix\ApplyInvoiceFallbackService;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\App\Pix\Services\AutoPix\SendChargeNotificationService;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    echo "Este script deve ser executado via CLI." . PHP_EOL;
    exit(1);
}

Logger::log('AutoPix CRON: início', []);

try {
    $attemptDays = Config::setting('autopix_charge_days');

    $service = new CreatePaymentInstructionService();
    $instructionRepo = new AutoPixInstructionRepository();
    $notifier = new SendChargeNotificationService();
    $fallbackService = new ApplyInvoiceFallbackService();

    $today = new DateTime('today');

    $invoices = Capsule::table('tblinvoices')
        ->select('id', 'userid', 'duedate', 'total')
        ->where('status', 'Unpaid')
        ->where('paymentmethod', 'lknbbpix_auto')
        ->get();

    foreach ($invoices as $invoice) {
        $invoiceId = (int) $invoice->id;
        $dueDate = new DateTime($invoice->duedate);

        $targets = resolveInvoiceTargets($invoiceId);
        if (!$targets) {
            Logger::log('AutoPix CRON: fatura sem serviço/domínio elegível', ['invoiceId' => $invoiceId]);
            continue;
        }

        $existingInstructions = $instructionRepo->findByInvoiceId($invoiceId);
        $existingByAttempt = [];
        foreach ($existingInstructions as $instruction) {
            $existingByAttempt[(int)$instruction['attempt_number']] = $instruction;
        }

        $createdAttempt = false;

        foreach ($attemptDays as $index => $offset) {
            $attemptNumber = $index + 1;

            if (isset($existingByAttempt[$attemptNumber])) {
                continue; // já existe instrução para esta tentativa
            }

            if ($attemptNumber > 1) {
                $previous = $existingByAttempt[$attemptNumber - 1] ?? null;
                if (!$previous || !in_array($previous['status'], ['failed', 'cancelled'], true)) {
                    continue; // aguardando resultado da tentativa anterior
                }
            }

            $scheduledDate = (clone $dueDate)->add(new DateInterval('P' . $offset . 'D'));
            $diffDays = (int) $today->diff($scheduledDate)->format('%r%a');

            if ($diffDays < 2 || $diffDays > 10) {
                continue; // fora da janela regulatória para enviar instrução
            }

            $payload = [
                'invoice_id' => $invoiceId,
                'attempt_number' => $attemptNumber,
                'scheduled_date' => $scheduledDate->format('Y-m-d'),
                'service_id' => $targets['service_id'],
                'domain_id' => $targets['domain_id'],
                'offset' => $offset,
            ];

            $response = $service->run($payload);

            if ($response['success'] ?? false) {
                $createdAttempt = true;
                Logger::log('AutoPix CRON: instrução criada', [
                    'invoiceId' => $invoiceId,
                    'attempt' => $attemptNumber,
                    'scheduled_date' => $scheduledDate->format('Y-m-d'),
                    'offset' => $offset,
                ]);

                $notifier->run([
                    'invoice_id' => $response['invoice_id'],
                    'client_id' => $invoice->userid,
                    'attempt' => $attemptNumber,
                    'scheduled_date' => $scheduledDate->format('Y-m-d'),
                    'status' => 'pending',
                    'amount' => $response['amount'],
                    'offset' => $offset,
                ]);
            } else {
                Logger::log('AutoPix CRON: erro ao criar instrução', [
                    'invoiceId' => $invoiceId,
                    'attempt' => $attemptNumber,
                    'error' => $response['error'] ?? 'Erro desconhecido',
                ]);
            }

            if ($createdAttempt) {
                break; // cria somente uma instrução por execução para esta fatura
            }
        }

        if (!$createdAttempt) {
            $pendingInstruction = $instructionRepo->findPendingByInvoice($invoiceId);
            if ($pendingInstruction === null) {
                $configuredAttempts = count($attemptDays);

                if ($configuredAttempts > 0) {
                    $allAttemptsCreated = true;
                    $allFailed = true;

                    for ($attemptIndex = 1; $attemptIndex <= $configuredAttempts; $attemptIndex++) {
                        if (!isset($existingByAttempt[$attemptIndex])) {
                            $allAttemptsCreated = false;
                            break;
                        }

                        $status = $existingByAttempt[$attemptIndex]['status'] ?? '';
                        if (!in_array($status, ['failed', 'cancelled'], true)) {
                            $allFailed = false;
                            break;
                        }
                    }

                    if ($allAttemptsCreated && $allFailed) {
                        Logger::log('AutoPix CRON: tentativas esgotadas, aplicando fallback na fatura', [
                            'invoiceId' => $invoiceId,
                            'clientId' => $invoice->userid,
                        ]);

                        $fallbackService->run([
                            'invoice_id' => $invoiceId,
                            'client_id' => (int) $invoice->userid,
                            'reason' => 'cron',
                            'source' => 'cron',
                        ]);
                    }
                }
            }
        }
    }

    Logger::log('AutoPix CRON: fim', []);
} catch (Exception $e) {
    Logger::log('AutoPix CRON: falha inesperada', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    echo 'Erro na execução do CRON: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

exit(0);

/**
 * Retorna service_id e/ou domain_id associados à fatura
 */
function resolveInvoiceTargets(int $invoiceId): ?array
{
    $serviceId = 0;
    $domainId = 0;

    $items = Capsule::table('tblinvoiceitems')
        ->select('type', 'relid')
        ->where('invoiceid', $invoiceId)
        ->get();

    $serviceTypes = ['Hosting', 'HostingSetup', 'HostingAddon'];
    $domainTypes = ['Domain', 'DomainRegister', 'DomainTransfer', 'DomainRenewal'];

    foreach ($items as $item) {
        $relid = (int) $item->relid;
        if ($relid <= 0) {
            continue;
        }

        if (in_array($item->type, $serviceTypes, true) && $serviceId === 0) {
            $serviceId = $relid;
        }

        if (in_array($item->type, $domainTypes, true) && $domainId === 0) {
            $domainId = $relid;
        }

        if ($serviceId > 0 || $domainId > 0) {
            break;
        }
    }

    if ($serviceId === 0 && $domainId === 0) {
        return null;
    }

    return [
        'service_id' => $serviceId,
        'domain_id' => $domainId,
    ];
}

