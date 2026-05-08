<?php

namespace Lkn\BBPix\App\Pix;

use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Response;
use Throwable;
use WHMCS\Database\Capsule;

class PixAutoRepository extends AbstractPixApiRepository
{
    public function __construct()
    {
        parent::__construct('webhook.write cobr.read cobv.read webhookcobr.read payloadlocationrec.write payloadlocation.read cobv.write webhookrec.write cob.write rec.write webhookrec.read cobr.write solicrec.read lotecobv.write solicrec.write pix-bb.read webhooksolicrec.read pix.write payloadlocationrec.read payloadlocation.write lotecobv.read cob.read webhook.read pix.read webhooksolicrec.write webhookcobr.write rec.read');
    }

    public function criarCobV(string $txid, array $payload): array|string
    {
        return $this->handleResponse(
            'Criar cobrança cobv para Pix Automático',
            ['txid' => $txid, 'payload' => $payload],
            $this->request('PUT', "cobv/$txid", $payload)
        );
    }

    public function criarLocationRecorrencia(): array|string
    {
        return $this->handleResponse(
            'Criar location de recorrência',
            [],
            $this->request('POST', 'locrec')
        );
    }

    public function criarRecorrencia(array $payload): array|string
    {
        $payload['politicaRetentativa'] = 'NAO_PERMITE';

        return $this->handleResponse(
            'Criar recorrência Pix Automático',
            ['payload' => $payload],
            $this->request('POST', 'rec', $payload)
        );
    }

    public function obterQrCodeComposto(string $idRec, string $txid): array|string
    {
        return $this->handleResponse(
            'Obter QR Code composto da recorrência',
            ['idRec' => $idRec, 'txid' => $txid],
            $this->request('GET', "rec/$idRec?txid=$txid")
        );
    }

    public function agendarCobrancaAutomatica(string $txid, array $payload): array|string
    {
        return $this->handleResponse(
            'Agendar cobrança automática Pix',
            ['txid' => $txid, 'payload' => $payload],
            $this->request('PUT', "cobr/$txid", $payload)
        );
    }

    public function cancelarRecorrencia(string $idRec): array|string
    {
        try {
            $payload = ['status' => 'CANCELADA'];

            return $this->handleResponse(
                'Cancelar recorrência Pix Automático',
                ['idRec' => $idRec, 'payload' => $payload],
                $this->requestWithScopes(
                    'rec.write',
                    'PATCH',
                    "rec/$idRec",
                    $payload
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao cancelar recorrência Pix Automático',
                ['idRec' => $idRec],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function registrarWebhookRec(?string $webhookUrl = null): array|string
    {
        try {
            $webhookUrl = $webhookUrl ?: $this->getWebhookRecUrl();

            return $this->handleResponse(
                'Registrar webhookrec na API do BB',
                ['webhookUrl' => $webhookUrl],
                $this->requestWithScopes(
                    'webhookrec.write webhookcobr.write',
                    'PUT',
                    'webhookrec',
                    ['webhookUrl' => $webhookUrl]
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao registrar webhookrec na API do BB',
                ['webhookUrl' => $webhookUrl],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function registrarWebhookCobr(?string $webhookUrl = null): array|string
    {
        try {
            $webhookUrl = $webhookUrl ?: $this->getWebhookCobrUrl();

            return $this->handleResponse(
                'Registrar webhookcobr na API do BB',
                ['webhookUrl' => $webhookUrl],
                $this->requestWithScopes(
                    'webhookrec.write webhookcobr.write',
                    'PUT',
                    'webhookcobr',
                    ['webhookUrl' => $webhookUrl]
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao registrar webhookcobr na API do BB',
                ['webhookUrl' => $webhookUrl],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function removerWebhookRec(): array|string
    {
        try {
            return $this->handleResponse(
                'Remover webhookrec na API do BB',
                [],
                $this->requestWithScopes(
                    'webhookrec.write webhookcobr.write',
                    'DELETE',
                    'webhookrec'
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao remover webhookrec na API do BB',
                [],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function removerWebhookCobr(): array|string
    {
        try {
            return $this->handleResponse(
                'Remover webhookcobr na API do BB',
                [],
                $this->requestWithScopes(
                    'webhookrec.write webhookcobr.write',
                    'DELETE',
                    'webhookcobr'
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao remover webhookcobr na API do BB',
                [],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function consultarWebhookRec(): array|string
    {
        try {
            return $this->handleResponse(
                'Consultar webhookrec na API do BB',
                [],
                $this->requestWithScopes(
                    'webhookrec.read',
                    'GET',
                    'webhookrec'
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao consultar webhookrec na API do BB',
                [],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    public function consultarWebhookCobr(): array|string
    {
        try {
            return $this->handleResponse(
                'Consultar webhookcobr na API do BB',
                [],
                $this->requestWithScopes(
                    'webhookcobr.read',
                    'GET',
                    'webhookcobr'
                )
            );
        } catch (Throwable $th) {
            Logger::log(
                'Falha ao consultar webhookcobr na API do BB',
                [],
                ['error' => $th->getMessage()]
            );

            return Response::return(false, ['reason' => $th->getMessage()]);
        }
    }

    private function handleResponse(string $logLabel, array $request, array $response): array|string
    {
        Logger::log($logLabel, $request, $response);

        if ($response['success']) {
            return Response::return(true, is_array($response['data']) ? $response['data'] : []);
        }

        $this->logApiFailure($logLabel, $request, $response);

        return Response::return(false, [
            'statusCode' => $response['statusCode'],
            'type' => $response['bbError']['type'],
            'title' => $response['bbError']['title'],
            'detail' => $response['bbError']['detail'],
            'violacoes' => $response['bbError']['violacoes']
        ]);
    }

    private function getWebhookRecUrl(): string
    {
        $systemUrl = $this->getSystemUrl();

        return $systemUrl . '/modules/gateways/lknbbpix/webhookrec.php';
    }

    private function getWebhookCobrUrl(): string
    {
        $systemUrl = $this->getSystemUrl();

        return $systemUrl . '/modules/gateways/lknbbpix/webhookcobr.php';
    }

    private function getSystemUrl(): string
    {
        return rtrim((string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value'), '/');
    }
}