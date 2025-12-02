<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Validator;
use Lkn\BBPix\Helpers\Formatter;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

final class BuildConsentPayloadService
{
    /**
     * Constrói o payload para criação de consentimento (Jornada 2)
     *
     * @param int $clientId
     * @param int|null $serviceId
     * @param int|null $domainId
     * @param string $type 'service' ou 'domain'
     * @return array
     * @throws \RuntimeException
     */
    public function build(int $clientId, ?int $serviceId, ?int $domainId, string $type): array
    {
        $clientData = $this->getClientData($clientId);
        $receiverData = $this->getReceiverData();
        $itemData = $this->getItemData($serviceId, $domainId, $type);
        $dataInicial = $this->calculateInitialDate();

        $periodicidade = $this->mapBillingCycleToPeriodicidade($itemData['ciclo']);

        $payload = [
            'criacao' => sprintf(
                'PIX Automático - %s - Cliente: %s - Valor: R$ %s %s',
                $itemData['nome'],
                $clientData['nome'],
                number_format($itemData['valor'], 2, ',', '.'),
                $periodicidade
            )
        ];

        $context = [
            'payload' => $payload,
            'client' => $clientData,
            'receiver' => $receiverData,
            'item' => [
                'nome' => $itemData['nome'],
                'valor' => $itemData['valor'],
                'valorRec' => $itemData['valor'] > 0 ? number_format($itemData['valor'], 2, '.', '') : null,
                'ciclo' => $itemData['ciclo'],
                'periodicidade' => $periodicidade,
                'contrato' => $itemData['contrato'],
                'objeto' => $itemData['objeto'],
            ],
            'calendar' => [
                'dataInicial' => $dataInicial,
                'dataFinal' => null,
                'periodicidade' => $periodicidade,
            ],
            'retentativa' => 'NAO_PERMITE',
        ];

        Logger::log('AutoPix: Payload construído', [
            'clientId' => $clientId,
            'serviceId' => $serviceId,
            'domainId' => $domainId,
            'type' => $type
        ], $context['payload']);

        return $context;
    }

