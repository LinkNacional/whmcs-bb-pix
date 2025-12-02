{* Tela de PIX Automático - Consistente com Lagom2 *}
{assign var=iconsPages value=['clientareadomains', 'supportticketslist', 'clientareainvoices', 'clientareaproducts', 'clientareaquotes']}

<style>
.autopix-info-content {
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    display: none;
}
.autopix-info-content.open {
    display: block;
    max-height: 1000px;
    opacity: 1;
    animation: slideDown 0.45s ease-in-out;
}
.autopix-info-content.closing {
    display: block;
    animation: slideUp 0.45s ease-in-out;
}
@keyframes slideDown {
    from {
        max-height: 0;
        opacity: 0;
    }
    to {
        max-height: 1000px;
        opacity: 1;
    }
}
@keyframes slideUp {
    from {
        max-height: 1000px;
        opacity: 1;
    }
    to {
        max-height: 0;
        opacity: 0;
    }
}
.autopix-chevron-icon {
    transition: transform 0.3s ease-in-out;
}
.autopix-chevron-icon.rotate {
    transform: rotate(180deg);
}

/* Responsividade da Tabela */
.table-container {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Mobile - Tablets pequenos e celulares */
@media screen and (max-width: 768px) {
    .table-container {
        margin: 0 -15px;
        width: calc(100% + 30px);
    }
    
    #tableAutoPixList {
        min-width: 700px;
        font-size: 13px;
    }
    
    #tableAutoPixList th,
    #tableAutoPixList td {
        padding: 8px 5px !important;
        white-space: nowrap;
    }
    
    #tableAutoPixList .btn {
        font-size: 12px;
        padding: 5px 8px;
    }
    
    #tableAutoPixList .btn .btn-text {
        display: none;
    }
    
    #tableAutoPixList .label {
        font-size: 11px;
        padding: 3px 6px;
    }
    
    /* Ajustar header do painel info */
    .panel-title {
        font-size: 14px;
    }
    
    #toggleAutoPixBtn {
        font-size: 12px;
        padding: 2px 5px;
    }
}

/* Celulares muito pequenos */
@media screen and (max-width: 480px) {
    #tableAutoPixList {
        min-width: 650px;
        font-size: 12px;
    }
    
    #tableAutoPixList th,
    #tableAutoPixList td {
        padding: 6px 3px !important;
    }
    
    #tableAutoPixList .btn {
        padding: 4px 6px;
    }
    
    /* Deixar apenas os ícones visíveis nos botões */
    #tableAutoPixList .btn i {
        margin: 0;
    }
}

/* Tablets em modo paisagem */
@media screen and (min-width: 769px) and (max-width: 1024px) {
    #tableAutoPixList {
        font-size: 14px;
    }
    
    #tableAutoPixList th,
    #tableAutoPixList td {
        padding: 10px 8px !important;
    }
}

/* Indicador visual de scroll horizontal */
.table-container::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(to left, rgba(255,255,255,0.9), transparent);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s;
}

.table-container.has-scroll::after {
    opacity: 1;
}

@media screen and (max-width: 768px) {
    .table-container::after {
        opacity: 1;
    }
}

/* Responsividade do Modal QR Code */
@media screen and (max-width: 768px) {
    #modalQRCode .modal-dialog {
        margin: 10px;
        width: calc(100% - 20px);
    }
    
    #modalQRCode .modal-header h4 {
        font-size: 16px;
    }
    
    #modalQRCode .modal-body {
        padding: 15px;
    }
    
    #qrcodeContainer img {
        max-width: 100% !important;
        width: 250px !important;
        height: auto;
    }
    
    #pixCopiaECola {
        font-size: 10px !important;
    }
    
    #modalQRCode .alert {
        font-size: 13px;
        padding: 10px;
    }
    
    #modalQRCode .input-group .btn {
        padding: 6px 10px;
        font-size: 13px;
    }
}

@media screen and (max-width: 480px) {
    #qrcodeContainer img {
        width: 200px !important;
    }
    
    #modalQRCode .modal-header h4 {
        font-size: 14px;
    }
    
    #modalQRCode .alert {
        font-size: 12px;
    }
}
</style>

