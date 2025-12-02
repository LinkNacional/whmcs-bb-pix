<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Exception;
use Lkn\BBPix\App\Pix\Repositories\AutoPixInstructionRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

final class ApplyInvoiceFallbackService
{
    private AutoPixInstructionRepository $instructions;

    public function __construct()
    {
        $this->instructions = new AutoPixInstructionRepository();
    }

    public function run(array $context): bool
    {
        $invoiceId = (int)($context['invoice_id'] ?? 0);
        $clientId = (int)($context['client_id'] ?? 0);
        $reason = $context['reason'] ?? 'unknown';
        $source = $context['source'] ?? 'cron';

        if ($invoiceId <= 0) {
            Logger::log('AutoPix fallback: invoiceId inválido', $context);
            return false;
        }

        $configurationMethod = Config::setting('autopix_retry_failure_method');
        if ($configurationMethod === 'none') {
            Logger::log('AutoPix fallback: configuração definida para não alterar método de fatura', [
                'invoiceId' => $invoiceId,
                'reason' => $reason,
            ]);
            return true;
        }

        $newMethod = $this->resolvePaymentMethod($clientId, $configurationMethod);

        if ($newMethod === '') {
            Logger::log('AutoPix fallback: não foi possível determinar método de pagamento', [
                'invoiceId' => $invoiceId,
                'clientId' => $clientId,
                'configMethod' => $configurationMethod,
            ]);
            return false;
        }

        try {
            $updateResponse = localAPI('UpdateInvoice', [
                'invoiceid' => $invoiceId,
                'paymentmethod' => $newMethod,
            ]);

            if (($updateResponse['result'] ?? '') !== 'success') {
                Logger::log('AutoPix fallback: falha ao atualizar invoice', [
                    'invoiceId' => $invoiceId,
                    'response' => $updateResponse,
                ]);
                return false;
            }

            $instruction = $this->instructions->findLastByStatus($invoiceId, ['failed', 'cancelled']);
            $lastAttempt = $instruction['attempt_number'] ?? null;

            Logger::log('AutoPix fallback: método de fatura atualizado após falhas', [
                'invoiceId' => $invoiceId,
                'clientId' => $clientId,
                'paymentMethod' => $newMethod,
                'reason' => $reason,
                'source' => $source,
                'attempt' => $lastAttempt,
            ]);

            if (Config::setting('autopix_retry_failure_log_activity')) {
                $message = sprintf(
                    'AutoPix: fatura #%d atualizada para método %s após tentativas falhas (%s - tent. %s)',
                    $invoiceId,
                    $newMethod,
                    strtoupper($reason),
                    $lastAttempt ?? 'n/d'
                );
                logActivity($message, $clientId, $invoiceId);
            }

            return true;
        } catch (Exception $e) {
            Logger::log('AutoPix fallback: exceção ao atualizar fatura', [
                'invoiceId' => $invoiceId,
                'clientId' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolvePaymentMethod(int $clientId, string $configMethod): string
    {
        if ($configMethod === 'lknbbpix') {
            return 'lknbbpix';
        }

        if ($configMethod === 'default') {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first();

            return $client->defaultgateway ?? '';
        }

        return '';
    }
}