    /**
     * Busca dados do cliente (pagador)
     */
    private function getClientData(int $clientId): array
    {
        $client = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        
        if ($client['result'] !== 'success') {
            throw new \RuntimeException('Cliente não encontrado.');
        }

        $customFields = $client['customfields'] ?? [];
        
        // Buscar CPF/CNPJ usando configurações do gateway
        $cpfCnpjCfId = Config::setting('cpf_cnpj_cf_id');
        $cpfCfId = Config::setting('cpf_cf_id');
        $cnpjCfId = Config::setting('cnpj_cf_id');

        $docValue = null;
        $docType = null;

        // Tentar campo misto
        if (!empty($cpfCnpjCfId)) {
            $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cpfCnpjCfId));
            if ($field && !empty($field['value'])) {
                $docValue = Formatter::removeNonNumber(trim($field['value']));
                
                if (Validator::cpf($docValue)) {
                    $docType = 'cpf';
                } elseif (Validator::cnpj($docValue)) {
                    $docType = 'cnpj';
                }
            }
        }

        // Campos separados
        if (!$docType) {
            if (!empty($cnpjCfId)) {
                $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cnpjCfId));
                if ($field && !empty($field['value'])) {
                    $docValue = Formatter::removeNonNumber(trim($field['value']));
                    if (Validator::cnpj($docValue)) {
                        $docType = 'cnpj';
                    }
                }
            }

            if (!$docType && !empty($cpfCfId)) {
                $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cpfCfId));
                if ($field && !empty($field['value'])) {
                    $docValue = Formatter::removeNonNumber(trim($field['value']));
                    if (Validator::cpf($docValue)) {
                        $docType = 'cpf';
                    }
                }
            }
        }

        if (!$docType || !$docValue) {
            throw new \RuntimeException('CPF ou CNPJ do cliente não encontrado ou inválido.');
        }

        // Nome do cliente
        $nome = $docType === 'cnpj' && !empty($client['companyname']) 
            ? $client['companyname'] 
            : trim($client['firstname'] . ' ' . $client['lastname']);

        $fullName = $docType === 'cnpj' && !empty($client['companyname']) 
            ? $client['companyname'] 
            : trim($client['firstname'] . ' ' . $client['lastname']);

        return [
            'nome' => Formatter::name($nome),
            'documento' => $docValue,
            'tipo_documento' => $docType,
            'nome_completo' => Formatter::name($fullName)
        ];
    }

    /**
     * Busca dados do recebedor (configuração do gateway)
     */
    private function getReceiverData(): array
    {
        // Buscar chave PIX recebedora (pode ser CPF, CNPJ ou chave aleatória)
        $pixKey = Config::setting('receiver_pix_key');
        
        if (empty($pixKey)) {
            throw new \RuntimeException('Chave PIX recebedora não configurada.');
        }

        // Primeiro tenta validar como chave aleatória (EVP)
        if (Validator::pixRandomKey($pixKey)) {
            // Para chaves aleatórias, não precisamos retornar documento
            // apenas a chave PIX em si
            $companyName = Capsule::table('tblconfiguration')
                ->where('setting', 'CompanyName')
                ->value('value');

            return [
                'nome' => Formatter::name($companyName ?: 'Empresa'),
                'documento' => null,
                'tipo_documento' => 'evp',
                'chave' => strtolower(trim($pixKey))
            ];
        }

        // Se não for chave aleatória, valida como CPF ou CNPJ
        $pixKeyNumeric = Formatter::removeNonNumber($pixKey);
        
        $docType = null;
        if (Validator::cpf($pixKeyNumeric)) {
            $docType = 'cpf';
        } elseif (Validator::cnpj($pixKeyNumeric)) {
            $docType = 'cnpj';
        } else {
            throw new \RuntimeException('Chave PIX recebedora inválida (deve ser CPF, CNPJ ou chave aleatória).');
        }

        // Buscar nome do recebedor (pode estar em outra configuração ou usar nome da empresa do WHMCS)
        $companyName = Capsule::table('tblconfiguration')
            ->where('setting', 'CompanyName')
            ->value('value');

        return [
            'nome' => Formatter::name($companyName ?: 'Empresa'),
            'documento' => $pixKeyNumeric,
            'tipo_documento' => $docType,
            'chave' => $pixKeyNumeric
        ];
    }

    /**
     * Busca dados do serviço ou domínio
     */
    private function getItemData(?int $serviceId, ?int $domainId, string $type): array
    {
        if ($type === 'service' && $serviceId) {
            $service = localAPI('GetClientsProducts', ['serviceid' => $serviceId]);
            
            if ($service['result'] !== 'success' || empty($service['products']['product'][0])) {
                throw new \RuntimeException('Serviço não encontrado.');
            }

            $product = $service['products']['product'][0];
            
            $productName = $this->truncate((string) ($product['name'] ?? 'Serviço'), 35);
            $groupName = trim((string) ($product['groupname'] ?? ''));
            $fullLabel = $groupName !== '' ? $this->truncate($groupName . ' - ' . $productName, 35) : $productName;

            return [
                'nome' => $productName,
                'valor' => (float)($product['recurringamount'] ?? 0),
                'ciclo' => $product['billingcycle'] ?? 'Monthly',
                'contrato' => $this->truncate('SERV-' . $serviceId, 35),
                'objeto' => $fullLabel
            ];
        } elseif ($type === 'domain' && $domainId) {
            $domain = localAPI('GetClientsDomains', ['domainid' => $domainId]);
            
            if ($domain['result'] !== 'success' || empty($domain['domains']['domain'][0])) {
                throw new \RuntimeException('Domínio não encontrado.');
            }

            $domainData = $domain['domains']['domain'][0];
            $registrationPeriod = (int)($domainData['registrationperiod'] ?? 1);
            $domainName = $this->truncate((string) ($domainData['domainname'] ?? 'Domínio'), 35);
            
            return [
                'nome' => $domainName,
                'valor' => (float)($domainData['recurringamount'] ?? 0),
                'ciclo' => $registrationPeriod > 1 ? 'Annually' : 'Annually',
                'contrato' => $this->truncate('DOM-' . $domainName, 35),
                'objeto' => $domainName
            ];
        }

        throw new \RuntimeException('Tipo de item inválido.');
    }

    /**
     * Mapeia ciclo de cobrança do WHMCS para periodicidade da API
     */
    private function mapBillingCycleToPeriodicidade(string $cycle): string
    {
        return match($cycle) {
            'Monthly' => 'MENSAL',
            'Quarterly' => 'TRIMESTRAL',
            'Semi-Annually' => 'SEMESTRAL',
            'Annually', 'Biennially', 'Triennially' => 'ANUAL',
            default => 'MENSAL'
        };
    }

    /**
     * Calcula data inicial (mínimo D+1)
     */
    private function calculateInitialDate(): string
    {
        // Data inicial: próximo dia útil após hoje
        $date = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $date->modify('+2 days'); // Mínimo 2 dias para dar tempo de aprovar
        
        // Se cair em final de semana, pular para segunda
        if ($date->format('N') == 6) { // Sábado
            $date->modify('+2 days');
        } elseif ($date->format('N') == 7) { // Domingo
            $date->modify('+1 day');
        }
        
        return $date->format('Y-m-d');
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit);
    }
}
