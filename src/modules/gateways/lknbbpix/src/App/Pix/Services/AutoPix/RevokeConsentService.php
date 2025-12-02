<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\App\Pix\Repositories\AutoPixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;
use Lkn\BBPix\Helpers\Logger;

final class RevokeConsentService
{
    public function run(string $pspConsentId): array
    {
        try {
            $repo = new AutoPixConsentRepository();
            $consent = $repo->findByPspConsentId($pspConsentId);

            if (!$consent) {
                return ['success' => false, 'error' => 'Consent not found'];
            }

            // Remote revoke at BB
            $api = new AutoPixApiRepository();
            $remote = $api->revokeConsent($pspConsentId);

            // If API responds ok, mark locally
            $repo->markRevokedByPspConsentId($pspConsentId);

            Logger::log('AutoPix consent revoked', [
                'psp_consent_id' => $pspConsentId,
                'remote' => $remote
            ]);

            return ['success' => true, 'raw' => $remote];
        } catch (\Throwable $e) {
            Logger::log('AutoPix revoke consent error', [
                'psp_consent_id' => $pspConsentId,
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
