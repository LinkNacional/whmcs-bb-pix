<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use DateTime;
use Lkn\BBPix\App\Pix\Services\AutoPix\UpdatePaymentMethodService;
use Lkn\BBPix\App\Pix\Services\AutoPix\RestorePaymentMethodService;

final class AutoPixConsentRepository extends AbstractDbRepository
{
    protected string $table = 'mod_lknbbpix_auto_consents';

    public function insert(array $data): void
    {
        $this->query()->insert($data);
    }

    public function listByClient(int $clientId): array
    {
        $rows = $this->query()->where('clientid', $clientId)->orderBy('id', 'desc')->get();
        $list = [];
        foreach ($rows as $row) {
            $list[] = (array) $row;
        }
        return $list;
    }

    public function findByPspConsentId(string $pspConsentId): ?array
    {
        $row = $this->query()->where('psp_consent_id', $pspConsentId)->first();

        return $row ? (array) $row : null;
    }

    public function markRevokedByPspConsentId(string $pspConsentId): void
    {
        // Buscar o consent antes de atualizar
        $consent = $this->findByPspConsentId($pspConsentId);
        
        if (!$consent) {
            return;
        }

        // Atualizar status no banco
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $this->query()->where('psp_consent_id', $pspConsentId)->update([
            'status' => 'revoked',
            'revoked_at' => $now
        ]);

        // Restaurar método de pagamento do serviço/domínio
        $restorePaymentMethod = new RestorePaymentMethodService();
        $restorePaymentMethod->run($consent);
    }

    public function markActiveByPspConsentId(string $pspConsentId): void
    {
        // Buscar o consent antes de atualizar
        $consent = $this->findByPspConsentId($pspConsentId);
        
        if (!$consent) {
            return;
        }

        // Atualizar status no banco
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $this->query()->where('psp_consent_id', $pspConsentId)->update([
            'status' => 'active',
            'confirmed_at' => $now
        ]);

        // Atualizar método de pagamento do serviço/domínio
        $updatePaymentMethod = new UpdatePaymentMethodService();
        $updatePaymentMethod->run($consent);
    }

    public function updateIdRecByPspConsentId(string $pspConsentId, string $idRec, ?string $idRecTipo = null): void
    {
        $this->query()
            ->where('psp_consent_id', $pspConsentId)
            ->update([
                'id_rec' => $idRec,
                'id_rec_tipo' => $idRecTipo,
                'updated_at' => (new DateTime())->format('Y-m-d H:i:s')
        ]);
    }

    public function findPendingConsent(int $clientId, ?int $serviceId = null, ?int $domainId = null): ?array
    {
        $query = $this->query()
            ->where('clientid', $clientId)
            ->where('status', 'pending');
        
        if ($serviceId) {
            $query->where('serviceid', $serviceId);
        } elseif ($domainId) {
            $query->where('domainid', $domainId);
        }
        
        $row = $query->orderBy('id', 'desc')->first();
        
        return $row ? (array) $row : null;
    }
}

