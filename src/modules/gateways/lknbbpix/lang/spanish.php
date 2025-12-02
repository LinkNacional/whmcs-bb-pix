<?php
/**
 * LKNBBPIX Language File
 * Spanish (es)
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

// AutoPix - General
$_GATEWAYLANG['autopix_title'] = 'PIX Automático';
$_GATEWAYLANG['autopix_tagline'] = 'Configure el pago automático de sus facturas a través de PIX';
$_GATEWAYLANG['autopix_page_title'] = 'PIX Automático';

// AutoPix - Encabezados de la Tabla
$_GATEWAYLANG['autopix_table_product_service'] = 'Producto/Servicio';
$_GATEWAYLANG['autopix_table_type'] = 'Tipo';
$_GATEWAYLANG['autopix_table_billing_cycle'] = 'Ciclo de Pago';
$_GATEWAYLANG['autopix_table_recurring_amount'] = 'Monto Recurrente';
$_GATEWAYLANG['autopix_table_status'] = 'Estado PIX Automático';
$_GATEWAYLANG['autopix_table_actions'] = 'Acciones';

// AutoPix - Ciclos de Pago
$_GATEWAYLANG['cycle_monthly'] = 'Mensual';
$_GATEWAYLANG['cycle_quarterly'] = 'Trimestral';
$_GATEWAYLANG['cycle_semiannually'] = 'Semestral';
$_GATEWAYLANG['cycle_annually'] = 'Anual';
$_GATEWAYLANG['cycle_biennially'] = 'Bienal';
$_GATEWAYLANG['cycle_triennially'] = 'Trienal';
$_GATEWAYLANG['cycle_onetime'] = 'Único';
$_GATEWAYLANG['cycle_free'] = 'Gratuito';
$_GATEWAYLANG['cycle_years'] = ':count año|:count años';

// AutoPix - Tipos de Producto
$_GATEWAYLANG['type_domain'] = 'Dominio';
$_GATEWAYLANG['type_shared_hosting'] = 'Hosting Compartido';
$_GATEWAYLANG['type_server_vps'] = 'Servidor/VPS';
$_GATEWAYLANG['type_reseller'] = 'Revendedor';
$_GATEWAYLANG['type_other'] = 'Otro';

// AutoPix - Estado
$_GATEWAYLANG['status_active'] = 'Activo';
$_GATEWAYLANG['status_pending'] = 'Pendiente';
$_GATEWAYLANG['status_revoked'] = 'Revocado';
$_GATEWAYLANG['status_no_consent'] = 'Inactivo';

// AutoPix - Estado de Productos/Dominios WHMCS
$_GATEWAYLANG['product_status_pending'] = 'pendiente';
$_GATEWAYLANG['product_status_suspended'] = 'suspendido';
$_GATEWAYLANG['product_status_terminated'] = 'terminado';
$_GATEWAYLANG['product_status_cancelled'] = 'cancelado';
$_GATEWAYLANG['product_status_fraud'] = 'fraudulento';
$_GATEWAYLANG['product_status_expired'] = 'expirado';

// AutoPix - Botones
$_GATEWAYLANG['btn_generate_qrcode'] = 'Generar Código QR';
$_GATEWAYLANG['btn_recover_qrcode'] = 'Recuperar Código QR';
$_GATEWAYLANG['btn_consent_active'] = 'Consentimiento Activo';
$_GATEWAYLANG['btn_awaiting_acceptance'] = 'Esperando Aceptación';
$_GATEWAYLANG['btn_sending'] = 'Generando...';
$_GATEWAYLANG['btn_recovering'] = 'Recuperando...';
$_GATEWAYLANG['btn_request_sent_success'] = '¡Código QR generado con éxito!';
$_GATEWAYLANG['btn_error'] = 'Error: :message';
$_GATEWAYLANG['btn_network_error'] = 'Error de red';
$_GATEWAYLANG['btn_learn_more'] = 'Saber más';
$_GATEWAYLANG['btn_hide'] = 'Ocultar';
$_GATEWAYLANG['btn_pending_invoice_tooltip'] = 'Para generar el código QR, todas las facturas pendientes de este producto deben estar pagadas';
$_GATEWAYLANG['btn_inactive_product_tooltip'] = 'Este producto está :status, para generar el código QR este producto debe estar activo';

// AutoPix - Filtros
$_GATEWAYLANG['filter_by_status'] = 'Filtrar por estado';
$_GATEWAYLANG['filter_all_services'] = 'Todos los servicios';
$_GATEWAYLANG['btn_clear_filters'] = 'Limpiar filtros';

// AutoPix - Mensajes
$_GATEWAYLANG['no_services_found'] = 'No se encontraron servicios activos';
$_GATEWAYLANG['no_services_message'] = 'No tiene servicios activos para configurar PIX Automático.';
$_GATEWAYLANG['btn_contract_services'] = 'Contratar Servicios';

// AutoPix - Panel de Información
$_GATEWAYLANG['how_autopix_works'] = 'Cómo Funciona PIX Automático';
$_GATEWAYLANG['autopix_description'] = '<strong>PIX Automático</strong> permite que sus facturas se paguen automáticamente al vencimiento, directamente desde su cuenta bancaria, sin necesidad de generar un nuevo código PIX cada mes.';
$_GATEWAYLANG['autopix_notification_title'] = 'Cómo Activar (Jornada 2):';
$_GATEWAYLANG['autopix_notification_description'] = 'Al hacer clic en <strong>Generar Código QR</strong>, se mostrará un código QR en la pantalla. Abra la aplicación de su banco, escanee el código QR con la cámara y acepte el consentimiento. Tras la confirmación, sus facturas se pagarán automáticamente al vencimiento.';
$_GATEWAYLANG['autopix_feature_automatic'] = '<strong>Pago automático:</strong> Sus facturas se pagan automáticamente al vencimiento';
$_GATEWAYLANG['autopix_feature_secure'] = '<strong>Seguro:</strong> Transacciones autorizadas y procesadas por Banco do Brasil';
$_GATEWAYLANG['autopix_feature_control'] = '<strong>Control total:</strong> Puede revocar el consentimiento en cualquier momento';

// AutoPix - Control de Acceso
$_GATEWAYLANG['access_restricted'] = 'Acceso Restringido';
$_GATEWAYLANG['access_denied_country'] = 'PIX Automático está disponible solo para clientes en Brasil.';
$_GATEWAYLANG['access_denied_verification'] = 'No se pudo verificar su información. Por favor, intente nuevamente.';

