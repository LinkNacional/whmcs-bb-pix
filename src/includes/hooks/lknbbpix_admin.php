<?php

use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/gateways/lknbbpix/vendor/autoload.php';

add_hook('AdminAreaFooterOutput', 1, function (): string {
    try {
        $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

        if ($currentPage !== 'clientssummary.php') {
            return '';
        }

        $userId = (int) ($_GET['userid'] ?? $_GET['id'] ?? 0);

        if ($userId <= 0) {
            return '';
        }

        if (!Config::setting('enable_pix_automatic')) {
            return '';
        }

        $authRows = Capsule::table('mod_lknbbpix_auths')
            ->where('client_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id_rec', 'periodicidade', 'due_day', 'status', 'updated_at']);

        $apiUrl = '/modules/gateways/lknbbpix/api.php';

        $clientAutoEnabled = true;

        if (Capsule::schema()->hasTable('mod_lknbbpix_client_auto_settings')) {
            $clientAutoSettings = Capsule::table('mod_lknbbpix_client_auto_settings')
                ->where('client_id', $userId)
                ->first(['auto_enabled']);

            if ($clientAutoSettings) {
                $clientAutoEnabled = ((int) ($clientAutoSettings->auto_enabled ?? 1)) === 1;
            }
        }

        $statusClasses = [
            'APROVADA' => 'label label-success',
            'CRIADA' => 'label label-warning',
            'REJEITADA' => 'label label-danger',
            'CANCELADA' => 'label label-danger',
            'REVOGADA' => 'label label-default',
        ];

        $rowsHtml = '';

        foreach ($authRows as $row) {
            $status = strtoupper(trim((string) ($row->status ?? '')));
            $statusClass = $statusClasses[$status] ?? 'label label-default';
            $canCancel = in_array($status, ['CRIADA', 'APROVADA'], true);
            $cancelStyle = $canCancel ? '' : ' style="display:none;"';
            $periodicidade = htmlspecialchars((string) ($row->periodicidade ?? ''), ENT_QUOTES, 'UTF-8');
            $dueDay = (int) ($row->due_day ?? 0);
            $updatedAt = htmlspecialchars((string) ($row->updated_at ?? ''), ENT_QUOTES, 'UTF-8');
            $idRec = htmlspecialchars((string) ($row->id_rec ?? ''), ENT_QUOTES, 'UTF-8');
            $statusLabel = htmlspecialchars($status === '' ? 'DESCONHECIDO' : $status, ENT_QUOTES, 'UTF-8');

            $rowsHtml .= '<tr data-id-rec="' . $idRec . '">'
                . '<td><span class="' . $statusClass . ' lknbbpix-status-badge">' . $statusLabel . '</span></td>'
                . '<td title="' . $updatedAt . '">' . $dueDay . ' / ' . $periodicidade . '</td>'
                . '<td class="text-right">'
                . '<a href="javascript:void(0);" class="text-danger lknbbpix-cancel-auth"' . $cancelStyle . ' '
                . 'data-id-rec="' . $idRec . '" title="Cancelar autorizacao">'
                . '<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>'
                . '</a>'
                . '</td>'
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="3" class="text-muted">Nenhuma autorização Pix Automático cadastrada para este cliente.</td></tr>';
        }

        $toggleLabel = $clientAutoEnabled ? 'Ativado' : 'Desativado';
        $toggleClass = $clientAutoEnabled ? 'btn-success' : 'btn-default';

        $panelHtml = '<div id="lknbbpix-auto-panel" class="clientssummarybox">'
            . '<div class="panel panel-default">'
            . '<div class="panel-heading">'
            . '<div class="clearfix">'
            . '<strong style="line-height:28px;">PIX Automatico</strong>'
            . '<button type="button" id="lknbbpix-toggle-auto" class="btn btn-xs ' . $toggleClass . ' pull-right" data-enabled="' . ($clientAutoEnabled ? '1' : '0') . '">' . $toggleLabel . '</button>'
            . '</div>'
            . '</div>'
            . '<div class="panel-body" style="padding:0;">'
            . '<div id="lknbbpix-auto-feedback" style="display:none; margin:10px;" class="alert" role="alert"></div>'
            . '<div class="table-responsive">'
            . '<table class="table table-striped table-condensed" style="margin-bottom:0;">'
            . '<thead><tr>'
            . '<th>Status</th>'
            . '<th>Periodicidade</th>'
            . '<th class="text-right">Ações</th>'
            . '</tr></thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '</table>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<div class="modal fade" id="lknbbpix-cancel-modal" tabindex="-1" role="dialog" aria-hidden="true">'
            . '<div class="modal-dialog modal-sm" role="document">'
            . '<div class="modal-content">'
            . '<div class="modal-header">'
            . '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
            . '<h4 class="modal-title">Cancelar autorizacao</h4>'
            . '</div>'
            . '<div class="modal-body">'
            . '<p>Confirma o cancelamento desta autorizacao de Pix Automatico?</p>'
            . '<p><small>Essa acao impede novas cobrancas automaticas para este ciclo.</small></p>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-default" data-dismiss="modal">Fechar</button>'
            . '<button type="button" class="btn btn-danger" id="lknbbpix-confirm-cancel">Confirmar cancelamento</button>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        $panelHtmlJson = json_encode($panelHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $apiUrlJson = json_encode($apiUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $clientAutoEnabledJson = json_encode($clientAutoEnabled);

        return '<script>'
            . 'jQuery(function($){'
            . 'if ($("#lknbbpix-auto-panel").length) { return; }'
            . 'var meuPainelHtml = ' . $panelHtmlJson . ';'
            . 'var apiUrl = ' . $apiUrlJson . ';'
            . 'var clientAutoEnabled = ' . $clientAutoEnabledJson . ';'
            . 'var selectedIdRec = "";'
            . 'var selectedRow = null;'
            . 'var selectedIcon = null;'
            . 'var isLoading = false;'
            . 'var getCsrfToken = function(){'
            . 'if (typeof window.csrfToken === "string" && window.csrfToken !== "") { return window.csrfToken; }'
            . 'var hiddenToken = $("input[name=token]").first().val();'
            . 'if (typeof hiddenToken === "string" && hiddenToken !== "") { return hiddenToken; }'
            . 'if (typeof serverCsrfToken === "string" && serverCsrfToken !== "") { return serverCsrfToken; }'
            . 'return "";'
            . '};'
            . 'var setFeedback = function(type, message){'
            . 'var box = $("#lknbbpix-auto-feedback");'
            . 'box.removeClass("alert-success alert-danger alert-warning");'
            . 'box.addClass(type === "success" ? "alert-success" : "alert-danger");'
            . 'box.text(message).show();'
            . '};'
            . 'var syncToggleButton = function(){'
            . 'var button = $("#lknbbpix-toggle-auto");'
            . 'if (!button.length) { return; }'
            . 'button.removeClass("btn-success btn-default");'
            . 'button.addClass(clientAutoEnabled ? "btn-success" : "btn-default");'
            . 'button.text(clientAutoEnabled ? "Ativado" : "Desativado");'
            . 'button.attr("data-enabled", clientAutoEnabled ? "1" : "0");'
            . '};'
            . 'if ($("#tablePayMethods").closest(".clientssummarybox").length) {'
            . '$("#tablePayMethods").closest(".clientssummarybox").after(meuPainelHtml);'
            . '} else {'
            . 'var fallbackAnchor = $(".clientssummarybox").first();'
            . 'if (fallbackAnchor.length) { fallbackAnchor.after(meuPainelHtml); }'
            . '}'
            . 'syncToggleButton();'
            . '$(document).on("click", "#lknbbpix-toggle-auto", function(){'
            . 'if (isLoading) { return; }'
            . 'var csrfToken = getCsrfToken();'
            . 'if (!csrfToken) { setFeedback("error", "Token CSRF não encontrado."); return; }'
            . 'var targetEnabled = !clientAutoEnabled;'
            . 'isLoading = true;'
            . '$(this).prop("disabled", true).text("Salvando...");'
            . 'fetch(apiUrl, {'
            . 'method: "POST",'
            . 'headers: {"Content-Type": "application/json", "X-CSRF-Token": csrfToken},'
            . 'body: JSON.stringify({'
            . 'action: "toggle-client-auto-pix",'
            . 'clientId: ' . $userId . ','
            . 'enabled: targetEnabled,'
            . 'csrfToken: csrfToken,'
            . 'token: csrfToken'
            . '})'
            . '})'
            . '.then(function(response){ return response.json(); })'
            . '.then(function(payload){'
            . 'if (payload && payload.success) {'
            . 'clientAutoEnabled = !!(payload.data && payload.data.enabled);'
            . 'syncToggleButton();'
            . 'setFeedback("success", (payload.data && payload.data.message) ? payload.data.message : "Configuração atualizada com sucesso.");'
            . 'return;'
            . '}'
            . 'var errorMessage = (payload && payload.data && (payload.data.error || payload.data.message)) ? (payload.data.error || payload.data.message) : "Falha ao atualizar configuração de Pix Automático.";'
            . 'setFeedback("error", errorMessage);'
            . 'syncToggleButton();'
            . '})'
            . '.catch(function(){'
            . 'setFeedback("error", "Erro de comunicação ao atualizar configuração de Pix Automático.");'
            . 'syncToggleButton();'
            . '})'
            . '.finally(function(){'
            . 'isLoading = false;'
            . '$("#lknbbpix-toggle-auto").prop("disabled", false);'
            . '});'
            . '});'
            . '$(document).on("click", ".lknbbpix-cancel-auth", function(){'
            . 'if (isLoading) { return; }'
            . 'selectedRow = $(this).closest("tr");'
            . 'selectedIdRec = String($(this).data("id-rec") || "");'
            . 'selectedIcon = $(this).find(".glyphicon");'
            . 'if (!selectedIdRec) { setFeedback("error", "idRec inválido."); return; }'
            . '$("#lknbbpix-cancel-modal").modal("show");'
            . '});'
            . '$(document).on("click", "#lknbbpix-confirm-cancel", function(){'
            . 'if (isLoading || !selectedIdRec) { return; }'
            . 'var csrfToken = getCsrfToken();'
            . 'if (!csrfToken) { setFeedback("error", "Token CSRF não encontrado."); return; }'
            . 'isLoading = true;'
            . '$(this).prop("disabled", true).text("Cancelando...");'
            . 'if (selectedIcon && selectedIcon.length) {'
            . 'selectedIcon.removeClass("glyphicon-trash").addClass("glyphicon-refresh glyphicon-spin");'
            . '}'
            . 'fetch(apiUrl, {'
            . 'method: "POST",'
            . 'headers: {"Content-Type": "application/json", "X-CSRF-Token": csrfToken},'
            . 'body: JSON.stringify({'
            . 'action: "cancel-auto-auth",'
            . 'idRec: selectedIdRec,'
            . 'clientId: ' . $userId . ','
            . 'csrfToken: csrfToken,'
            . 'token: csrfToken'
            . '})'
            . '})'
            . '.then(function(response){ return response.json(); })'
            . '.then(function(payload){'
            . 'if (payload && payload.success) {'
            . 'setFeedback("success", (payload.data && payload.data.message) ? payload.data.message : "Autorização cancelada com sucesso.");'
            . 'if (selectedRow && selectedRow.length) {'
            . 'var badge = selectedRow.find(".lknbbpix-status-badge");'
            . 'badge.removeClass("label-success label-warning label-default label-danger").addClass("label-danger").text("CANCELADA");'
            . 'selectedRow.find(".lknbbpix-cancel-auth").hide();'
            . '}'
            . '$("#lknbbpix-cancel-modal").modal("hide");'
            . 'return;'
            . '}'
            . 'var errorMessage = (payload && payload.data && (payload.data.error || payload.data.message)) ? (payload.data.error || payload.data.message) : "Falha ao cancelar autorização.";'
            . 'setFeedback("error", errorMessage);'
            . 'if (selectedIcon && selectedIcon.length) {'
            . 'selectedIcon.removeClass("glyphicon-refresh glyphicon-spin").addClass("glyphicon-trash");'
            . '}'
            . '})'
            . '.catch(function(){'
            . 'setFeedback("error", "Erro de comunicação ao cancelar autorização.");'
            . 'if (selectedIcon && selectedIcon.length) {'
            . 'selectedIcon.removeClass("glyphicon-refresh glyphicon-spin").addClass("glyphicon-trash");'
            . '}'
            . '})'
            . '.finally(function(){'
            . 'isLoading = false;'
            . '$("#lknbbpix-confirm-cancel").prop("disabled", false).text("Confirmar cancelamento");'
            . '});'
            . '});'
            . '});'
            . '</script>';
    } catch (Throwable $e) {
        Logger::log(
            'Renderizar painel PIX Automático no client summary',
            ['page' => (string) ($_SERVER['PHP_SELF'] ?? ''), 'userid' => (int) ($_GET['userid'] ?? $_GET['id'] ?? 0)],
            ['error' => $e->getMessage()]
        );

        return '';
    }
});
