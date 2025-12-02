<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

final class AutoPixApiRepository
{
    private readonly string $envCode;
    private readonly string $devAppKey;
    private readonly string $accessToken;
    // Scopes para PIX Automático - Location de Recorrência (Jornada 2)
    // Conforme doc BB: payloadlocationrec.read e payloadlocationrec.write
    private const DEFAULT_SCOPES = 'pix.read pix.write payloadlocationrec.read payloadlocationrec.write';

    public function __construct()
    {
        $this->envCode = Config::setting('env');
        $this->devAppKey = Config::setting('application_key');

        // Teste: solicitar token SEM especificar escopos (BB retorna todos os escopos disponíveis)
        Logger::log('AutoPix: Solicitando token sem escopos específicos (teste diagnóstico)', [
            'ambiente' => $this->envCode
        ]);
        
        $resp = $this->requestAccessToken('');
        if (!isset($resp['access_token'])) {
            throw new \RuntimeException('Unable to create access token for AutoPix');
        }
        $this->accessToken = $resp['access_token'];
        
        Logger::log('AutoPix: Token obtido com sucesso (sem escopos específicos)', [
            'token_length' => strlen($this->accessToken),
            'expires_in' => $resp['expires_in'] ?? 'N/A'
        ]);
    }

    private function httpRequest(
        string $method,
        string $baseUrl,
        string $endpoint,
        array|string $body = [],
        array $headers = []
    ): string|false {
        $request = curl_init();
        $requestUrl = "$baseUrl/$endpoint";

        $isProd = $this->envCode === 'prod';

        $curlOptions = [
            CURLOPT_URL => $requestUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSLCERT => Config::constant('public_key_path'),
            CURLOPT_SSLKEY => Config::constant('private_key_path'),
            CURLOPT_SSL_VERIFYPEER => $isProd,
            CURLOPT_SSL_VERIFYHOST => $isProd ? 2 : 0
        ];

        if ($headers) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            if ($body === []) {
                $curlOptions[CURLOPT_POSTFIELDS] = '{}';
            } else {
                $curlOptions[CURLOPT_POSTFIELDS] = is_string($body)
                    ? $body
                    : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        curl_setopt_array($request, $curlOptions);
        $response = curl_exec($request);
        curl_close($request);

        return $response;
    }

    private function request(
        string $method,
        string $endpoint,
        array|string $body = [],
        array $extraHeaders = [],
        ?string $idempotencyKey = null,
        string $service = 'pix'
    ): string|false {
        $baseUrlKey = $service === 'pix-recebimento' ? 'pixRecebimentoBaseUrl' : 'autoPixBaseUrl';
        $baseUrl = Config::constant("{$this->envCode}.{$baseUrlKey}");
        $appKey = trim((string) $this->devAppKey);
        if ($appKey !== '') {
            $querySeparator = str_contains($endpoint, '?') ? '&' : '?';
            $endpoint = sprintf('%s%sgw-dev-app-key=%s', $endpoint, $querySeparator, $appKey);
        }

        $headers = array_merge($extraHeaders, [
            "Authorization: Bearer {$this->accessToken}",
            'Content-Type: application/json'
        ]);

        if ($idempotencyKey) {
            $headers[] = "Idempotency-Key: {$idempotencyKey}";
        }

        return $this->httpRequest($method, $baseUrl, $endpoint, $body, $headers);
    }

    private function requestAccessToken(string $scopes): array|bool|null
    {
        $baseUrl = Config::constant($this->envCode . '.oAuthUrl');
        $basic = ltrim(Config::setting('auth_basic'), 'Basic ');

        $headers = [
            "Authorization: Basic $basic",
            'Content-Type: application/x-www-form-urlencoded'
        ];

        // Se scopes for vazio, não envia o parâmetro scope (BB retorna todos os escopos disponíveis)
        $bodyParams = ['grant_type' => 'client_credentials'];
        if (!empty($scopes)) {
            $bodyParams['scope'] = $scopes;
        }
        $body = http_build_query($bodyParams);

        $response = $this->httpRequest('POST', $baseUrl, 'oauth/token', $body, $headers);

        Logger::log('Autopix: Gerar access token', [
            'url' => "$baseUrl/oauth/token",
            'headers' => $headers,
            'body' => $body,
            'scopes_requested' => empty($scopes) ? 'TODOS (não especificado)' : $scopes,
            'response' => $response
        ], $response);

        return json_decode($response, true);
    }

    private function uuidV4(): string
    {
        $d = openssl_random_pseudo_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    // Location de Recorrência (Jornada 2)
    public function createConsent(array $body): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', 'locrec', $body, [], $key);
        Logger::log('Autopix: Criar location de recorrência', ['body' => $body, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function getConsent(string $consentId): array
    {
        $resp = $this->request('GET', "locrec/{$consentId}");
        Logger::log('Autopix: Consultar location de recorrência', ['consentId' => $consentId], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function revokeConsent(string $consentId): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('DELETE', "locrec/{$consentId}/idRec", [], [], $key);
        Logger::log('Autopix: Desvincular recorrência', ['consentId' => $consentId, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function createRecurrence(array $body): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', 'rec', $body, [], $key);
        Logger::log('Autopix: Criar recorrência', ['body' => $body, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    // Charges
    public function createCharge(array $body): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', 'charges', $body, [], $key);
        Logger::log('Autopix: Criar cobrança', ['body' => $body, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function getCharge(string $chargeId): array
    {
        $resp = $this->request('GET', "charges/{$chargeId}");
        Logger::log('Autopix: Consultar cobrança', ['chargeId' => $chargeId], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function cancelCharge(string $chargeId): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', "charges/{$chargeId}/cancel", [], [], $key);
        Logger::log('Autopix: Cancelar cobrança', ['chargeId' => $chargeId, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function refundCharge(string $chargeId, array $body = []): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', "charges/{$chargeId}/refund", $body, [], $key);
        Logger::log('Autopix: Estornar cobrança', ['chargeId' => $chargeId, 'key' => $key, 'body' => $body], $resp);
        return json_decode((string) $resp, true) ?? [];
    }

    public function sendPaymentInstruction(array $body): array
    {
        $key = $this->uuidV4();
        $resp = $this->request('POST', 'instrucao-pagamento', $body, [], $key, 'pix-recebimento');
        Logger::log('Autopix: Enviar instrução de pagamento', ['body' => $body, 'key' => $key], $resp);
        return json_decode((string) $resp, true) ?? [];
    }
} 
