<?php

namespace Lkn\BBPix\Helpers;

use WHMCS\Database\Capsule;

final class PayerResolver
{
    public const PROFILE_UPDATE_REQUIRED_CODE = 'profile_update_required';

    public const PROFILE_UPDATE_REQUIRED_MESSAGE = 'Erro: Atualize os dados Perfil para Gerar um PIX.';

    public const PROFILE_DETAILS_URL = '/clientarea.php?action=details';

    public static function resolveFromGatewayParams(array $params, bool $requireDocument = false): array
    {
        return self::resolveClientData(
            [
                'firstname' => $params['clientdetails']['firstname'] ?? '',
                'lastname' => $params['clientdetails']['lastname'] ?? '',
                'companyname' => $params['clientdetails']['companyname'] ?? '',
                'customfields' => $params['clientdetails']['customfields'] ?? [],
            ],
            [
                'cnpj_cf_id' => (int) ($params['cnpj_cf_id'] ?? 0),
                'cpf_cf_id' => (int) ($params['cpf_cf_id'] ?? 0),
                'cpf_cnpj_cf_id' => (int) ($params['cpf_cnpj_cf_id'] ?? 0),
            ],
            $requireDocument
        );
    }

    public static function resolveForClientId(int $clientId, bool $requireDocument = false): array
    {
        $client = Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['firstname', 'lastname', 'companyname']);

        if (!$client) {
            return self::profileUpdateRequired();
        }

        $settings = [
            'cnpj_cf_id' => (int) Config::setting('cnpj_cf_id'),
            'cpf_cf_id' => (int) Config::setting('cpf_cf_id'),
            'cpf_cnpj_cf_id' => (int) Config::setting('cpf_cnpj_cf_id'),
        ];

        $fieldIds = array_values(array_filter([
            $settings['cnpj_cf_id'],
            $settings['cpf_cf_id'],
            $settings['cpf_cnpj_cf_id'],
        ]));

        $customFields = [];

        if ($fieldIds !== []) {
            $customFieldRows = Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $clientId)
                ->whereIn('fieldid', $fieldIds)
                ->get(['fieldid', 'value']);

            foreach ($customFieldRows as $customFieldRow) {
                $customFields[] = [
                    'id' => (int) $customFieldRow->fieldid,
                    'value' => (string) $customFieldRow->value,
                ];
            }
        }

        return self::resolveClientData(
            [
                'firstname' => $client->firstname ?? '',
                'lastname' => $client->lastname ?? '',
                'companyname' => $client->companyname ?? '',
                'customfields' => $customFields,
            ],
            $settings,
            $requireDocument
        );
    }

    public static function resolveClientData(array $clientData, array $settings, bool $requireDocument = false): array
    {
        $customFields = self::normalizeCustomFields($clientData['customfields'] ?? []);

        $document = self::resolveDocument($customFields, $settings);

        if ($requireDocument && ($document['type'] === '' || $document['value'] === '')) {
            return self::profileUpdateRequired();
        }

        $clientFullName = self::resolveClientName($clientData, $document['type']);

        if ($requireDocument && $clientFullName === '') {
            return self::profileUpdateRequired();
        }

        return [
            'success' => true,
            'data' => [
                'clientFullName' => $clientFullName,
                'payerDocType' => $document['type'],
                'payerDocValue' => $document['value'],
            ],
        ];
    }

    public static function profileUpdateRequired(): array
    {
        return [
            'success' => false,
            'data' => [
                'code' => self::PROFILE_UPDATE_REQUIRED_CODE,
                'error' => self::PROFILE_UPDATE_REQUIRED_MESSAGE,
                'profileUrl' => self::PROFILE_DETAILS_URL,
            ],
        ];
    }

    private static function normalizeCustomFields(array $customFields): array
    {
        $normalized = [];

        foreach ($customFields as $key => $customField) {
            if (is_array($customField) && array_key_exists('id', $customField)) {
                $normalized[(int) $customField['id']] = trim((string) ($customField['value'] ?? ''));
                continue;
            }

            $normalized[(int) $key] = trim((string) $customField);
        }

        return $normalized;
    }

    private static function resolveDocument(array $customFields, array $settings): array
    {
        $combinedFieldId = (int) ($settings['cpf_cnpj_cf_id'] ?? 0);

        if ($combinedFieldId > 0) {
            $combinedValue = Formatter::removeNonNumber((string) ($customFields[$combinedFieldId] ?? ''));

            if (Validator::cnpj($combinedValue)) {
                return ['type' => 'cnpj', 'value' => $combinedValue];
            }

            if (Validator::cpf($combinedValue)) {
                return ['type' => 'cpf', 'value' => $combinedValue];
            }
        }

        $cnpjFieldId = (int) ($settings['cnpj_cf_id'] ?? 0);
        $cnpjValue = Formatter::removeNonNumber((string) ($customFields[$cnpjFieldId] ?? ''));

        if (Validator::cnpj($cnpjValue)) {
            return ['type' => 'cnpj', 'value' => $cnpjValue];
        }

        $cpfFieldId = (int) ($settings['cpf_cf_id'] ?? 0);
        $cpfValue = Formatter::removeNonNumber((string) ($customFields[$cpfFieldId] ?? ''));

        if (Validator::cpf($cpfValue)) {
            return ['type' => 'cpf', 'value' => $cpfValue];
        }

        return ['type' => '', 'value' => ''];
    }

    private static function resolveClientName(array $clientData, string $documentType): string
    {
        $name = '';

        if ($documentType === 'cnpj') {
            $name = trim((string) ($clientData['companyname'] ?? ''));
        }

        if ($name === '') {
            $firstName = trim((string) ($clientData['firstname'] ?? ''));
            $lastName = trim((string) ($clientData['lastname'] ?? ''));
            $name = trim("{$firstName} {$lastName}");
        }

        if ($name === '') {
            return '';
        }

        return Formatter::name(mb_substr($name, 0, 200));
    }
}