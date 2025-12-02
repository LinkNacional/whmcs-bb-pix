<?php
/**
 * LKNBBPIX Language File
 * English (en)
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
$_GATEWAYLANG['autopix_title'] = 'Automatic PIX';
$_GATEWAYLANG['autopix_tagline'] = 'Configure automatic payment of your invoices via PIX';
$_GATEWAYLANG['autopix_page_title'] = 'Automatic PIX';

// AutoPix - Table Headers
$_GATEWAYLANG['autopix_table_product_service'] = 'Product/Service';
$_GATEWAYLANG['autopix_table_type'] = 'Type';
$_GATEWAYLANG['autopix_table_billing_cycle'] = 'Billing Cycle';
$_GATEWAYLANG['autopix_table_recurring_amount'] = 'Recurring Amount';
$_GATEWAYLANG['autopix_table_status'] = 'Automatic PIX Status';
$_GATEWAYLANG['autopix_table_actions'] = 'Actions';

// AutoPix - Billing Cycles
$_GATEWAYLANG['cycle_monthly'] = 'Monthly';
$_GATEWAYLANG['cycle_quarterly'] = 'Quarterly';
$_GATEWAYLANG['cycle_semiannually'] = 'Semi-Annually';
$_GATEWAYLANG['cycle_annually'] = 'Annually';
$_GATEWAYLANG['cycle_biennially'] = 'Biennially';
$_GATEWAYLANG['cycle_triennially'] = 'Triennially';
$_GATEWAYLANG['cycle_onetime'] = 'One Time';
$_GATEWAYLANG['cycle_free'] = 'Free';
$_GATEWAYLANG['cycle_years'] = ':count year|:count years';

// AutoPix - Product Types
$_GATEWAYLANG['type_domain'] = 'Domain';
$_GATEWAYLANG['type_shared_hosting'] = 'Shared Hosting';
$_GATEWAYLANG['type_server_vps'] = 'Server/VPS';
$_GATEWAYLANG['type_reseller'] = 'Reseller';
$_GATEWAYLANG['type_other'] = 'Other';

// AutoPix - Status
$_GATEWAYLANG['status_active'] = 'Active';
$_GATEWAYLANG['status_pending'] = 'Pending';
$_GATEWAYLANG['status_revoked'] = 'Revoked';
$_GATEWAYLANG['status_no_consent'] = 'Inactive';

// AutoPix - Product/Domain Status WHMCS
$_GATEWAYLANG['product_status_pending'] = 'pending';
$_GATEWAYLANG['product_status_suspended'] = 'suspended';
$_GATEWAYLANG['product_status_terminated'] = 'terminated';
$_GATEWAYLANG['product_status_cancelled'] = 'cancelled';
$_GATEWAYLANG['product_status_fraud'] = 'fraud';
$_GATEWAYLANG['product_status_expired'] = 'expired';

// AutoPix - Buttons
$_GATEWAYLANG['btn_generate_qrcode'] = 'Generate QR Code';
$_GATEWAYLANG['btn_recover_qrcode'] = 'Recover QR Code';
$_GATEWAYLANG['btn_consent_active'] = 'Consent Active';
$_GATEWAYLANG['btn_awaiting_acceptance'] = 'Awaiting Acceptance';
$_GATEWAYLANG['btn_sending'] = 'Generating...';
$_GATEWAYLANG['btn_recovering'] = 'Recovering...';
$_GATEWAYLANG['btn_request_sent_success'] = 'QR Code generated successfully!';
$_GATEWAYLANG['btn_error'] = 'Error: :message';
$_GATEWAYLANG['btn_network_error'] = 'Network error';
$_GATEWAYLANG['btn_learn_more'] = 'Learn More';
$_GATEWAYLANG['btn_hide'] = 'Hide';
$_GATEWAYLANG['btn_pending_invoice_tooltip'] = 'To generate the QR Code, all outstanding invoices for this product must be paid';
$_GATEWAYLANG['btn_inactive_product_tooltip'] = 'This product is :status, to generate the QR Code this product must be active';

// AutoPix - Filters
$_GATEWAYLANG['filter_by_status'] = 'Filter by status';
$_GATEWAYLANG['filter_all_services'] = 'All services';
$_GATEWAYLANG['btn_clear_filters'] = 'Clear filters';

// AutoPix - Messages
$_GATEWAYLANG['no_services_found'] = 'No active services found';
$_GATEWAYLANG['no_services_message'] = 'You do not have active services to configure Automatic PIX.';
$_GATEWAYLANG['btn_contract_services'] = 'Contract Services';

// AutoPix - Information Panel
$_GATEWAYLANG['how_autopix_works'] = 'How Automatic PIX Works';
$_GATEWAYLANG['autopix_description'] = '<strong>Automatic PIX</strong> allows your invoices to be paid automatically upon expiration, directly from your bank account, without the need to generate a new PIX code every month.';
$_GATEWAYLANG['autopix_notification_title'] = 'How to Activate (Journey 2):';
$_GATEWAYLANG['autopix_notification_description'] = 'When you click <strong>Generate QR Code</strong>, a QR Code will be displayed on the screen. Open your bank\'s app, scan the QR Code with the camera, and accept the consent. After confirmation, your invoices will be automatically paid upon expiration.';
$_GATEWAYLANG['autopix_feature_automatic'] = '<strong>Automatic payment:</strong> Your invoices are paid automatically upon expiration';
$_GATEWAYLANG['autopix_feature_secure'] = '<strong>Secure:</strong> Transactions authorized and processed by Banco do Brasil';
$_GATEWAYLANG['autopix_feature_control'] = '<strong>Total control:</strong> You can revoke consent at any time';

// AutoPix - Access Control
$_GATEWAYLANG['access_restricted'] = 'Access Restricted';
$_GATEWAYLANG['access_denied_country'] = 'Automatic PIX is available only for clients in Brazil.';
$_GATEWAYLANG['access_denied_verification'] = 'Unable to verify your information. Please try again.';

