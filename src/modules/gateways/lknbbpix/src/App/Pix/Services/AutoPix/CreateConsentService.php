<?php

namespace Lkn\BBPix\App\Pix\Services\AutoPix;

use Lkn\BBPix\App\Pix\Repositories\AutoPixApiRepository;
use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;
use Lkn\BBPix\Helpers\BrCode;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

final class CreateConsentService
{
    public function run(array $input): array
    {
        try {
            $clientId = (int) $input['clientid'];
            $serviceId = !empty($input['serviceid']) ? (int)$input['serviceid'] : null;
            $domainId = !empty($input['domainid']) ? (int)$input['domainid'] : null;
            $type = $input['type'] ?? 'service';

            // Construir payload usando BuildConsentPayloadService
            $payloadBuilder = new BuildConsentPayloadService();
            $payloadContext = $payloadBuilder->build($clientId, $serviceId, $domainId, $type);
            $payload = $payloadContext['payload'];

            // Criar location de recorrência na API
            $api = new AutoPixApiRepository();
            $response = $api->createConsent($payload);

            if (!isset($response['id']) || !isset($response['location'])) {
                Logger::log('AutoPix: Erro ao criar location - resposta inválida', [
                    'payload' => $payload,
                    'response' => $response
                ]);
                return ['success' => false, 'error' => 'Erro ao criar location na API', 'raw' => $response];
            }

            $merchantProfile = $this->getMerchantProfile();

            try {
                $pixBrCode = BrCode::fromLocation(
                    (string) $response['location'],
                    $merchantProfile['name'],
                    $merchantProfile['city']
                );
            } catch (\Throwable $e) {
                Logger::log('AutoPix: Erro ao compor BR Code', [
                    'location' => $response['location'] ?? null,
                    'merchant' => $merchantProfile,
                    'error' => $e->getMessage()
                ]);

                return ['success' => false, 'error' => 'Erro ao gerar QR Code do consentimento'];
            }

            // Extrair dados da resposta
            // API /locrec retorna: id, location, criacao
            $consentId = $response['id'];
            $pixLocation = $response['location'];
            $idRec = $response['idRec'] ?? null;
            $idRecTipo = $idRec ? strtoupper($idRec[1] ?? '') : null;

            Logger::log('AutoPix: Location criada com sucesso', [
                'locationId' => $consentId,
                'location' => $pixLocation,
                'idRec' => $idRec,
                'response' => $response
            ]);

            $recurrencePayload = $this->buildRecurrencePayload(
                $payloadContext,
                (int) $consentId
            );

            $recurrenceResponse = $api->createRecurrence($recurrencePayload);

            if (!isset($recurrenceResponse['idRec'])) {
                Logger::log('AutoPix: Erro ao criar recorrência', [
                    'payload' => $recurrencePayload,
                    'response' => $recurrenceResponse
                ]);

                return ['success' => false, 'error' => 'Erro ao registrar recorrência na API'];
            }

            $idRec = $recurrenceResponse['idRec'];
            $idRecTipo = strtoupper($idRec[1] ?? '');

            Logger::log('AutoPix: Recorrência criada com sucesso', [
                'consentId' => $consentId,
                'idRec' => $idRec,
                'payload' => $recurrencePayload
            ], $recurrenceResponse);

            $clientInfo = $payloadContext['client'];
            $itemInfo = $payloadContext['item'];

            // Salvar no banco
            $repo = new AutoPixConsentRepository();
            $repo->insert([
                'clientid' => $clientId,
                'serviceid' => $serviceId,
                'domainid' => $domainId,
                'type' => $type,
                'status' => 'pending',
                'psp_consent_id' => $consentId,
                'id_rec' => $idRec,
                'id_rec_tipo' => $idRecTipo,
                'created_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode([
                    'payload' => $payload,
                    'response' => $response,
                    'recurrence_request' => $recurrencePayload,
                    'recurrence_response' => $recurrenceResponse,
                    'qr' => [
                        'location' => $pixLocation,
                        'pixCopiaECola' => $pixBrCode,
                        'merchantName' => $merchantProfile['name'],
                        'merchantCity' => $merchantProfile['city'],
                        'client' => $clientInfo,
                        'item' => $itemInfo,
                    ]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            Logger::log('AutoPix: Consentimento criado com sucesso', [
                'consentId' => $consentId,
                'clientId' => $clientId,
                'serviceId' => $serviceId,
                'domainId' => $domainId
            ], $response);

            return [
                'success' => true,
                'consentId' => $consentId,
                'pixCopiaECola' => $pixBrCode
            ];
        } catch (\Throwable $e) {
            Logger::log('AutoPix create consent error', [
                'input' => $input,
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getMerchantProfile(): array
    {
        $configuredName = (string) Config::setting('autopix_merchant_name');
        $configuredCity = (string) Config::setting('autopix_merchant_city');

        $name = trim($configuredName);
        $city = trim($configuredCity);

        if ($name === '' || $city === '') {
            $settings = Capsule::table('tblconfiguration')
                ->whereIn('setting', ['CompanyName', 'CompanyCity'])
                ->pluck('value', 'setting')
                ->all();

            if ($name === '') {
                $name = (string) ($settings['CompanyName'] ?? '');
            }

            if ($city === '') {
                $city = (string) ($settings['CompanyCity'] ?? '');
            }
        }

        return [
            'name' => BrCode::sanitizeMerchantName($name),
            'city' => BrCode::sanitizeMerchantCity($city),
        ];
    }

    private function buildRecurrencePayload(array $context, int $locId): array
    {
        $client = $context['client'];
        $item = $context['item'];
        $calendar = $context['calendar'];
        $retentativa = $context['retentativa'] ?? 'NAO_PERMITE';

        $debtor = [
            'nome' => $client['nome'],
        ];

        if ($client['tipo_documento'] === 'cnpj') {
            $debtor['cnpj'] = $client['documento'];
        } else {
            $debtor['cpf'] = $client['documento'];
        }

        $payload = [
            'vinculo' => [
                'contrato' => $item['contrato'],
                'devedor' => $debtor,
                'objeto' => $item['objeto'],
            ],
            'calendario' => [
                'dataInicial' => $calendar['dataInicial'],
                'periodicidade' => $calendar['periodicidade'],
            ],
            'politicaRetentativa' => $retentativa,
            'loc' => $locId,
        ];

        if (!empty($calendar['dataFinal'])) {
            $payload['calendario']['dataFinal'] = $calendar['dataFinal'];
        }

        if (!empty($item['valorRec'])) {
            $payload['valor']['valorRec'] = $item['valorRec'];
        }

        return $payload;
    }
}
