<?php

/**
 * This file handle requests that come primary from the module configuration pages.
 *
 * These requests are made by JS fetch(). They are primary located in /resources/js
 * and others are located directly in a .tpl file.
 */

use Lkn\BBPix\App\Pix\Controllers\ApiController;
use Lkn\BBPix\App\Pix\Controllers\DiscountController;
use Lkn\BBPix\App\Pix\Controllers\PixController;
use Lkn\BBPix\Helpers\Auth;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Response;
use Lkn\BBPix\Helpers\Validator;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\Formatter;
use Lkn\BBPix\App\Pix\Exceptions\PixException;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/vendor/autoload.php';

$request = json_decode(file_get_contents('php://input')) ?? (object) ($_POST);

header('Content-Type: application/json;');

$authState = new CurrentUser();

switch ($request->action) {
    // The form request this endpoint to check if the invoice is paid each 18 seconds.
    case 'check-invoice-status':
        if (!isset($request->token) || $request->token !== $_SESSION['lkn-bb-pix']) {
            exit('token invalido.');
        }

        $invoiceId = $request->invoiceId;

        $isInvoicePaid = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->where('status', 'Paid')
            ->exists();

        http_response_code(200);
        Response::api(true, ['isInvoicePaid' => $isInvoicePaid]);

        break;

    case 'manual-payment-confirmation':

        if (!($authState->admin() || $authState->client())) {
            exit;
        }

        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        http_response_code(200);
        return (new PixController($cobType))->checkAndConfirmInvoicePayment($request->invoiceId);

        break;

    case 'reemit-pix':
        if (!($authState->admin() || $authState->client())) {
            exit;
        }
        $invoiceId = (int) $request->invoiceId;
    
        // Verificar se a fatura usa nosso gateway de pagamento
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        
        // Verificar se a fatura está com status "Unpaid"
        if ($invoice['status'] !== 'Unpaid') {
            Response::api(false, ['Erro ao reemitir pix' => "Erro, a fatura deve estar com status Unpaid"]);
            return;
        }
        
        try {
            $clientId = $invoice['userid'];
            $client = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
            if ($client['result'] !== 'success') {
                Response::api(false, ['Erro ao reemitir pix' => "Erro ao obter os detalhes do cliente"]);
                return;
            }
    
            // Buscar configurações via helper Config
            $params = [
                'invoiceid' => $invoiceId,
                'amount' => $invoice['balance'],
                'description' => $invoice['title'] ?? '',
                'currency' => $invoice['currencycode'] ?? '',
                'send_payer_doc_and_name' => Config::setting('send_payer_doc_and_name'),
                'cpf_cnpj_cf_id' => Config::setting('cpf_cnpj_cf_id'),
                'cpf_cf_id' => Config::setting('cpf_cf_id'),
                'cnpj_cf_id' => Config::setting('cnpj_cf_id'),
                'clientdetails' => [
                    'firstname' => $client['firstname'],
                    'lastname' => $client['lastname'],
                    'email' => $client['email'],
                    'address1' => $client['address1'],
                    'address2' => $client['address2'],
                    'city' => $client['city'],
                    'state' => $client['state'],
                    'postcode' => $client['postcode'],
                    'country' => $client['country'],
                    'phonenumber' => $client['phonenumber'],
                    'customfields' => $client['customfields'],
                    'client_id' => $client['userid'],
                    'companyname' => $client['companyname'],
                ],
            ];
    
            $paymentValue = $invoice['balance'];
    
            $clientCustomFields = $params['clientdetails']['customfields'];
            $payerDocType = '';
            $payerDocValue = '';
    
            if ((bool) ($params['send_payer_doc_and_name'])) {
                if (!empty($params['cpf_cnpj_cf_id'])) {
                    $clientCpfOrCnpj = current(array_filter($clientCustomFields, fn ($cf) => (int) ($cf['id']) === (int) ($params['cpf_cnpj_cf_id'])));
                    $clientCpfOrCnpj = $clientCpfOrCnpj['value'];
    
                    if (Validator::cpf($clientCpfOrCnpj)) {
                        $payerDocType = 'cpf';
                        $payerDocValue = $clientCpfOrCnpj;
                    } elseif (Validator::cnpj($clientCpfOrCnpj)) {
                        $payerDocType = 'cnpj';
                        $payerDocValue = $clientCpfOrCnpj;
                    } else {
                        Response::api(false, ['Erro ao reemitir pix' => "Verifique seu CPF/CNPJ e tente novamente."]);
                        return;
                    }
                } else {
                    $clientCnpj = current(array_filter($clientCustomFields, fn ($cf) => (int) ($cf['id']) === (int) ($params['cnpj_cf_id'])));
                    $clientCnpj = trim($clientCnpj['value']);
    
                    if (empty($clientCnpj)) {
                        $clientCpf = current(array_filter($clientCustomFields, fn ($cf) => (int) ($cf['id']) === (int) ($params['cpf_cf_id'])));
                        $clientCpf = trim($clientCpf['value']);
    
                        $payerDocType = 'cpf';
                        $payerDocValue = $clientCpf;
                    } else {
                        $payerDocType = 'cnpj';
                        $payerDocValue = $clientCnpj;
                    }
                }
            }
    
            $clientId = $params['clientdetails']['client_id'];
    
            if ($payerDocType === 'cnpj') {
                $clientFullName = $params['clientdetails']['companyname'];
            } else {
                $firstName = $params['clientdetails']['firstname'];
                $lastName = $params['clientdetails']['lastname'];
    
                $clientFullName = substr(trim("$firstName $lastName"), 0, 200);
            }
    
            $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';
    
            $response = (new PixController($cobType))->create([
                'clientFullName' => Formatter::name($clientFullName),
                'payerDocType' => $payerDocType,
                'payerDocValue' => Formatter::removeNonNumber($payerDocValue),
                'invoiceId' => $invoiceId,
                'paymentValue' => $paymentValue,
                'clientId' => $clientId
            ]);
                
            if (!$response['success']){
                Logger::log('Erro ao Reemitir Pix', ["Falha ao criar novo pix"]);
                Response::api(false, ['Erro ao reemitir pix' => "Falha ao criar novo pix"]);
                return;
            }
            Logger::log('Pix reemitido com sucesso', ["Novo PIX criado com sucesso"]);
            
            // Pegue o campo correto do array de resposta do PixController
            $pixCopiaECola = $response['data']['pixCode'] ?? $response['data']['pixCopiaECola'] ?? null;
            
            Response::api(true, [
                'Pix reemitido com sucesso' => "Novo PIX criado com sucesso",
                'pixCopiaECola' => $pixCopiaECola
            ]);
        } catch (PixException $e) {
            Response::api(false, ['Erro ao reemitir pix' => $e->getMessage()]);
            Logger::log('Erro ao Reemitir Pix', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage(),
                'code' => $e->exceptionCode->name
            ]);
        } catch (\Throwable $e) {
            Response::api(false, ['Erro ao reemitir pix' => $e->getMessage()]);
            Logger::log('Erro ao Reemitir Pix', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
        break;

    case 'save-discount':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            exit;
        }

        http_response_code(200);
        (new DiscountController())->createOrUpdate($request->productId, $request->percentage);

        break;

    case 'delete-discount':
        if (!Auth::isAdminLogged(['Configure Payment Gateways'])) {
            exit;
        }

        http_response_code(200);
        (new DiscountController())->delete($request->productId);

        break;

    case 'update-private-cert':
    case 'update-public-cert':
        if (!$authState->admin()) {
            exit;
        }

        $certType = $request->action === 'update-private-cert' ? 'private' : 'public';
        http_response_code(200);
        (new ApiController())->updateCert($certType, $_FILES['cert']);

        break;

    default:
        http_response_code(404);

        break;
}
