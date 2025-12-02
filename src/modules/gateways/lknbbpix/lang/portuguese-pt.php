<?php
/**
 * LKNBBPIX Language File
 * European Portuguese (pt_PT)
 *
 * @package    LKNBBPIX
 * @author     LKN
 * @copyright  Copyright (c) LKN
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/** @var array<string, string> $_GATEWAYLANG */
$_GATEWAYLANG = [];

// AutoPix - Geral
$_GATEWAYLANG['autopix_title'] = 'PIX Automático';
$_GATEWAYLANG['autopix_tagline'] = 'Configure o pagamento automático das suas facturas via PIX';
$_GATEWAYLANG['autopix_page_title'] = 'PIX Automático';

// AutoPix - Cabeçalhos da Tabela
$_GATEWAYLANG['autopix_table_product_service'] = 'Produto/Serviço';
$_GATEWAYLANG['autopix_table_type'] = 'Tipo';
$_GATEWAYLANG['autopix_table_billing_cycle'] = 'Ciclo de Pagamento';
$_GATEWAYLANG['autopix_table_recurring_amount'] = 'Valor Recorrente';
$_GATEWAYLANG['autopix_table_status'] = 'Estado PIX Automático';
$_GATEWAYLANG['autopix_table_actions'] = 'Acções';

// AutoPix - Ciclos de Pagamento
$_GATEWAYLANG['cycle_monthly'] = 'Mensal';
$_GATEWAYLANG['cycle_quarterly'] = 'Trimestral';
$_GATEWAYLANG['cycle_semiannually'] = 'Semestral';
$_GATEWAYLANG['cycle_annually'] = 'Anual';
$_GATEWAYLANG['cycle_biennially'] = 'Bienal';
$_GATEWAYLANG['cycle_triennially'] = 'Trienal';
$_GATEWAYLANG['cycle_onetime'] = 'Único';
$_GATEWAYLANG['cycle_free'] = 'Gratuito';
$_GATEWAYLANG['cycle_years'] = ':count ano|:count anos';

// AutoPix - Tipos de Produto
$_GATEWAYLANG['type_domain'] = 'Domínio';
$_GATEWAYLANG['type_shared_hosting'] = 'Alojamento Partilhado';
$_GATEWAYLANG['type_server_vps'] = 'Servidor/VPS';
$_GATEWAYLANG['type_reseller'] = 'Revenda';
$_GATEWAYLANG['type_other'] = 'Outro';

// AutoPix - Estado
$_GATEWAYLANG['status_active'] = 'Activo';
$_GATEWAYLANG['status_pending'] = 'Pendente';
$_GATEWAYLANG['status_revoked'] = 'Revogado';
$_GATEWAYLANG['status_no_consent'] = 'Inactivo';

// AutoPix - Status de Produtos/Domínios WHMCS
$_GATEWAYLANG['product_status_pending'] = 'pendente';
$_GATEWAYLANG['product_status_suspended'] = 'suspenso';
$_GATEWAYLANG['product_status_terminated'] = 'terminado';
$_GATEWAYLANG['product_status_cancelled'] = 'cancelado';
$_GATEWAYLANG['product_status_fraud'] = 'fraudulento';
$_GATEWAYLANG['product_status_expired'] = 'expirado';

// AutoPix - Botões
$_GATEWAYLANG['btn_generate_qrcode'] = 'Gerar QR Code';
$_GATEWAYLANG['btn_recover_qrcode'] = 'Recuperar QR Code';
$_GATEWAYLANG['btn_consent_active'] = 'Consentimento Activo';
$_GATEWAYLANG['btn_awaiting_acceptance'] = 'A aguardar Aceitação';
$_GATEWAYLANG['btn_sending'] = 'A gerar...';
$_GATEWAYLANG['btn_recovering'] = 'A recuperar...';
$_GATEWAYLANG['btn_request_sent_success'] = 'QR Code gerado com sucesso!';
$_GATEWAYLANG['btn_error'] = 'Erro: :message';
$_GATEWAYLANG['btn_network_error'] = 'Erro de rede';
$_GATEWAYLANG['btn_learn_more'] = 'Saiba mais';
$_GATEWAYLANG['btn_hide'] = 'Ocultar';
$_GATEWAYLANG['btn_pending_invoice_tooltip'] = 'Para gerar o QR Code, todas as facturas em aberto deste produto devem ser pagas';
$_GATEWAYLANG['btn_inactive_product_tooltip'] = 'Este produto está :status, para gerar o QR Code este produto deve estar activo';

// AutoPix - Filtros
$_GATEWAYLANG['filter_by_status'] = 'Filtrar por estado';
$_GATEWAYLANG['filter_all_services'] = 'Todos os serviços';
$_GATEWAYLANG['btn_clear_filters'] = 'Limpar filtros';

// AutoPix - Mensagens
$_GATEWAYLANG['no_services_found'] = 'Nenhum serviço activo encontrado';
$_GATEWAYLANG['no_services_message'] = 'Não possui serviços activos para configurar o PIX Automático.';
$_GATEWAYLANG['btn_contract_services'] = 'Contratar Serviços';

// AutoPix - Painel de Informações
$_GATEWAYLANG['how_autopix_works'] = 'Como Funciona o PIX Automático';
$_GATEWAYLANG['autopix_description'] = 'O <strong>PIX Automático</strong> permite que as suas facturas sejam pagas automaticamente no vencimento, directamente da sua conta bancária, sem necessidade de gerar um novo código PIX a cada mês.';
$_GATEWAYLANG['autopix_notification_title'] = 'Como Activar (Jornada 2):';
$_GATEWAYLANG['autopix_notification_description'] = 'Ao clicar em <strong>Gerar QR Code</strong>, será exibido um QR Code no ecrã. Abra a aplicação do seu banco, digitalize o QR Code com a câmara e aceite o consentimento. Após a confirmação, as suas facturas serão pagas automaticamente no vencimento.';
$_GATEWAYLANG['autopix_feature_automatic'] = '<strong>Pagamento automático:</strong> As suas facturas são pagas automaticamente no vencimento';
$_GATEWAYLANG['autopix_feature_secure'] = '<strong>Seguro:</strong> Transacções autorizadas e processadas pelo Banco do Brasil';
$_GATEWAYLANG['autopix_feature_control'] = '<strong>Controlo total:</strong> Pode revogar o consentimento a qualquer momento';

// AutoPix - Controlo de Acesso
$_GATEWAYLANG['access_restricted'] = 'Acesso Restrito';
$_GATEWAYLANG['access_denied_country'] = 'O PIX Automático está disponível apenas para clientes no Brasil.';
$_GATEWAYLANG['access_denied_verification'] = 'Não foi possível verificar as suas informações. Por favor, tente novamente.';