{* Bloco informativo sobre PIX Automático *}
<div class="panel panel-default" style="margin-bottom: 20px;">
    <div class="panel-heading" style="cursor: pointer;" onclick="toggleAutoPixInfo()">
        <h5 class="panel-title" style="display: flex; justify-content: space-between; align-items: center; margin: 0;">
            <span>
                <i class="fas fa-info-circle"></i>
                {$lang.how_autopix_works|escape}
            </span>
            <button type="button" class="btn btn-sm btn-link" id="toggleAutoPixBtn" style="text-decoration: none; color: inherit;">
                <span id="toggleAutoPixText">{$lang.btn_learn_more|escape}</span>
                <i class="fas fa-chevron-down autopix-chevron-icon" id="toggleAutoPixIcon"></i>
            </button>
        </h5>
    </div>
    <div class="panel-body autopix-info-content" id="autoPixInfoContent">
        <p>{$lang.autopix_description}</p>
        
        <div class="alert alert-info" style="margin-top: 15px; margin-bottom: 15px;">
            <i class="fas fa-bell"></i>
            <strong>{$lang.autopix_notification_title|escape}</strong><br>
            {$lang.autopix_notification_description}
        </div>
        
        <ul class="list-unstyled" style="margin-top: 15px;">
            <li style="margin-bottom: 8px;">
                <i class="fas fa-check text-success"></i>
                {$lang.autopix_feature_automatic}
            </li>
            <li style="margin-bottom: 8px;">
                <i class="fas fa-check text-success"></i>
                {$lang.autopix_feature_secure}
            </li>
            <li style="margin-bottom: 8px;">
                <i class="fas fa-check text-success"></i>
                {$lang.autopix_feature_control}
            </li>
        </ul>
    </div>
</div>

<script>
function toggleAutoPixInfo() {
    var content = document.getElementById('autoPixInfoContent');
    var text = document.getElementById('toggleAutoPixText');
    var icon = document.getElementById('toggleAutoPixIcon');
    
    if (content.classList.contains('open')) {
        // Fechar com animação
        content.classList.remove('open');
        content.classList.add('closing');
        text.textContent = '{$lang.btn_learn_more|escape}';
        icon.classList.remove('rotate');
        
        // Remover a classe closing após a animação terminar
        setTimeout(function() {
            content.classList.remove('closing');
        }, 400); // 400ms = duração da animação
    } else {
        // Abrir com animação
        content.classList.add('open');
        text.textContent = '{$lang.btn_hide|escape}';
        icon.classList.add('rotate');
    }
}
</script>

