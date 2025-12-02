<?php

use Lkn\BBPix\App\Pix\Exceptions\PixException;
use Lkn\BBPix\App\Pix\Controllers\PixController;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Formatter;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
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
                    $table->decimal('percentage', 5, 2)->unsigned();
                    $table->dateTime('created_at')->useCurrent();
                    $table->dateTime('updated_at')->useCurrent();
                }
            );
        }

        // AutoPix tables
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_lknbbpix_auto_consents')) {
            $schema->create(
                'mod_lknbbpix_auto_consents',
                function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->integer('clientid');
                    $table->integer('serviceid')->nullable();
                    $table->integer('domainid')->nullable();
                    $table->enum('type', ['service', 'domain']);
                    $table->enum('status', ['pending', 'active', 'revoked', 'blocked']);
                    $table->string('psp_consent_id', 191);
                    $table->string('id_rec', 64)->nullable();
                    $table->string('id_rec_tipo', 4)->nullable();
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at')->nullable();
                    $table->dateTime('confirmed_at')->nullable();
                    $table->dateTime('revoked_at')->nullable();
                    $table->dateTime('last_notified_at')->nullable();
                    $table->text('metadata')->nullable();
                    $table->index('clientid');
                    $table->index('serviceid');
                    $table->index('domainid');
                    $table->index('psp_consent_id');
                    $table->index('id_rec');
                    $table->index('id_rec_tipo');
                }
            );
        } else {
            if (!$schema->hasColumn('mod_lknbbpix_auto_consents', 'id_rec')) {
                $schema->table('mod_lknbbpix_auto_consents', function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->string('id_rec', 64)->nullable()->after('psp_consent_id');
                });
            }

            if (!$schema->hasColumn('mod_lknbbpix_auto_consents', 'id_rec_tipo')) {
                $schema->table('mod_lknbbpix_auto_consents', function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->string('id_rec_tipo', 4)->nullable()->after('id_rec');
                });
            }

            if (!$schema->hasColumn('mod_lknbbpix_auto_consents', 'updated_at')) {
                $schema->table('mod_lknbbpix_auto_consents', function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->dateTime('updated_at')->nullable()->after('created_at');
                });
            }

            $connection = Capsule::connection();
            $indexes = $connection->select("SHOW INDEX FROM mod_lknbbpix_auto_consents");
            $indexNames = array_map(static function ($index) {
                return $index->Key_name;
            }, $indexes);

            if (!in_array('mod_lknbbpix_auto_consents_id_rec_index', $indexNames, true)) {
                try {
                    Capsule::statement('CREATE INDEX mod_lknbbpix_auto_consents_id_rec_index ON mod_lknbbpix_auto_consents (id_rec)');
                } catch (Exception $e) {
                    // ignore if already exists
                }
            }

            if (!in_array('mod_lknbbpix_auto_consents_id_rec_tipo_index', $indexNames, true)) {
                try {
                    Capsule::statement('CREATE INDEX mod_lknbbpix_auto_consents_id_rec_tipo_index ON mod_lknbbpix_auto_consents (id_rec_tipo)');
                } catch (Exception $e) {
                    // ignore if already exists
                }
            }
        }

        if (!$schema->hasTable('mod_lknbbpix_auto_instructions')) {
            $schema->create(
                'mod_lknbbpix_auto_instructions',
                function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->integer('invoice_id');
                    $table->integer('consent_id');
                    $table->unsignedTinyInteger('attempt_number')->default(1);
                    $table->enum('finalidade', ['AGND', 'NTAG', 'RIFL'])->default('AGND');
                    $table->date('scheduled_date');
                    $table->string('id_fim_a_fim', 64);
                    $table->decimal('amount', 10, 2);
                    $table->enum('status', ['pending', 'scheduled', 'liquidated', 'failed', 'cancelled'])->default('pending');
                    $table->longText('api_response')->nullable();
                    $table->dateTime('created_at')->useCurrent();
                    $table->dateTime('updated_at')->nullable();
                    $table->unique('id_fim_a_fim', 'idx_instr_id_fim_a_fim');
                    $table->index('invoice_id', 'idx_instr_invoice');
                    $table->index('consent_id', 'idx_instr_consent');
                    $table->index('status', 'idx_instr_status');
                    $table->index('scheduled_date', 'idx_instr_scheduled_date');
                }
            );
        }

        if (!Capsule::schema()->hasTable('mod_lknbbpix_webhook_events')) {
            Capsule::schema()->create(
                'mod_lknbbpix_webhook_events',
                function ($table): void {
                    /** @var Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->string('event_id', 191);
                    $table->string('event_type', 100);
                    $table->dateTime('received_at');
                    $table->dateTime('processed_at')->nullable();
                    $table->enum('status', ['received', 'processed', 'failed']);
                    $table->text('payload')->nullable();
                    $table->unique('event_id');
                    $table->index('event_type');
                    $table->index('status');
                    $table->index('received_at');
                }
            );
        }
    } catch (Exception $e) {
        echo "Unable to create gateway tables: {$e->getMessage()}";
    }

    $whmcsInstallUrl = rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');

    $header = View::render(
        'config_header',
        [
            'logoUrl' => $whmcsInstallUrl . '/modules/gateways/lknbbpix/logo.png',
            'moduleVersion' => Config::constant('version')
        ]
    );

    $apiUrl = "$whmcsInstallUrl/modules/gateways/lknbbpix/api.php";

    $currentSettings = getGatewayVariables('lknbbpix');
    $consentFallbackValue = $currentSettings['autopix_consent_fallback_method']
        ?? ($currentSettings['autopix_revoke_fallback_method'] ?? 'default');
    $retryFallbackValue = $currentSettings['autopix_retry_failure_method'] ?? 'default';
    $attemptOffsetsValue = $currentSettings['autopix_charge_days'] ?? '-7,-3,0';
    $splitInvoicesDefault = $currentSettings['autopix_split']
        ?? ($currentSettings['autopix_split_experimental'] ?? '');

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
                'prod' => 'Produção',
                'dev' => 'Homologação'
            ]
        ],

        'enable_logs' => [
            'FriendlyName' => 'Habilitar debug',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'As operações realizadas pelo gateway estarão vísíveis em <a href="https://whmcs.linknacional.com.br/admin/gatewaylog.php">Log dos Portais</a> para detecção de erros.'
        ],

        'application_key' => [
            'FriendlyName' => 'application_key *',
            'Type' => 'password',
            'Size' => '25',
            'Description' => <<<HTML
            <a href="https://apoio.developers.bb.com.br/referency/post/6050dda3737e1c0012e2d00e">
                Clique aqui para saber como conseguir a application_key.
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
            'Description' => 'Coloque aqui a sua chave registrada no BB que irá receber os pagamentos. Aceita CPF, CNPJ, telefone, e-mail ou chave aleatória (EVP).',
            'Type' => 'text',
            'Size' => '40'
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

        'autopix_merchant_name' => [
            'FriendlyName' => 'Nome do recebedor (PIX Automático)',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Nome exibido no BR Code das cobranças AutoPix. Deixe em branco para usar o nome configurado em Configurações Gerais do WHMCS.'
        ],

        'autopix_merchant_city' => [
            'FriendlyName' => 'Cidade do recebedor (PIX Automático)',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Cidade exibida no BR Code das cobranças AutoPix. Deixe em branco para usar a cidade configurada em Configurações Gerais do WHMCS.'
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

        'enable_pix_cancel_when_invoice_cancel' => [
            'FriendlyName' => 'Verificar e cancelar PIX ao cancelar fatura',
            'Type' => 'yesno',
            'Default' => 'no',
            'Description' => 'Além de verificar se o PIX foi pago, também cancela automaticamente PIX pendentes quando a fatura é cancelada. Quando ambas as opções estão marcadas, esta configuração tem prioridade.'
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
        
        'transaction_fee' => [
            'FriendlyName' => 'Taxa transacional (R$)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => 'Taxa fixa aplicada como fee em toda transação com prefixo PAGO.'
        ],

        'autopix_settings' => [
            'Description' => <<<HTML
            <div style="margin: 20px 0px 10px; font-weight: bold; font-size: 1.1em;">
                Configurações de PIX Automático
            </div>
HTML
        ],

        'autopix_consent_fallback_method' => [
            'FriendlyName' => 'Método ao revogar consentimento PIX Automático',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => 'default',
            'Options' => [
                'default' => 'Método padrão do cliente',
                'lknbbpix' => 'PIX Convencional (lknbbpix)',
                'none' => 'Não alterar método de pagamento'
            ],
            'Description' => 'Define qual método de pagamento será atribuído ao serviço/domínio quando o consentimento PIX Automático for revogado pelo cliente.',
            'Value' => $consentFallbackValue
        ],

        'autopix_retry_failure_method' => [
            'FriendlyName' => 'Método após tentativas PIX Automático sem sucesso',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => 'default',
            'Options' => [
                'default' => 'Método padrão do cliente',
                'lknbbpix' => 'PIX Convencional (lknbbpix)',
                'none' => 'Não alterar método de pagamento'
            ],
            'Description' => 'Define qual método de pagamento será atribuído à fatura quando todas as tentativas automáticas falharem. O consentimento permanece ativo.',
            'Value' => $retryFallbackValue
        ],

        'autopix_charge_days' => [
            'FriendlyName' => 'Tentativas (offsets antes do vencimento)',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '-7,-3,0',
            'Description' => 'Informe até três offsets (entre -7 e 0) para as tentativas automáticas. O valor 0 representa o dia do vencimento; -7 representa 7 dias antes. A primeira tentativa só será enviada quando a janela regulatória (2 a 10 dias antes da liquidação) estiver aberta.',
            'Value' => $attemptOffsetsValue
        ],

        'autopix_charge_notification_template' => [
            'FriendlyName' => 'Template de e-mail (PIX Automático)',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'AutoPix Charge Notification',
            'Description' => 'Nome do template de e-mail do WHMCS usado para notificar o cliente em cada tentativa de cobrança automática.'
        ],

        'autopix_retry_failure_log_activity' => [
            'FriendlyName' => 'Registrar log ao falhar tentativas',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Quando todas as tentativas automáticas falharem, registrar entrada em Log de Atividades do cliente.'
        ],

        'autopix_split' => [
            'FriendlyName' => 'Separar itens AutoPix em faturas distintas',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Ao gerar faturas com método AutoPix, criar faturas independentes por item para evitar suspensão conjunta. Requer cron diário atualizado. (experimental)',
            'Value' => $splitInvoicesDefault,
        ],

        'autopix_cron_notice' => [
            'Description' => <<<HTML
            <div class="fieldlabel">CRON AutoPix</div>
            <div class="fieldarea" style="line-height: 1.6;">
                <strong>Importante:</strong> agende a execução diária do script <code>modules/gateways/lknbbpix/cron/autopix_charge.php</code>.<br>
                Exemplo de CRON: <code>0 6 * * * php -q /path/to/whmcs/modules/gateways/lknbbpix/cron/autopix_charge.php</code>
            </div>
HTML
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
                    return View::render(
                        'form.index',
                        ['errorMsg' => 'Verifique seu CPF/CNPJ e tente novamente.']
                    );
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

        $firstName = $params['clientdetails']['firstname'];
        $lastName = $params['clientdetails']['lastname'];
        $fullNameFromNames = substr(trim("$firstName $lastName"), 0, 200);

        if ($payerDocType === 'cnpj') {
            $clientFullName = trim($params['clientdetails']['companyname']);

            if ($clientFullName === '') {
                // Company name was left blank even though the client uses CNPJ; fallback to the personal name.
                $clientFullName = $fullNameFromNames;
            }
        } else {
            $clientFullName = $fullNameFromNames;
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

        if (!$response['success']) {
            return View::render('form.index', ['errorMsg' => $response['data']['error']]);
        }

        $csrfToken = bin2hex(openssl_random_pseudo_bytes(32));
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

        $whmcsInstallUrl = rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');

        return View::render(
            'form.index',
            [
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
