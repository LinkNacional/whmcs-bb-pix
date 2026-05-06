<?php

use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\PixController;
use Lkn\BBPix\App\Pix\Services\DecisionService;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Formatter;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\InvoiceOriginHelper;
use Lkn\BBPix\Helpers\Logger;
use Lkn\BBPix\Helpers\PayerResolver;
use Lkn\BBPix\Helpers\Validator;
use Lkn\BBPix\Helpers\View;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lknbbpix/vendor/autoload.php';
require_once __DIR__ . '/lknbbpix/src/utils.php';
require_once __DIR__ . '/lknbbpix/src/License/license_func.php';

/**
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function lknbbpix_MetaData()
{
    return [
        'DisplayName' => 'Pix - Banco do Brasil',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define gateway configuration options.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function lknbbpix_config()
{
    try {
        if (!Capsule::schema()->hasTable('mod_lknbbpix_discount_per_product')) {
            Capsule::schema()->create(
                'mod_lknbbpix_discount_per_product',
                function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->unsignedSmallInteger('product_id')->unique();
                    $table->unsignedDecimal('percentage', 5, 2);
                    $table->dateTime('created_at')->useCurrent();
                    $table->dateTime('updated_at')->useCurrent();
                }
            );
        }

        if (!Capsule::schema()->hasTable('mod_lknbbpix_auths')) {
            Capsule::schema()->create(
                'mod_lknbbpix_auths',
                function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->unsignedInteger('client_id');
                    $table->string('id_rec', 29)->unique();
                    $table->unsignedTinyInteger('due_day');
                    $table->string('periodicidade', 25);
                    $table->string('status', 25);
                    $table->text('emv_payload')->nullable();
                    $table->dateTime('created_at')->useCurrent();
                    $table->dateTime('updated_at')->useCurrent();
                    $table->index(['client_id', 'due_day', 'status'], 'mod_lknbbpix_auths_lookup_idx');
                }
            );
        }
    } catch (Exception $e) {
        echo "Unable to create gateway tables: {$e->getMessage()}";
    }

    $whmcsInstallUrl = rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');

    $apiUrl = "$whmcsInstallUrl/modules/gateways/lknbbpix/api.php";

    $header = View::render(
        'config_header',
        [
            'logoUrl' => $whmcsInstallUrl . '/modules/gateways/lknbbpix/logo.png',
            'moduleVersion' => Config::constant('version'),
            'apiUrl' => $apiUrl,
        ]
    );

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Pix - Banco do Brasil'
        ],

        '' => ['Description' => $header],

        'lkn_license' => [
            'FriendlyName' => 'Licença da Link Nacional',
            'Description' => 'Licença Link Nacional',
            'Type' => 'password',
            'Size' => '25'
        ],

        'credentials' => [
            'Description' => <<<HTML
            <div style="margin: 20px 0px 10px; font-weight: bold; font-size: 1.1em;">
                Credenciais da API
            </div>
HTML
        ],

        'jsonFees' => [
            'FriendlyName' => 'Certificados mTLS',
            'Type' => 'input',
            'Description' => View::render(
                'certs_upload_form',
                [
                    'api_url' => $apiUrl
                ]
            )
        ],

        'env' => [
            'FriendlyName' => 'Ambiente *',
            'Type' => 'dropdown',
            'Size' => '25',
            'Description' => 'Define se o gateway irá operar em modo de produção ou testes. Lembre-se de atualizar as credenciais acima para as do ambiente em questão.',
            'Options' => [
                'hml_no_mtls' => 'Homologação Sem mTLS',
                'hml_mtls' => 'Homologação Com mTLS',
                'prod' => 'Produção Com mTLS'
            ]
        ],

        'enable_logs' => [
            'FriendlyName' => 'Habilitar debug',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'As operações realizadas pelo gateway estarão vísíveis em <a href="https://whmcs.linknacional.com.br/admin/gatewaylog.php">Log dos Portais</a> para detecção de erros.'
        ],

        'developer_application_key' => [
            'FriendlyName' => 'developer_application_key *',
            'Type' => 'password',
            'Size' => '25',
            'Description' => <<<HTML
            <a href="https://apoio.developers.bb.com.br/referency/post/6050dda3737e1c0012e2d00e">
                Clique aqui para saber como conseguir a developer_application_key.
            </a>
            HTML
        ],

        'client_id' => [
            'FriendlyName' => 'client_id *',
            'Type' => 'password',
            'Size' => '25',
            'Description' => <<<HTML
            <a href="https://apoio.developers.bb.com.br/referency/post/6050dda3737e1c0012e2d00e">
                Clique aqui para saber como conseguir a client_id.
            </a>
            HTML
        ],

        'client_secret' => [
            'FriendlyName' => 'client_secret *',
            'Type' => 'password',
            'Size' => '25',
            'Description' => <<<HTML
            <a href="https://apoio.developers.bb.com.br/referency/post/6050dda3737e1c0012e2d00e">
                Clique aqui para saber como conseguir a client_secret.
            </a>
            HTML
        ],

        'auth_basic' => [
            'FriendlyName' => 'Basic *',
            'Type' => 'password',
            'Size' => '25',
            'Description' => <<<HTML
            <a href="https://apoio.developers.bb.com.br/referency/post/6050dda3737e1c0012e2d00e">
                Clique aqui para saber como conseguir a Basic.
            </a>
            HTML
        ],

        'receiver_pix_key' => [
            'FriendlyName' => 'Chave do recebedor *',
            'Description' => 'Coloque aqui a sua chave registrada no BB que irá receber os pagamentos.',
            'Type' => 'text',
            'Size' => '25'
        ],

        'pix_expiration' => [
            'FriendlyName' => 'Expiração do Pix *',
            'Description' => 'Data da expiração do Pix em dias. Por padrão, é 1 dia. Deixe vazio para seguir o padrão. Máximo de 24855 dias.',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 1
        ],

        'customization' => [
            'Description' => <<<HTML
            <div style="margin: 20px 0px 10px; font-weight: bold; font-size: 1.1em;">
                Personalização
            </div>
HTML
        ],

        'pix_descrip' => [
            'FriendlyName' => 'Descrição do Pix',
            'Type' => 'text',
            'Size' => '140',
            'Description' => 'Esse dado podem ser vistos no aplicativo de pagamento. Deixe vazio para não enviar nenhuma descrição Máximo de 140 caracteres.'
        ],

        'send_payer_doc_and_name' => [
            'FriendlyName' => 'Inserir o nome e o CPF/CNPJ do cliente no Pix',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Assim, esses dados serão vistos no aplicativo de pagamento. Caso CNPJ e CPF estejam presentes no perfil do cliente, o CNPJ será utilizado. Obrigatório para cobranças com multa e/ou juros.'
        ],

        'enable_pix_automatic' => [
            'FriendlyName' => 'Habilitar Pix Automático',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Desative para forçar apenas o fluxo manual/tradicional de Pix.'
        ],

        'cnpj_cf_id' => [
            'FriendlyName' => 'ID do custom field para CNPJ',
            'Type' => 'dropdown',
            'Options' => lknbbpix_create_custom_fields_select(),
            'Size' => '25',
            'Description' => 'Caso você só tenha um custom field específico para CNPJ, selecione-o aqui.'
        ],

        'cpf_cf_id' => [
            'FriendlyName' => 'ID do custom field para CPF',
            'Type' => 'dropdown',
            'Options' => lknbbpix_create_custom_fields_select(),
            'Size' => '25',
            'Description' => 'Caso você só tenha um custom field específico para CPF, selecione-o aqui.'
        ],

        'cpf_cnpj_cf_id' => [
            'FriendlyName' => 'ID do custom field misto para CPF e CNPJ',
            'Type' => 'dropdown',
            'Options' => lknbbpix_create_custom_fields_select(),
            'Size' => '25',
            'Description' => 'Caso você só tenha um custom field que serve tanto para CPF quanto para CNPJ, selecione-o aqui. Caso contrário, não altere esse campo.'
        ],

        'enable_share_pix_btn' => [
            'FriendlyName' => 'Exibir botão para compartilhamento do Pix',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Exibe um botão que permite o compartilhamento facilitado do código do Pix pelas redes sociais e e-mail.'
        ],

        'enable_client_manual_check' => [
            'FriendlyName' => 'Exibir botão para cliente verificar pagamento',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Exibir botão na tela de fatura do cliente da fatura verificar o pagamento do Pix.'
        ],

        'max_client_manual_checks' => [
            'FriendlyName' => 'Quantidade de verificação manuais pelo cliente',
            'Description' => 'Após verificar pelas quantidade de vezes definida aqui, o botão não é mais exibido.',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 5
        ],

        'enable_admin_manual_check' => [
            'FriendlyName' => 'Exibir botão para administrador verificar pagamento',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Exibir botão na tela administrativa da fatura verificar o pagamento do Pix.'
        ],

        'max_payment_value' => [
            'FriendlyName' => 'Valor máximo da fatura para permitir pagamento com o módulo',
            'Description' => 'Utilize apenas números e vírgula (apenas duas casas decimais são consideradas). Caso a fatura não atenda ao limite definido aqui, o cliente recebera uma aviso. Deixe em branco para não definir limite máximo.',
            'Type' => 'text',
            'Size' => '25',
            'Default' => ''
        ],

        'min_payment_value' => [
            'FriendlyName' => 'Valor mínimo da fatura para permitir pagamento com o módulo',
            'Description' => 'Utilize apenas números e vírgula (apenas duas casas decimais são consideradas). Caso a fatura não atenda ao limite definido aqui, o cliente recebera uma aviso. Deixe em branco para não definir limite mínimo.',
            'Type' => 'text',
            'Size' => '25',
            'Default' => ''
        ],

        'enable_pix_when_invoice_cancel' => [
            'FriendlyName' => 'Verificar pagamento ao cancelar uma fatura',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Essa configuração existe pois há a possibilidade de o webhook do Banco do Brasil não notificar o gateway que o Pix foi pago, o que pode gerar pagamentos duplicados.'
        ],

        'discount_settings' => [
            'Description' => <<<HTML
            <div style="margin: 20px 0px 10px; font-weight: bold; font-size: 1.1em;">
                Configurações de desconto
            </div>
HTML
        ],

        'discount_for_pix_payment_percentage' => [
            'FriendlyName' => 'Desconto por pagto. realizado via PIX',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Valor do desconto que será aplicado caso o pagamento ocorra por este gateway via PIX. Em percentual (Ex.: 10, 25, 50)<br>
            Caso algum produto dentro da fatura tenha desconto definido, essa configuração não terá efeito na fatura.'
        ],

        'ruled_discount_criteria' => [
            'FriendlyName' => 'Desconto por critério',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => 'disabled',
            'Options' => [
                'disabled' => 'Desativado: não aplicar desconto por critério',
                'new_orders' => 'Ativado: apenas para faturas de novos pedidos',
            ],
            'Description' => ''
        ],

        'ruled_discount_percentage' => [
            'FriendlyName' => 'Porcentagem do desconto por critério',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Valor do desconto que será aplicado caso o pagamento siga a algum critério acima.<br>
            Soma-se à porcentagem definida na configuração "Desconto por pagto. realizado via PIX".'
        ],

        'domain_register_discount_percentage' => [
            'FriendlyName' => 'Porcentagem de desconto de registro de domínio',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '0',
            'Description' => '0.00 a 100.00. Utilize apenas ponto e números.'
        ],

        'discount_modal' => [
            'FriendlyName' => 'Desconto por produto',
            'Description' => <<<HTML
            <button
                type="button"
                class="btn btn-link"
                data-toggle="modal"
                data-target="#lknbbpix-discounts-modal"
            >
            Editar descontos de produtos
            </button>
HTML
        ],

        'product_discount_rule' => [
            'FriendlyName' => 'Condição para aplicar desconto nos produtos',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => 'disabled',
            'Options' => [
                'disabled' => 'Desativado: não aplicar desconto nos produtos',
                'new_orders' => 'Ativado: apenas para faturas de novos pedidos',
                'first_orders' => 'Ativado: apenas para primeiro pedido do cliente',
            ],
            'Description' => ''
        ],

        'fees_settings' => [
            'Description' => <<<HTML
            <div style="margin: 20px 0px 10px; font-weight: bold; font-size: 1.1em;">
                Configurações de juros e multas
            </div>
HTML
        ],

        'enable_fees_interest' => [
            'FriendlyName' => 'Habilitar cobrança de juros e multa',
            'Type' => 'yesno',
            'Default' => 'no',
            'Description' => 'Ative para permitir pagamentos mesmo com a fatura vencida tendo a opção de cobrar multa ou juros após o vencimento.'
        ],

        'enable_fees_calculation' => [
            'FriendlyName' => 'Permitir gerar PIX após o vencimento',
            'Type' => 'yesno',
            'Default' => 'no',
            'Description' => 'Ative para permitir geração de PIX após a data de vencimento da fatura, o cálculo dos juros do vencimento é feito ao criar a intenção do pagamento e não via API do Banco do Brasil. O Banco do Brasil não permite geração de PIX vencido.'
        ],

        'interest_rate' => [
            'FriendlyName' => 'Taxa de juros',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '0',
            'Description' => 'Valor percentual da taxa de juros a ser cobrada diariamente. Aceita valores de 0.00 a 100.00. Utilize apenas ponto e números.'
        ],

        'cob_type' => [
            'FriendlyName' => 'Tipo de cobrança para multa por atraso',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => 'fixed',
            'Options' => [
                'fixed' => 'Fixo',
                'percent' => 'Percentual',
            ],
            'Description' => 'Tipo do valor aceito para cobrança de multa por atraso, pode ser percentual ou fixo.'
        ],

        'fine' => [
            'FriendlyName' => 'Multa por atraso',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '0',
            'Description' => 'Valor da multa a ser cobrada após o vencimento. Utilize apenas ponto e números. Valor pode ser percentual ou fixo'
        ],

        'fine_days' => [
            'FriendlyName' => 'Validade do pagamento',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '1',
            'Description' => 'Por quantos dias após o vencimento é permitida a cobrança.'
        ],
    ];
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function lknbbpix_link($params): string
{
    // Handles the case in which the process of payment is made through payment of a service.
    if (
        trim($_SERVER['PHP_SELF'], '/') === 'cart.php' ||
        $_SERVER['REQUEST_URI'] === '/clientarea.php?action=addfunds'
    ) {
        return 'Aguarde o redirecionamento.';
    }

    try {
        $isLicenseValid = lknbbpix_check_license();

        if ($isLicenseValid !== true) {
            return View::render(
                'form.index',
                ['errorMsg' => $isLicenseValid]
            );
        }

        $invoiceId = $params['invoiceid'];
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        $paymentValue = $invoice['balance'];

        $maxPaymentValue = round((float) ($params['max_payment_value']), 2);
        $minPaymentValue = round((float) ($params['min_payment_value']), 2);

        if ($maxPaymentValue > 0.0 && $paymentValue > $maxPaymentValue) {
            $formattedMaxValue = number_format($maxPaymentValue, 2, ',', '.');

            return View::render(
                'form.index',
                ['errorMsg' => "A fatura não atende o valor máximo para pagamentos: R\${$formattedMaxValue}."]
            );
        } elseif ($minPaymentValue > 0.0 && $paymentValue < $minPaymentValue) {
            $formattedMinValue = number_format($minPaymentValue, 2, ',', '.');

            return View::render(
                'form.index',
                ['errorMsg' => "A fatura não atende o valor mínimo para pagamentos: R\${$formattedMinValue}."]
            );
        }

        $clientId = $params['clientdetails']['client_id'];

        $invoiceFlowData = lknbbpix_resolve_invoice_flow($invoiceId, (int) $clientId);
        $flowDecision = $invoiceFlowData['decision'];

        $whmcsInstallUrl = rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');

        if ($flowDecision === DecisionService::COBR_AUTOMATICO) {
            return View::render(
                'form.index',
                [
                    'pixFlow' => DecisionService::COBR_AUTOMATICO,
                    'autoPixMessage' => 'Esta fatura possui autorização ativa para Pix Automático. A cobrança será processada automaticamente no vencimento.',
                ]
            );
        }

        $payerResolution = PayerResolver::resolveFromGatewayParams(
            $params,
            $flowDecision === DecisionService::JORNADA4 || (bool) ($params['send_payer_doc_and_name'])
        );

        if (!($payerResolution['success'] ?? false)) {
            return View::render(
                'form.index',
                [
                    'errorMsg' => $payerResolution['data']['error'] ?? PayerResolver::PROFILE_UPDATE_REQUIRED_MESSAGE,
                    'errorProfileUrl' => $payerResolution['data']['profileUrl'] ?? PayerResolver::PROFILE_DETAILS_URL,
                ]
            );
        }

        $clientFullName = $payerResolution['data']['clientFullName'];
        $payerDocType = $payerResolution['data']['payerDocType'];
        $payerDocValue = $payerResolution['data']['payerDocValue'];

        if ($flowDecision === DecisionService::JORNADA4) {
            $csrfToken = bin2hex(random_bytes(32));
            $_SESSION['lkn-bb-pix'] = $csrfToken;

            return View::render(
                'form.index',
                [
                    'pixFlow' => DecisionService::JORNADA4,
                    'csrfToken' => $csrfToken,
                    'invoiceId' => (int) $invoiceId,
                    'invoiceValue' => (float) $paymentValue,
                    'max_client_manual_checks' => $params['max_client_manual_checks'] ?? 5,
                    'whmcsInstallUrl' => $whmcsInstallUrl,
                ]
            );
        }

        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        $response = (new PixController($cobType))->create([
            'clientFullName' => $clientFullName,
            'payerDocType' => $payerDocType,
            'payerDocValue' => $payerDocValue,
            'invoiceId' => $invoiceId,
            'paymentValue' => $paymentValue,
            'clientId' => $clientId
        ]);

        if (!$response['success']) {
            return View::render('form.index', ['errorMsg' => $response['data']['error']]);
        }

        $csrfToken = bin2hex(random_bytes(32));
        $_SESSION['lkn-bb-pix'] = $csrfToken;

        $pixValue = $response['data']['pixValue'];

        $discountPercentage = null;
        $taxAmount = null;

        if ($pixValue < $paymentValue) {
            $discountAmount = $pixValue - $paymentValue;

            $discountPercentage = abs(($discountAmount / $paymentValue) * 100);
            $discountPercentage = number_format($discountPercentage, 0, ',', '.');
        }

        if($pixValue > $paymentValue) {
            $taxAmount = $pixValue - $paymentValue;
            $taxAmount = number_format($taxAmount, '2', ',', '.');
        }

        return View::render(
            'form.index',
            [
                'pixFlow' => DecisionService::MANUAL_TRADICIONAL,
                'qrCodeBase64' => $response['data']['pixQrCodeBase64'],
                'qrCodeText' => $response['data']['pixCode'],
                'pixValue' => $pixValue,
                'discountPercentage' => $discountPercentage,
                'taxAmount' => $taxAmount,
                'csrfToken' => $csrfToken,
                'invoiceId' => $invoiceId,
                'invoiceValue' => $paymentValue,
                'enable_share_pix_btn' => $params['enable_share_pix_btn'] === 'on',
                'enable_client_manual_check' => $params['enable_client_manual_check'] === 'on',
                'max_client_manual_checks' => $params['max_client_manual_checks'] ?? 5,
                'enable_admin_manual_check' => $params['enable_admin_manual_check'] === 'on',
                'whmcsInstallUrl' => $whmcsInstallUrl
            ]
        );
    } catch (PixException $e) {
        return View::render(
            'form.index',
            ['errorMsg' => var_export($e->exceptionCode, true)]
        );
    } catch (Throwable $e) {
        Logger::log(
            'Erro ao gerar Pix',
            [
                'clientFullName' => $clientFullName,
                'payerDocType' => $payerDocType,
                'payerDocValue' => $payerDocValue,
                'invoiceId' => $invoiceId,
                'paymentValue' => $paymentValue,
                'clientId' => $clientId
            ],
            [
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        );

        return View::render(
            'form.index',
            ['errorMsg' => 'Não foi possível gerar o Pix.']
        );
    }
}

function lknbbpix_resolve_invoice_flow(int $invoiceId, int $clientId): array
{
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(['duedate']);

    $dueDay = (int) date('d', strtotime((string) ($invoice->duedate ?? date('Y-m-d'))));
    $origin = (new InvoiceOriginHelper())->classify($invoiceId);

    $decision = $origin === InvoiceOriginHelper::MANUAL_TRADICIONAL
        ? DecisionService::MANUAL_TRADICIONAL
        : (new DecisionService())->evaluate($origin, $clientId, $dueDay);

    return [
        'decision' => $decision,
        'origin' => $origin,
        'dueDay' => $dueDay,
    ];
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @return array Transaction response status
 */
