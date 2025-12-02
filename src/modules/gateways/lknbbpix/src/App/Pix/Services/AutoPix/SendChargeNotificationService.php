<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

final class SendChargeNotificationService
{
    public function run(array $params): bool
    {
        $invoiceId = (int)($params['invoice_id'] ?? 0);
        $clientId = (int)($params['client_id'] ?? 0);
        $attempt = (int)($params['attempt'] ?? 1);
        $scheduledDate = $params['scheduled_date'] ?? '';
        $status = $params['status'] ?? 'pending';
        $amount = (float)($params['amount'] ?? 0);
        $offset = (int)($params['offset'] ?? 0);

        if ($invoiceId <= 0 || $clientId <= 0) {
            Logger::log('AutoPix notify: dados inválidos', $params);
            return false;
        }

        $template = Config::setting('autopix_charge_notification_template') ?? '';
        if (trim($template) === '') {
            Logger::log('AutoPix notify: template não configurado', $params);
            return false;
        }

        $mergeFields = [
            'invoice_id' => $invoiceId,
            'attempt' => $attempt,
            'scheduled_date' => $scheduledDate,
            'status' => $status,
            'amount' => number_format($amount, 2, ',', '.'),
            'offset' => $offset,
        ];

        $result = localAPI('SendEmail', [
            'messagename' => $template,
            'id' => $clientId,
            'customvars' => base64_encode(serialize($mergeFields)),
        ]);

        Logger::log('AutoPix notify: email disparado', [
            'invoiceId' => $invoiceId,
            'clientId' => $clientId,
            'template' => $template,
            'result' => $result
        ]);

        return ($result['result'] ?? '') === 'success';
    }
}

