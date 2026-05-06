<?php

namespace Lkn\BBPix\App\Pix;

use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

abstract class AbstractPixApiRepository
{
    protected string $envCode;

    protected string $devAppKey;

    protected string $accessToken;

    public function __construct(string $scopes)
    {
        $this->envCode = Config::setting('env');
        $this->devAppKey = Config::setting('developer_application_key');

        $requestAccessTokenResponse = $this->requestAccessToken($scopes);

        if (!isset($requestAccessTokenResponse['access_token'])) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CREATE_ACCESS_TOKEN);
        }

        $this->accessToken = $requestAccessTokenResponse['access_token'];
    }

    protected function request(
        string $method,
        string $endpoint,
        array|string $body = [],
        array $headers = []
    ): array {
        return $this->performRequest($this->accessToken, $method, $endpoint, $body, $headers);
    }

    protected function requestWithScopes(
        string $scopes,
        string $method,
        string $endpoint,
        array|string $body = [],
        array $headers = []
    ): array {
        $requestAccessTokenResponse = $this->requestAccessToken($scopes);

        if (!isset($requestAccessTokenResponse['access_token'])) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CREATE_ACCESS_TOKEN);
        }

        return $this->performRequest(
            $requestAccessTokenResponse['access_token'],
            $method,
            $endpoint,
            $body,
            $headers
        );
    }

    protected function getResponseData(array $response): array|bool|null
    {
        return $response['data'];
    }

    protected function performRequest(
        string $accessToken,
        string $method,
        string $endpoint,
        array|string $body = [],
        array $headers = []
    ): array {
        $baseUrl = Config::constant("{$this->envCode}.baseUrl");
        $querySeparator = str_contains($endpoint, '?') ? '&' : '?';
        $endpoint = "{$endpoint}{$querySeparator}gw-dev-app-key={$this->devAppKey}";

        $requestHeaders = array_merge($headers, [
            "Authorization: Bearer {$accessToken}",
            'Content-Type: application/json'
        ]);

        return $this->httpRequest($method, $baseUrl, $endpoint, $body, $requestHeaders);
    }

    protected function logApiFailure(string $result, array $request, array $response): void
    {
        Logger::log(
            $result,
            $request,
            [
                'statusCode' => $response['statusCode'],
                'curlError' => $response['curlError'],
                'type' => $response['bbError']['type'],
                'title' => $response['bbError']['title'],
                'detail' => $response['bbError']['detail'],
                'violacoes' => $response['bbError']['violacoes'],
                'rawBody' => $response['rawBody']
            ]
        );
    }

    protected function httpRequest(
        string $method,
        string $baseUrl,
        string $endpoint,
        array|string $body = [],
        array $headers = []
    ): array {
        $request = curl_init();
        $requestUrl = "$baseUrl/$endpoint";

        $curlOptions = [
            CURLOPT_URL => $requestUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSLCERT => Config::constant('public_key_path'),
            CURLOPT_SSLKEY => Config::constant('private_key_path')
        ];

        if (count($headers) > 0) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            if ($body === []) {
                $curlOptions[CURLOPT_POSTFIELDS] = '{}';
            } elseif (is_string($body)) {
                $curlOptions[CURLOPT_POSTFIELDS] = $body;
            } else {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        curl_setopt_array($request, $curlOptions);

        $rawBody = curl_exec($request);
        $curlError = curl_error($request);
        $statusCode = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);

        curl_close($request);

        $decodedBody = is_string($rawBody) && $rawBody !== ''
            ? json_decode($rawBody, true)
            : null;

        return [
            'success' => $curlError === '' && $statusCode >= 200 && $statusCode < 300,
            'statusCode' => $statusCode,
            'data' => $decodedBody,
            'rawBody' => $rawBody,
            'curlError' => $curlError,
            'bbError' => $this->extractBbError($decodedBody, $statusCode, $curlError)
        ];
    }

    protected function requestAccessToken(string $scopes): array|bool|null
    {
        $baseUrl = Config::constant($this->envCode . '.oAuthUrl');
        $basic = ltrim(Config::setting('auth_basic'), 'Basic ');

        $headers = [
            "Authorization: Basic $basic",
            'Content-Type: application/x-www-form-urlencoded'
        ];

        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => $scopes
        ]);

        $response = $this->httpRequest(
            'POST',
            $baseUrl,
            'oauth/token',
            $body,
            $headers
        );

        Logger::log(
            'Gerar access token',
            [
                'url' => "$baseUrl/oauth/token",
                'headers' => $headers,
                'body' => $body
            ],
            $response
        );

        return $response['data'];
    }

    private function extractBbError(array|bool|null $decodedBody, int $statusCode, string $curlError): array
    {
        if ($curlError !== '') {
            return [
                'type' => 'curl_error',
                'title' => 'Erro de transporte cURL',
                'status' => $statusCode,
                'detail' => $curlError,
                'violacoes' => []
            ];
        }

        if (!is_array($decodedBody)) {
            return [
                'type' => null,
                'title' => null,
                'status' => $statusCode,
                'detail' => null,
                'violacoes' => []
            ];
        }

        return [
            'type' => $decodedBody['type'] ?? null,
            'title' => $decodedBody['title'] ?? null,
            'status' => $decodedBody['status'] ?? $statusCode,
            'detail' => $decodedBody['detail'] ?? null,
            'violacoes' => $decodedBody['violacoes'] ?? []
        ];
    }
}