function lknbbpix_refund($params)
{
    try {
        $refundAmount = (float) ($params['amount']);
        $invoiceId = $params['invoiceid'];
        $cobType = Config::setting('enable_fees_interest') ? 'cobv' : 'cob';

        $response = (new PixController($cobType))->refund([
            'transacId' => $params['transid'],
            'refundAmount' => $refundAmount,
            'invoiceId' => $invoiceId
        ]);

        if (!$response['success']) {
            return ['status' => 'error', 'rawdata' => $response['data']['errorMsg']];
        }

        $pixStatus = $response['data']['status'];

        $refundStatus = in_array($pixStatus, ['EM_PROCESSAMENTO', 'DEVOLVIDO'], true) ? 'success' : 'error';

        Logger::log('mark as refunded debug', [
            'total' => Invoice::getTotal($invoiceId),
            'balance' => Invoice::getBalance($invoiceId),
            'refund' => $refundAmount
        ]);

        $newInvoiceBalance = Invoice::getBalance($invoiceId) + $refundAmount;

        if ($refundStatus === 'success' && Invoice::getTotal($invoiceId) === $newInvoiceBalance) {
            Invoice::markAsRefunded($invoiceId);
        }

        return [
            'status' => $refundStatus,
            'rawdata' => $response,
            'transid' => $response['data']['refundTransId'],
            'fees' => 0,
        ];
    } catch (Throwable $th) {
        return [
            'status' => 'error',
            'rawdata' => [
                'msg' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine()
            ]
        ];
    }
}
