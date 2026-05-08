<?php

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

        $authRows = Capsule::table('mod_lknbbpix_auths')
            ->where('client_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id_rec', 'periodicidade', 'due_day', 'status', 'updated_at']);

        if ($authRows->isEmpty()) {
            return '';
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
            $periodicidade = htmlspecialchars((string) ($row->periodicidade ?? ''), ENT_QUOTES, 'UTF-8');
            $dueDay = (int) ($row->due_day ?? 0);
            $updatedAt = htmlspecialchars((string) ($row->updated_at ?? ''), ENT_QUOTES, 'UTF-8');
            $idRec = htmlspecialchars((string) ($row->id_rec ?? ''), ENT_QUOTES, 'UTF-8');
            $statusLabel = htmlspecialchars($status === '' ? 'DESCONHECIDO' : $status, ENT_QUOTES, 'UTF-8');

            $rowsHtml .= '<tr>'
                . '<td><span class="' . $statusClass . '">' . $statusLabel . '</span></td>'
                . '<td title="' . $updatedAt . '">' . $dueDay . ' / ' . $periodicidade . '</td>'
                . '<td class="text-right">'
                . '<a href="javascript:void(0);" class="text-danger lknbbpix-cancel-auth" '
                . 'data-id-rec="' . $idRec . '" title="Cancelar autorizacao">'
                . '<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>'
                . '</a>'
                . '</td>'
                . '</tr>';
        }

        $panelHtml = '<div id="lknbbpix-auto-panel" class="clientssummarybox">'
            . '<div class="panel panel-default">'
            . '<div class="panel-heading"><strong>PIX Automatico</strong></div>'
            . '<div class="panel-body" style="padding:0;">'
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
            . '</div>';

        $panelHtmlJson = json_encode($panelHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return '<script>'
            . 'jQuery(function($){'
            . 'if ($("#lknbbpix-auto-panel").length) { return; }'
            . 'var meuPainelHtml = ' . $panelHtmlJson . ';'
            . 'if ($("#tablePayMethods").closest(".clientssummarybox").length) {'
            . '$("#tablePayMethods").closest(".clientssummarybox").after(meuPainelHtml);'
            . 'return;'
            . '}'
            . 'var fallbackAnchor = $(".clientssummarybox").first();'
            . 'if (fallbackAnchor.length) {'
            . 'fallbackAnchor.after(meuPainelHtml);'
            . '}'
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
