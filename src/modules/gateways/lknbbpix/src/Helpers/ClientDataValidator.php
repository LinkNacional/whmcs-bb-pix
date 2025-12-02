<?php

namespace Lkn\BBPix\Helpers;

use WHMCS\Database\Capsule;

final class ClientDataValidator
{
    private string $error = '';

    /**
     * Valida se o cliente possui CPF ou CNPJ válido cadastrado
     *
     * @param int $clientId
     * @return bool
     */
    public function validate(int $clientId): bool
    {
        // Buscar custom fields do cliente
        $client = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        
        if ($client['result'] !== 'success') {
            $this->error = 'Cliente não encontrado.';
            return false;
        }

        $customFields = $client['customfields'] ?? [];
        
        // Buscar configurações do gateway para saber quais custom fields usar
        $cpfCnpjCfId = Config::setting('cpf_cnpj_cf_id');
        $cpfCfId = Config::setting('cpf_cf_id');
        $cnpjCfId = Config::setting('cnpj_cf_id');

        $docValue = null;
        $docType = null;

        // Tentar campo misto CPF/CNPJ primeiro
        if (!empty($cpfCnpjCfId)) {
            $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cpfCnpjCfId));
            if ($field && !empty($field['value'])) {
                $docValue = trim($field['value']);
                
                if (Validator::cpf($docValue)) {
                    $docType = 'cpf';
                } elseif (Validator::cnpj($docValue)) {
                    $docType = 'cnpj';
                }
            }
        }

        // Se não encontrou no campo misto, tentar campos separados
        if (!$docType) {
            // Tentar CNPJ
            if (!empty($cnpjCfId)) {
                $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cnpjCfId));
                if ($field && !empty($field['value'])) {
                    $docValue = trim($field['value']);
                    if (Validator::cnpj($docValue)) {
                        $docType = 'cnpj';
                    }
                }
            }

            // Tentar CPF
            if (!$docType && !empty($cpfCfId)) {
                $field = current(array_filter($customFields, fn($cf) => (int)($cf['id']) === (int)$cpfCfId));
                if ($field && !empty($field['value'])) {
                    $docValue = trim($field['value']);
                    if (Validator::cpf($docValue)) {
                        $docType = 'cpf';
                    }
                }
            }
        }

        if (!$docType || !$docValue) {
            $this->error = 'CPF ou CNPJ não encontrado ou inválido. Por favor, atualize seus dados cadastrais.';
            return false;
        }

        return true;
    }

    /**
     * Retorna a mensagem de erro da última validação
     *
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }
}

