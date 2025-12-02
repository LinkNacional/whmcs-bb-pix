<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\App\Pix\Repositories\AutoPixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;

final class GetConsentStatusService
{
    public function run(string $pspConsentId): array
    {
        $api = new AutoPixApiRepository();
        $repo = new AutoPixConsentRepository();

        $res = $api->getConsent($pspConsentId);
        $status = $res['status'] ?? null;

        if ($status === 'approved' || $status === 'active') {
            $repo->markActiveByPspConsentId($pspConsentId);
        } elseif ($status === 'revoked') {
            $repo->markRevokedByPspConsentId($pspConsentId);
        }

        return ['success' => (bool) $status, 'status' => $status, 'raw' => $res];
    }
}


