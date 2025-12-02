<?php
use WHMCS\Database\Capsule;
use Lkn\BBPix\Helpers\Lang;

if(!defined("WHMCS")){
	exit("This file cannot be accessed directly");
}

// Load translations
Lang::load();

$clientId = (int) ($_SESSION['uid'] ?? 0);
$items = [];

// Buscar itens com faturas não pagas UMA ÚNICA VEZ
$itemsWithUnpaidInvoices = [];
if($clientId > 0){
	try {
		// Buscar todas as faturas não pagas do cliente
		$unpaidInvoices = Capsule::table('tblinvoices')
			->where('userid', $clientId)
			->where('status', 'Unpaid')
			->pluck('id');
		
		if (!empty($unpaidInvoices)) {
			// Buscar todos os itens dessas faturas de uma só vez
			$unpaidItems = Capsule::table('tblinvoiceitems')
				->whereIn('invoiceid', $unpaidInvoices)
				->whereIn('type', ['Hosting', 'Domain'])
				->select('type', 'relid')
				->get();
			
			// Criar mapa de itens com pendências: tipo => [ids]
			foreach ($unpaidItems as $item) {
				$type = strtolower($item->type); // 'hosting' ou 'domain'
				$relid = (int) $item->relid;
				
				if (!isset($itemsWithUnpaidInvoices[$type])) {
					$itemsWithUnpaidInvoices[$type] = [];
				}
				$itemsWithUnpaidInvoices[$type][] = $relid;
			}
		}
	} catch (\Throwable $e) {
		// Se falhar, continua com array vazio
	}
}

