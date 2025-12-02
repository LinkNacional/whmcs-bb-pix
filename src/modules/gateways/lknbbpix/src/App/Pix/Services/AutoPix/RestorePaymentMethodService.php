<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Config;

final class RestorePaymentMethodService
{
    /**
     * Restaura o método de pagamento quando o consentimento é revogado
     * 
     * @param array $consent Consent com campos: type, serviceid, domainid, clientid
     * @return bool True se atualizou com sucesso
     */
    public function run(array $consent): bool
    {
        $type = $consent['type'] ?? '';
        $serviceId = (int)($consent['serviceid'] ?? 0);
        $domainId = (int)($consent['domainid'] ?? 0);
        $clientId = (int)($consent['clientid'] ?? 0);

        // Obter configuração de fallback
        try {
            $fallbackMethod = Config::setting('autopix_consent_fallback_method');
            if (empty($fallbackMethod)) {
                $fallbackMethod = 'default';
            }
        } catch (\Exception $e) {
            $fallbackMethod = 'default';
        }

        Logger::log('AutoPix: Restaurar método de pagamento após revogação', [
            'type' => $type,
            'serviceId' => $serviceId,
            'domainId' => $domainId,
            'clientId' => $clientId,
            'fallbackMethod' => $fallbackMethod
        ]);

        // Se configurado para não alterar, retornar
        if ($fallbackMethod === 'none') {
            Logger::log('AutoPix: Configurado para não alterar método ao revogar', [
                'fallbackMethod' => $fallbackMethod
            ]);
            return true;
        }

        // Determinar qual método usar
        $newPaymentMethod = $this->getPaymentMethod($clientId, $fallbackMethod);

        if ($newPaymentMethod === '') {
            Logger::log('AutoPix: Não foi possível determinar método de pagamento', [
                'clientId' => $clientId,
                'fallbackMethod' => $fallbackMethod
            ]);
            return false;
        }

        if ($type === 'service' && $serviceId > 0) {
            return $this->updateServicePaymentMethod($serviceId, $newPaymentMethod);
        }

        if ($type === 'domain' && $domainId > 0) {
            return $this->updateDomainPaymentMethod($domainId, $newPaymentMethod);
        }

        Logger::log('AutoPix: Tipo inválido ou IDs não encontrados', [
            'consent' => $consent
        ]);

        return false;
    }

    /**
     * Determina qual método de pagamento usar
     */
    private function getPaymentMethod(int $clientId, string $fallbackMethod): string
    {
        if ($fallbackMethod === 'lknbbpix') {
            return 'lknbbpix';
        }

        if ($fallbackMethod === 'default') {
            // Buscar método padrão do cliente
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first();

            $defaultPaymentMethod = $client->defaultgateway ?? '';

            Logger::log('AutoPix: Método padrão do cliente', [
                'clientId' => $clientId,
                'defaultPaymentMethod' => $defaultPaymentMethod
            ]);

            return $defaultPaymentMethod;
        }

        return '';
    }

    /**
     * Atualiza método de pagamento de um serviço/produto
     */
    private function updateServicePaymentMethod(int $serviceId, string $gatewayName): bool
    {
        try {
            $result = localAPI('UpdateClientProduct', [
                'serviceid' => $serviceId,
                'paymentmethod' => $gatewayName,
                'subscriptionid' => '' // Limpar subscriptionid
            ]);

            if ($result['result'] === 'success') {
                Logger::log('AutoPix: Serviço restaurado com sucesso', [
                    'serviceId' => $serviceId,
                    'paymentMethod' => $gatewayName
                ]);
                return true;
            }

            Logger::log('AutoPix: Erro ao restaurar serviço', [
                'serviceId' => $serviceId,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Exceção ao restaurar serviço', [
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
            $result = localAPI('UpdateClientDomain', [
                'domainid' => $domainId,
                'paymentmethod' => $gatewayName,
                'subscriptionid' => '' // Limpar subscriptionid
            ]);

            if ($result['result'] === 'success') {
                Logger::log('AutoPix: Domínio restaurado com sucesso', [
                    'domainId' => $domainId,
                    'paymentMethod' => $gatewayName
                ]);
                return true;
            }

            Logger::log('AutoPix: Erro ao restaurar domínio', [
                'domainId' => $domainId,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            Logger::log('AutoPix: Exceção ao restaurar domínio', [
                'domainId' => $domainId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}