{if $items|@count > 0}
    <div class="table-container loading">
        <table id="tableAutoPixList" class="table table-list">
            <thead>
                <tr>
                    <th data-priority="1">
                        <button type="button" class="btn-table-collapse"></button>
                        {$lang.autopix_table_product_service|escape}<span class="sorting-arrows"></span>
                    </th>
                    <th data-priority="5">{$lang.autopix_table_type|escape}<span class="sorting-arrows"></span></th>
                    <th data-priority="4">{$lang.autopix_table_billing_cycle|escape}<span class="sorting-arrows"></span></th>
                    <th data-priority="3">{$lang.autopix_table_recurring_amount|escape}<span class="sorting-arrows"></span></th>
                    <th data-priority="2">{$lang.autopix_table_status|escape}<span class="sorting-arrows"></span></th>
                    <th data-priority="6">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$items item=row}
                    <tr data-url="#">
                        <td>
                            <button type="button" class="btn-table-collapse"></button>
                            <b>{$row.name|escape}</b>
                        </td>
                        <td class="text-nowrap">
                            {if $row.group}
                                <span class="label label-default">{$row.group|escape}</span>
                            {else}
                                <span class="text-muted">-</span>
                            {/if}
                        </td>
                        <td class="text-nowrap">
                            {$row.billing_cycle_label|escape}
                        </td>
                        <td class="text-nowrap" data-order="{$row.amount_label|escape}">
                            {$row.amount_label|escape}
                        </td>
                        <td class="text-nowrap">
                            {if $row.status == $lang.status_active}
                                <span style="color: #28a745; font-size: 14px;">●</span>&nbsp;{$row.status|escape}
                            {elseif $row.status == $lang.status_pending}
                                <span style="color: #ff9800; font-size: 14px;">●</span>&nbsp;{$row.status|escape}
                            {elseif $row.status == $lang.status_revoked}
                                <span style="color: #dc3545; font-size: 14px;">●</span>&nbsp;{$row.status|escape}
                            {else}
                                <span style="color: #6c757d; font-size: 14px;">●</span>&nbsp;{$row.status|escape}
                            {/if}
                        </td>
                        <td class="cell-action">
                            {if $row.status == $lang.status_active}
                                <button class="btn btn-default btn-sm disabled" style="opacity: 0.65; cursor: not-allowed;" disabled>
                                    <i class="fas fa-qrcode"></i>
                                    <span class="btn-text">{$lang.btn_generate_qrcode|escape}</span>
                                </button>
                            {elseif $row.status == $lang.status_pending}
                                <button class="btn btn-primary btn-sm js-autopix-recover" data-itemid="{$row.id}" data-type="{$row.type}">
                                    <i class="fas fa-qrcode"></i>
                                    <span class="btn-text">{$lang.btn_recover_qrcode|escape}</span>
                                </button>
                            {elseif $row.service_status != 'active'}
                                {assign var=tooltip_text value=$lang.btn_inactive_product_tooltip|replace:':status':$row.service_status_label}
                                <button class="btn btn-default btn-sm disabled" style="opacity: 0.65; cursor: not-allowed;" 
                                        disabled 
                                        data-toggle="tooltip" 
                                        data-placement="top" 
                                        title="{$tooltip_text|escape}">
                                    <i class="fas fa-qrcode"></i>
                                    <span class="btn-text">{$lang.btn_generate_qrcode|escape}</span>
                                </button>
                            {elseif $row.has_pending_invoice}
                                <button class="btn btn-default btn-sm disabled" style="opacity: 0.65; cursor: not-allowed;" 
                                        disabled 
                                        data-toggle="tooltip" 
                                        data-placement="top" 
                                        title="{$lang.btn_pending_invoice_tooltip|escape}">
                                    <i class="fas fa-qrcode"></i>
                                    <span class="btn-text">{$lang.btn_generate_qrcode|escape}</span>
                                </button>
                            {else}
                                <button class="btn btn-primary btn-sm js-autopix-start" data-itemid="{$row.id}" data-type="{$row.type}">
                                    <i class="fas fa-qrcode"></i>
                                    <span class="btn-text">{$lang.btn_generate_qrcode|escape}</span>
                                </button>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        <div class="loader loader-table" id="tableLoading">
            <svg class="loader-spinner" viewBox="0 0 50 50">
                <circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>
            </svg>
        </div>
    </div>

    {* Modal QR Code *}
    <div class="modal fade" id="modalQRCode" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">
                        <i class="fas fa-qrcode"></i> PIX Automático - Autorização
                    </h4>
                </div>
                <div class="modal-body text-center">
                    <p class="text-muted">Escaneie o QR Code no aplicativo do seu banco:</p>
                    <div id="qrcodeContainer" style="margin: 20px 0; min-height: 300px;">
                        <div style="padding: 50px;">
                            <i class="fas fa-spinner fa-spin fa-3x"></i>
                        </div>
                    </div>
                    <div class="alert alert-info" style="margin-top: 20px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Importante:</strong> Após escanear o QR Code, autorize o débito automático no app do seu banco.
                    </div>
                    <hr>
                    <p><small><strong>Ou copie o código PIX:</strong></small></p>
                    <div class="input-group" style="margin-top: 10px;">
                        <input type="text" id="pixCopiaECola" class="form-control" readonly style="font-size: 11px; font-family: monospace;">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" onclick="copyPixCode()">
                                <i class="fas fa-copy"></i> Copiar
                            </button>
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    {* Script para DataTable e funcionalidades *}
    <script>
    (function(){
        // Client ID passado pelo backend
        var clientId = {$client_id|default:0};
        
        // Translations
        var i18n = {
            sending: '{$lang.btn_sending|escape}',
            recovering: '{$lang.btn_recovering|escape}',
            requestSuccess: '{$lang.btn_request_sent_success|escape}',
            statusPending: '{$lang.status_pending|escape}',
            networkError: '{$lang.btn_network_error|escape}',
            error: '{$lang.btn_error|escape}'
        };
        {literal}
        
        // Função para detectar se há scroll horizontal
        function checkTableScroll() {
            var container = document.querySelector('.table-container');
            if (container) {
                var hasScroll = container.scrollWidth > container.clientWidth;
                if (hasScroll) {
                    container.classList.add('has-scroll');
                } else {
                    container.classList.remove('has-scroll');
                }
            }
        }
        
        // Inicializar DataTable
        jQuery(document).ready(function() {
            var table = jQuery('#tableAutoPixList').removeClass('hidden').DataTable({
                "language": {
                    "url": "{/literal}{$datatables_lang_url}{literal}"
                },
                "order": [[0, 'asc']],  // Ordenação alfabética por nome
                "pageLength": 25,
                "dom": "<'row'<'col-sm-12'tr>><'row'<'col-sm-5'i><'col-sm-7'p>><'row'<'col-sm-12'l>>",
                "drawCallback": function() {
                    jQuery('.table-container').removeClass('loading');
                    jQuery('#tableLoading').addClass('hidden');
                    // Inicializar tooltips após renderizar a tabela
                    jQuery('[data-toggle="tooltip"]').tooltip();
                    // Verificar scroll horizontal
                    setTimeout(checkTableScroll, 100);
                }
            });
            
            // Inicializar tooltips na primeira carga
            jQuery('[data-toggle="tooltip"]').tooltip();
            
            // Verificar scroll horizontal na primeira carga e ao redimensionar
            checkTableScroll();
            window.addEventListener('resize', checkTableScroll);
        });

        // Função para copiar código PIX
        window.copyPixCode = function() {
            const input = document.getElementById('pixCopiaECola');
            input.select();
            input.setSelectionRange(0, 99999); // Para mobile
            
            try {
                document.execCommand('copy');
                alert('Código PIX copiado com sucesso!');
            } catch (err) {
                alert('Erro ao copiar. Por favor, copie manualmente.');
            }
        };

        // Função para enviar solicitação de consentimento
        function startConsent(itemId, itemType) {
            const payload = {
                clientid: clientId,
                type: itemType === 'domain' ? 'domain' : 'service'
            };
            
            // Adicionar serviceid ou domainid dependendo do tipo
            if (itemType === 'domain') {
                payload.domainid = itemId;
            } else {
                payload.serviceid = itemId;
            }
            
            return fetch('modules/gateways/lknbbpix/autopix.php?action=start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(r => r.json());
        }

        // Função para recuperar QR Code de consentimento pendente
        function recoverConsent(itemId, itemType) {
            const payload = {
                clientid: clientId,
                type: itemType === 'domain' ? 'domain' : 'service'
            };
            
            // Adicionar serviceid ou domainid dependendo do tipo
            if (itemType === 'domain') {
                payload.domainid = itemId;
            } else {
                payload.serviceid = itemId;
            }
            
            return fetch('modules/gateways/lknbbpix/autopix.php?action=recover', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(r => r.json());
        }

        // Handler de clique nos botões
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.js-autopix-start');
            if (!btn) return;
            
            e.preventDefault();
            const itemId = parseInt(btn.getAttribute('data-itemid')) || 0;
            const itemType = btn.getAttribute('data-type') || 'product';
            
            if (!itemId) return;
            
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i><span class="btn-text">' + i18n.sending + '</span>';
            
            startConsent(itemId, itemType).then(function(res) {
                if (res && res.success) {
                    // Exibir modal com QR Code
                    document.getElementById('qrcodeContainer').innerHTML = 
                        '<img src="' + res.qrcodeImage + '" alt="QR Code PIX Automático" style="max-width: 300px; border: 2px solid #ddd; padding: 10px; background: white;">';
                    document.getElementById('pixCopiaECola').value = res.pixCopiaECola;
                    
                    // Abrir modal
                    jQuery('#modalQRCode').modal('show');
                    
                    // Restaurar botão
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    
                    // Atualizar status na tabela quando o modal for fechado (usar .one para executar apenas uma vez)
                    jQuery('#modalQRCode').one('hidden.bs.modal', function() {
                        const row = btn.closest('tr');
                        const statusCell = row.querySelector('td:nth-child(5)');
                        statusCell.innerHTML = '<span style="color: #ff9800; font-size: 14px;">●</span>&nbsp;' + i18n.statusPending;
                        
                        // Atualizar botão para "Recuperar QR Code"
                        const actionCell = row.querySelector('td.cell-action');
                        actionCell.innerHTML = '<button class="btn btn-warning btn-sm js-autopix-recover" data-itemid="' + itemId + '" data-type="' + itemType + '">' +
                            '<i class="fa fa-qrcode"></i><span class="btn-text">' + i18n.recoverQRCode + '</span>' +
                            '</button>';
                    });
                } else {
                    btn.className = 'btn btn-danger btn-sm';
                    var errorMsg = res.error || 'Erro desconhecido';
                    btn.innerHTML = '<i class="ls ls-close"></i><span class="btn-text">' + errorMsg + '</span>';
                    setTimeout(function() {
                        btn.className = 'btn btn-primary btn-sm js-autopix-start';
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }, 5000);
                }
            }).catch(function(error) {
                console.error('Erro ao criar consentimento:', error);
                btn.className = 'btn btn-danger btn-sm';
                btn.innerHTML = '<i class="ls ls-close"></i><span class="btn-text">' + i18n.networkError + '</span>';
                setTimeout(function() {
                    btn.className = 'btn btn-primary btn-sm js-autopix-start';
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }, 5000);
            });
        });

        // Handler de clique nos botões de recuperar QR Code
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.js-autopix-recover');
            if (!btn) return;
            
            e.preventDefault();
            const itemId = parseInt(btn.getAttribute('data-itemid')) || 0;
            const itemType = btn.getAttribute('data-type') || 'product';
            
            if (!itemId) return;
            
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i><span class="btn-text">' + i18n.recovering + '</span>';
            
            recoverConsent(itemId, itemType).then(function(res) {
                if (res && res.success) {
                    // Exibir modal com QR Code recuperado
                    document.getElementById('qrcodeContainer').innerHTML = 
                        '<img src="' + res.qrcodeImage + '" alt="QR Code PIX Automático" style="max-width: 300px; border: 2px solid #ddd; padding: 10px; background: white;">';
                    document.getElementById('pixCopiaECola').value = res.pixCopiaECola;
                    
                    // Abrir modal
                    jQuery('#modalQRCode').modal('show');
                    
                    // Restaurar botão
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                } else {
                    btn.className = 'btn btn-danger btn-sm';
                    var errorMsg = res.error || 'Erro desconhecido';
                    btn.innerHTML = '<i class="ls ls-close"></i><span class="btn-text">' + errorMsg + '</span>';
                    setTimeout(function() {
                        btn.className = 'btn btn-primary btn-sm js-autopix-recover';
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }, 5000);
                }
            }).catch(function(error) {
                console.error('Erro ao recuperar QR Code:', error);
                btn.className = 'btn btn-danger btn-sm';
                btn.innerHTML = '<i class="ls ls-close"></i><span class="btn-text">' + i18n.networkError + '</span>';
                setTimeout(function() {
                    btn.className = 'btn btn-primary btn-sm js-autopix-recover';
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }, 5000);
            });
        });
        {/literal}
    })();
    </script>
{else}
    <div class="message message-no-data">
        <div class="message-image">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
            </svg>
        </div>
        <h6 class="message-title">{$lang.no_services_found|escape}</h6>
        <p class="text-muted">{$lang.no_services_message|escape}</p>
        <div class="message-action">
            <a class="btn btn-primary" href="cart.php">
                <i class="ls ls-shopping-cart"></i>
                {$lang.btn_contract_services|escape}
            </a>
        </div>
    </div>
{/if}{* end items count check *}