if($clientId > 0){
	// Buscar produtos
	$products = localAPI('GetClientsProducts', ['clientid' => $clientId, 'limitnum' => 250]);
	$productList = $products['products']['product'] ?? [];

	foreach($productList as $p){
		$serviceStatus = strtolower((string)($p['status'] ?? ''));
		$serviceId = (int) ($p['id'] ?? 0);
		$productName = trim((string) ($p['name'] ?? ''));
		$groupName = trim((string) ($p['groupname'] ?? ''));
		$amount = (float) ($p['recurringamount'] ?? 0);
		$billingCycle = trim((string) ($p['billingcycle'] ?? ''));

		// Buscar o tipo do produto (hostingaccount, reselleraccount, server, other)
		$productType = 'other';
		try {
			$hosting = Capsule::table('tblhosting')
				->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
				->where('tblhosting.id', $serviceId)
				->select('tblproducts.type')
				->first();
			if ($hosting) {
				$productType = strtolower(trim((string) $hosting->type));
			}
		} catch (\Throwable $e) {
			// Fallback to 'other'
		}

		// Traduzir tipo de produto
		$typeLabel = Lang::trans('type_other');
		$typeMap = [
			'hostingaccount' => Lang::trans('type_shared_hosting'),
			'reselleraccount' => Lang::trans('type_reseller'),
			'server' => Lang::trans('type_server_vps'),
			'other' => Lang::trans('type_other')
		];
		if (isset($typeMap[$productType])) {
			$typeLabel = $typeMap[$productType];
		}

		// Traduzir ciclo de pagamento
		$billingCycleLabel = $billingCycle;
		$cycleMap = [
			'Monthly' => Lang::trans('cycle_monthly'),
			'Quarterly' => Lang::trans('cycle_quarterly'),
			'Semi-Annually' => Lang::trans('cycle_semiannually'),
			'Annually' => Lang::trans('cycle_annually'),
			'Biennially' => Lang::trans('cycle_biennially'),
			'Triennially' => Lang::trans('cycle_triennially'),
			'One Time' => Lang::trans('cycle_onetime'),
			'Free Account' => Lang::trans('cycle_free')
		];
		if(isset($cycleMap[$billingCycle])){
			$billingCycleLabel = $cycleMap[$billingCycle];
		}

		$status = Lang::trans('status_no_consent');
		try{
			$consent = Capsule::table('mod_lknbbpix_auto_consents')
				->where('clientid', $clientId)
				->where('serviceid', $serviceId)
				->orderBy('id', 'desc')
				->first();
			if($consent){
				$row = (array) $consent;
				$st = strtolower((string) ($row['status'] ?? ''));
				if($st === 'active'){
					$status = Lang::trans('status_active');
				}elseif($st === 'pending'){
					$status = Lang::trans('status_pending');
				}elseif($st === 'revoked'){
					$status = Lang::trans('status_revoked');
				}else{
					$status = ucfirst($st);
				}
			}
		}catch(\Throwable $e){
			$status = Lang::trans('status_no_consent');
		}

		// Montar nome completo com grupo do produto
		$fullName = $productName;
		if(!empty($groupName)){
			$fullName = $groupName . ' - ' . $productName;
		}

		// Verificar se há fatura em aberto para este produto (usando cache)
		$hasPendingInvoice = isset($itemsWithUnpaidInvoices['hosting']) && 
		                     in_array($serviceId, $itemsWithUnpaidInvoices['hosting']);

		// Traduzir status do produto para o tooltip
		$serviceStatusLabel = $serviceStatus;
		$statusKey = 'product_status_' . $serviceStatus;
		$translated = Lang::trans($statusKey);
		if ($translated !== $statusKey) {
			$serviceStatusLabel = $translated;
		}

		$items[] = [
			'id' => $serviceId,
			'name' => $fullName,
			'product' => $productName,
			'group' => $typeLabel, // Translated product type (Shared Hosting, Server/VPS, Reseller, Other)
			'amount_label' => 'R$ ' . number_format($amount, 2, ',', '.'),
			'amount_num' => $amount,
			'billing_cycle' => $billingCycle,
			'billing_cycle_label' => $billingCycleLabel,
			'status' => $status,
			'service_status' => $serviceStatus,
			'service_status_label' => $serviceStatusLabel,
			'type' => 'product',
			'has_pending_invoice' => $hasPendingInvoice
		];
	}

	// Buscar domínios
	$domains = localAPI('GetClientsDomains', ['clientid' => $clientId, 'limitnum' => 250]);
	$domainList = $domains['domains']['domain'] ?? [];

	foreach($domainList as $d){
		$domainStatus = strtolower((string)($d['status'] ?? ''));
		$domainId = (int) ($d['id'] ?? 0);
		$domainName = trim((string) ($d['domainname'] ?? ''));
		$amount = (float) ($d['recurringamount'] ?? 0);
		$registrationPeriod = (int) ($d['registrationperiod'] ?? 1);

		// Traduzir período de registro
		$yearText = Lang::trans('cycle_years', ['count' => $registrationPeriod]);
		$parts = explode('|', $yearText);
		$billingCycleLabel = $registrationPeriod > 1 && isset($parts[1]) ? $parts[1] : $parts[0];

		$status = Lang::trans('status_no_consent');
		try{
			$consent = Capsule::table('mod_lknbbpix_auto_consents')
				->where('clientid', $clientId)
				->where('domainid', $domainId)
				->orderBy('id', 'desc')
				->first();
			if($consent){
				$row = (array) $consent;
				$st = strtolower((string) ($row['status'] ?? ''));
				if($st === 'active'){
					$status = Lang::trans('status_active');
				}elseif($st === 'pending'){
					$status = Lang::trans('status_pending');
				}elseif($st === 'revoked'){
					$status = Lang::trans('status_revoked');
				}else{
					$status = ucfirst($st);
				}
			}
		}catch(\Throwable $e){
			$status = Lang::trans('status_no_consent');
		}

		// Verificar se há fatura em aberto para este domínio (usando cache)
		$hasPendingInvoice = isset($itemsWithUnpaidInvoices['domain']) && 
		                     in_array($domainId, $itemsWithUnpaidInvoices['domain']);

		// Traduzir status do domínio para o tooltip
		$domainStatusLabel = $domainStatus;
		$statusKey = 'product_status_' . $domainStatus;
		$translated = Lang::trans($statusKey);
		if ($translated !== $statusKey) {
			$domainStatusLabel = $translated;
		}

		$items[] = [
			'id' => $domainId,
			'name' => $domainName,
			'product' => $domainName,
			'group' => Lang::trans('type_domain'), // Translated: "Domain", "Domínio", etc
			'amount_label' => 'R$ ' . number_format($amount, 2, ',', '.'),
			'amount_num' => $amount,
			'billing_cycle' => $registrationPeriod . 'Y',
			'billing_cycle_label' => $billingCycleLabel,
			'status' => $status,
			'service_status' => $domainStatus,
			'service_status_label' => $domainStatusLabel,
			'type' => 'domain',
			'has_pending_invoice' => $hasPendingInvoice
		];
	}
}

// Renderizar template usando o View helper do módulo
$html = Lkn\BBPix\Helpers\View::render('autopix.index', [
	'items' => $items,
	'lang' => Lang::all(),
	'datatables_lang_url' => Lang::getDataTablesLanguageUrl(),
	'current_language' => Lang::getCurrentLanguage(),
	'client_id' => $clientId
]);

$tplvars = [];
$tplvars['content'] = $html;
$tplvars['pagecontent'] = $html;
$pageTitle = Lang::trans('autopix_page_title');
$tagline = Lang::trans('autopix_tagline');
$tplfile = 'clientareapage';