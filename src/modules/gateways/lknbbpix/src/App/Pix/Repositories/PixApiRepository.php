<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Exceptions\PixExceptionCodes;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;

/**
 * Provides methods for communicating with Chatwoot API.
 *
 * The methods return the raw response of the API.
 *
 * @since 1.2.0
 */
class PixApiRepository
{
    /**
     * @since 1.2.0
     * @var string
     */
    private readonly string $envCode;

    /**
     * @since 1.2.0
     * @var string
     */
    private readonly string $devAppKey;

    /**
     * Holds the token with scopes that will be used for all current requests
     * from the context.
     *
     * @since 1.2.0
     * @var string
     */
    private readonly string $accessToken;

    /**
     * Requests the access_token for the requests. So, Avoid instantiating this
     * class many times to reduce wait time.
     *
     * @since 1.2.0
     */
    public function __construct()
    {
        $this->envCode = Config::setting('env');
        $this->devAppKey = Config::setting('application_key');

        $requestAccessTokenResponse = $this->requestAccessToken('cob.read cob.write pix.read pix.write');

        if (!isset($requestAccessTokenResponse['access_token'])) {
            throw new PixException(PixExceptionCodes::COULD_NOT_CREATE_ACCESS_TOKEN);
        }

        $this->accessToken = $requestAccessTokenResponse['access_token'];
    }

    /**
     * @since 1.2.0
     *
     * @param string       $method
     * @param string       $baseUrl
     * @param string       $endpoint
     * @param array|string $body
     * @param array        $headers
     *
     * @return string|false false may be returned also due to  problems.
     */
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

        // TODO Attention if you are using sandbox environment
        // Disable ssl certificates verification
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

        if (count($headers) > 0) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            if ($body === []) {
                $curlOptions[CURLOPT_POSTFIELDS] = '{}';
            } else {
                if (is_string($body)) {
                    $curlOptions[CURLOPT_POSTFIELDS] = $body;
                } else {
                    $curlOptions[CURLOPT_POSTFIELDS] = json_encode(
                        $body,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
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
        array $header = []
    ): string|false {
        $baseUrl = Config::constant("{$this->envCode}.baseUrl");

        $appKey = trim((string) $this->devAppKey);
        if ($appKey !== '') {
            $querySeparator = str_contains($endpoint, '?') ? '&' : '?';
            $endpoint = sprintf('%s%sgw-dev-app-key=%s', $endpoint, $querySeparator, $appKey);
        }

        $headers = array_merge($header, [
            "Authorization: Bearer {$this->accessToken}",
            'Content-Type: application/json'
        ]);

        $response = $this->httpRequest(
            $method,
            $baseUrl,
            $endpoint,
            $body,
            $headers
        );

        return $response;
    }

    private function jsonDecode(string $string): array|bool|null
    {
        return json_decode($string, true);
    }

    /**
     * @since 1.2.0
     *
     * @link https://apoio.developers.bb.com.br/referency/post/5f4f8169b71fb5001268c9a1
     *
     * @param string $scopes
     *
     * @return array|bool|null
     */
    private function requestAccessToken(string $scopes): array|bool|null
    {
        $baseUrl = Config::constant($this->envCode . '.oAuthUrl');

        $basic = ltrim(Config::setting('auth_basic'), 'Basic ');

        // Note: the Basic is required for the access_token requests.
        // The others requests use the access_token in Authorization header.
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
                'body' => $body,
                'response' => $response
            ],
            $response,
        );

        return $this->jsonDecode($response);
    }

    public function createPix(string $txId, array $body): array|bool|null
    {
        $response = $this->request('PUT', "cob/$txId", $body);

        Logger::log(
            'Criar cobrança Pix',
            ['txId' => $txId, 'body' => $body],
            $response
        );

        return $this->jsonDecode($response);
    }

    public function consultPix(PixTaxId $taxId): array|bool|null
    {
        $taxId = $taxId->getApiTransId();

        $response = $this->request('GET', "cob/$taxId");

        Logger::log('Consultar Pix', ['txId' => $taxId], $response);

        return $this->jsonDecode($response);
    }

    public function requestRefund(
        string $e2eid,
        string $refundValue
    ): array|bool|null {
        $txid = bin2hex(openssl_random_pseudo_bytes(17));

        $response = $this->request(
            'PUT',
            "pix/$e2eid/devolucao/$txid",
            ['valor' => $refundValue]
        );

        Logger::log(
            'Solicitar reembolso',
            [
                'e2eid' => $e2eid,
                'txId' => $txid,
                'refundValue' => $refundValue
            ],
            $response
        );

        return $this->jsonDecode($response);
    }

    public function cancelPix(PixTaxId $taxId): array|bool|null
    {
        $txId = $taxId->getApiTransId();

        $response = $this->request(
            'PATCH',
            "cob/$txId",
            ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']
        );

        Logger::log(
            'Cancelar cobrança Pix',
            ['txId' => $txId],
            $response
        );

        return $this->jsonDecode($response);
    }
}
