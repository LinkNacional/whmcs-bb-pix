<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\Helpers\Logger;

final class UpdatePaymentMethodService
{
    /**
     * Atualiza o método de pagamento de um serviço ou domínio para PIX Automático
     * 
     * @param array $consent Consent com campos: type, serviceid, domainid
     * @param string $gatewayName Nome do gateway (ex: 'lknbbpix_auto')
     * @return bool True se atualizou com sucesso
     */
    public function run(array $consent, string $gatewayName = 'lknbbpix_auto'): bool
    {
        $type = $consent['type'] ?? '';
        $serviceId = (int)($consent['serviceid'] ?? 0);
        $domainId = (int)($consent['domainid'] ?? 0);

        Logger::log('AutoPix: Atualizar método de pagamento', [
            'type' => $type,
            'serviceId' => $serviceId,
            'domainId' => $domainId,
            'gatewayName' => $gatewayName
        ]);

        if ($type === 'service' && $serviceId > 0) {
            return $this->updateServicePaymentMethod($serviceId, $gatewayName);
        }

        if ($type === 'domain' && $domainId > 0) {
            return $this->updateDomainPaymentMethod($domainId, $gatewayName);
        }

        Logger::log('AutoPix: Tipo inválido ou IDs não encontrados', [
            'consent' => $consent
        ]);

        return false;
    }

    /**
     * Atualiza método de pagamento de um serviço/produto
     */
    private function updateServicePaymentMethod(int $serviceId, string $gatewayName): bool
    {
        try {
            // Buscar o consent para obter o psp_consent_id
            $consent = $this->getConsentByServiceId($serviceId);
            $subscriptionId = $consent['psp_consent_id'] ?? '';

            $updateData = [
                'serviceid' => $serviceId,
                'paymentmethod' => $gatewayName
            ];

            // Se temos o psp_consent_id, armazenar como subscriptionid
            if ($subscriptionId !== '') {
                $updateData['subscriptionid'] = $subscriptionId;
            }

            $result = localAPI('UpdateClientProduct', $updateData);

            if ($result['result'] === 'success') {
                Logger::log('AutoPix: Serviço atualizado com sucesso', [
                    'serviceId' => $serviceId,
                    'paymentMethod' => $gatewayName,
                    'subscriptionId' => $subscriptionId
                ]);
                return true;
            }

            Logger::log('AutoPix: Erro ao atualizar serviço', [
                'serviceId' => $serviceId,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Exceção ao atualizar serviço', [
                'serviceId' => $serviceId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Atualiza método de pagamento de um domínio
     */
    private function updateDomainPaymentMethod(int $domainId, string $gatewayName): bool
    {
        try {
            // Buscar o consent para obter o psp_consent_id
            $consent = $this->getConsentByDomainId($domainId);
            $subscriptionId = $consent['psp_consent_id'] ?? '';

            $updateData = [
                'domainid' => $domainId,
                'paymentmethod' => $gatewayName
            ];

            // Se temos o psp_consent_id, armazenar como subscriptionid
            if ($subscriptionId !== '') {
                $updateData['subscriptionid'] = $subscriptionId;
            }

            $result = localAPI('UpdateClientDomain', $updateData);

            if ($result['result'] === 'success') {
                Logger::log('AutoPix: Domínio atualizado com sucesso', [
                    'domainId' => $domainId,
                    'paymentMethod' => $gatewayName,
                    'subscriptionId' => $subscriptionId
                ]);
                return true;
            }

            Logger::log('AutoPix: Erro ao atualizar domínio', [
                'domainId' => $domainId,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Exceção ao atualizar domínio', [
                'domainId' => $domainId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Busca consent ativo pelo serviceId
     */
    private function getConsentByServiceId(int $serviceId): ?array
    {
        try {
            $consent = \WHMCS\Database\Capsule::table('mod_lknbbpix_auto_consents')
                ->where('serviceid', $serviceId)
                ->where('status', 'active')
                ->orderBy('id', 'desc')
                ->first();

            return $consent ? (array) $consent : null;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Erro ao buscar consent por serviceId', [
                'serviceId' => $serviceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Busca consent ativo pelo domainId
     */
    private function getConsentByDomainId(int $domainId): ?array
    {
        try {
            $consent = \WHMCS\Database\Capsule::table('mod_lknbbpix_auto_consents')
                ->where('domainid', $domainId)
                ->where('status', 'active')
                ->orderBy('id', 'desc')
                ->first();

            return $consent ? (array) $consent : null;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Erro ao buscar consent por domainId', [
                'domainId' => $domainId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}

