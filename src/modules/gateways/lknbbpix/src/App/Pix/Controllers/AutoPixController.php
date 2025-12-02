<?php

namespace Lkn\BBPix\App\Pix\Controllers;

use Lkn\BBPix\App\Pix\Repositories\AutoPixConsentRepository;
use Lkn\BBPix\App\Pix\Services\AutoPix\CreateConsentService;
use Lkn\BBPix\App\Pix\Services\AutoPix\GenerateQRCodeService;
use Lkn\BBPix\App\Pix\Services\AutoPix\RevokeConsentService;
use Lkn\BBPix\Helpers\BrCode;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\ClientDataValidator;
use Lkn\BBPix\Helpers\View;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

final class AutoPixController
{
    public function index(int $clientId, ?int $serviceId = null, ?int $domainId = null): string
    {
        $repo = new AutoPixConsentRepository();
        $consents = $repo->listByClient($clientId);

        return View::render('autopix.index', [
            'consents' => $consents,
            'serviceId' => $serviceId,
            'domainId' => $domainId
        ]);
    }

    public function start(array $input): array
    {
        try {
            // Validar dados do cliente
            $clientId = (int)($input['clientid'] ?? 0);
            
            if ($clientId <= 0) {
                return ['success' => false, 'error' => 'Cliente inválido.'];
            }

            $validator = new ClientDataValidator();
            if (!$validator->validate($clientId)) {
                return ['success' => false, 'error' => $validator->getError()];
            }

            // Criar consentimento
            $result = (new CreateConsentService())->run($input);

            if (!$result['success']) {
                return $result;
            }

            // Gerar QR Code
            $qrcodeService = new GenerateQRCodeService();
            $qrcodeImage = $qrcodeService->generate($result['pixCopiaECola']);

            return [
                'success' => true,
                'consentId' => $result['consentId'],
                'qrcodeImage' => $qrcodeImage,
                'pixCopiaECola' => $result['pixCopiaECola']
            ];
        } catch (\Throwable $e) {
            Logger::log('AutoPix Controller: Erro em start()', [
                'input' => $input,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function recover(array $input): array
    {
        try {
            $clientId = (int)($input['clientid'] ?? 0);
            $serviceId = !empty($input['serviceid']) ? (int)$input['serviceid'] : null;
            $domainId = !empty($input['domainid']) ? (int)$input['domainid'] : null;
            
            if ($clientId <= 0) {
                return ['success' => false, 'error' => 'Cliente inválido.'];
            }

            // Buscar consentimento pendente no banco
            $repo = new AutoPixConsentRepository();
            $consent = $repo->findPendingConsent($clientId, $serviceId, $domainId);
            
            if (!$consent) {
                return ['success' => false, 'error' => 'Nenhum consentimento pendente encontrado.'];
            }

            // Extrair dados do metadata
            $metadata = json_decode($consent['metadata'], true);
            $qrMetadata = is_array($metadata['qr'] ?? null) ? $metadata['qr'] : [];
            $pixCopiaECola = $qrMetadata['pixCopiaECola'] ?? null;
            $location = $qrMetadata['location'] ?? ($metadata['response']['location'] ?? null);
            
            if (!$pixCopiaECola && $location) {
                try {
                    $profile = $this->resolveMerchantProfile($qrMetadata);
                    $pixCopiaECola = BrCode::fromLocation($location, $profile['name'], $profile['city']);
                } catch (\Throwable $e) {
                    Logger::log('AutoPix Controller: Erro ao reconstruir BR Code', [
                        'metadata' => $metadata,
                        'error' => $e->getMessage()
                    ]);

                    return ['success' => false, 'error' => 'Dados do QR Code não encontrados.'];
                }
            }

            if (!$pixCopiaECola) {
                return ['success' => false, 'error' => 'Dados do QR Code não encontrados.'];
            }

            // Gerar QR Code novamente
            $qrcodeService = new GenerateQRCodeService();
            $qrcodeImage = $qrcodeService->generate($pixCopiaECola);

            return [
                'success' => true,
                'qrcodeImage' => $qrcodeImage,
                'pixCopiaECola' => $pixCopiaECola
            ];
        } catch (\Throwable $e) {
            Logger::log('AutoPix Controller: Erro em recover()', [
                'input' => $input,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function revoke(string $pspConsentId): array
    {
        return (new RevokeConsentService())->run($pspConsentId);
    }

    private function resolveMerchantProfile(array $qrMetadata = []): array
    {
        $name = $qrMetadata['merchantName'] ?? null;
        $city = $qrMetadata['merchantCity'] ?? null;

        if (is_string($name) && is_string($city) && $name !== '' && $city !== '') {
            return [
                'name' => $name,
                'city' => $city,
            ];
        }

        $configuredName = trim((string) Config::setting('autopix_merchant_name'));
        $configuredCity = trim((string) Config::setting('autopix_merchant_city'));

        if ($configuredName !== '' && $configuredCity !== '') {
            return [
                'name' => BrCode::sanitizeMerchantName($configuredName),
                'city' => BrCode::sanitizeMerchantCity($configuredCity),
            ];
        }

        $settings = Capsule::table('tblconfiguration')
            ->whereIn('setting', ['CompanyName', 'CompanyCity'])
            ->pluck('value', 'setting')
            ->all();

        return [
            'name' => BrCode::sanitizeMerchantName(
                $configuredName !== '' ? $configuredName : ($settings['CompanyName'] ?? '')
            ),
            'city' => BrCode::sanitizeMerchantCity(
                $configuredCity !== '' ? $configuredCity : ($settings['CompanyCity'] ?? '')
            ),
        ];
    }
}
