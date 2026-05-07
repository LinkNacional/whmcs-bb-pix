<div style="margin: 10px;">
    <div
        style="display: flex; align-items: center; justify-content: flex-start; align-content: center; flex-wrap: wrap;">
        <div style="margin-right: 6px;">
            <a
                href="https://www.linknacional.com.br/"
                target="_blank"
            >
                <img
                    src="{$logoUrl}"
                    width="200px"
                >
            </a>
        </div>
        <div style="position: relative; bottom: -4px;">
            <p style="font-size: 1.2em;">
                | <strong>Pix - Banco do Brasil</strong>
                <span style="margin-left: 10px;">v{$moduleVersion}</span>
            </p>
        </div>
        <div style="margin-left: 20px;">
            <button
                id="lknbbpix-remove-webhooks-btn"
                type="button"
                style="padding: 6px 14px; font-size: 0.9em; cursor: pointer;"
                onclick="lknBbPixRemoveWebhooks(this)"
            >
                Remover Webhooks do Banco
            </button>
            <span id="lknbbpix-remove-webhooks-msg" style="margin-left: 10px; font-size: 0.85em;"></span>
        </div>
    </div>

    <div style="margin-top: 14px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.88em;">
            <thead>
                <tr style="background: #f5f5f5;">
                    <th style="text-align: left; padding: 6px 10px; border: 1px solid #ddd;">Webhook</th>
                    <th style="text-align: left; padding: 6px 10px; border: 1px solid #ddd;">URL registrada</th>
                    <th style="text-align: left; padding: 6px 10px; border: 1px solid #ddd;">Registrado em</th>
                </tr>
            </thead>
            <tbody>
                <tr id="lknbbpix-wh-row-rec">
                    <td style="padding: 6px 10px; border: 1px solid #ddd; white-space: nowrap;">webhookrec</td>
                    <td style="padding: 6px 10px; border: 1px solid #ddd;" id="lknbbpix-wh-rec-url"><em style="color:#999;">Carregando...</em></td>
                    <td style="padding: 6px 10px; border: 1px solid #ddd; white-space: nowrap;" id="lknbbpix-wh-rec-date"></td>
                </tr>
                <tr id="lknbbpix-wh-row-cobr">
                    <td style="padding: 6px 10px; border: 1px solid #ddd; white-space: nowrap;">webhookcobr</td>
                    <td style="padding: 6px 10px; border: 1px solid #ddd;" id="lknbbpix-wh-cobr-url"><em style="color:#999;">Carregando...</em></td>
                    <td style="padding: 6px 10px; border: 1px solid #ddd; white-space: nowrap;" id="lknbbpix-wh-cobr-date"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function lknBbPixRenderWebhookRow(urlEl, dateEl, webhookData) {
    if (webhookData && webhookData.success && webhookData.data && webhookData.data.webhookUrl) {
        urlEl.innerHTML = '<span style="color:green;">' + webhookData.data.webhookUrl + '</span>';
        dateEl.textContent = webhookData.data.criacao || '';
    } else if (webhookData && !webhookData.success) {
        urlEl.innerHTML = '<em style="color:#999;">Não registrado</em>';
        dateEl.textContent = '';
    } else {
        urlEl.innerHTML = '<em style="color:#c00;">Erro ao consultar</em>';
        dateEl.textContent = '';
    }
}

function lknBbPixLoadWebhooks() {
    var recUrlEl  = document.getElementById('lknbbpix-wh-rec-url');
    var recDateEl = document.getElementById('lknbbpix-wh-rec-date');
    var cobrUrlEl  = document.getElementById('lknbbpix-wh-cobr-url');
    var cobrDateEl = document.getElementById('lknbbpix-wh-cobr-date');

    if (recUrlEl)  recUrlEl.innerHTML  = '<em style="color:#999;">Carregando...</em>';
    if (recDateEl) recDateEl.textContent = '';
    if (cobrUrlEl)  cobrUrlEl.innerHTML  = '<em style="color:#999;">Carregando...</em>';
    if (cobrDateEl) cobrDateEl.textContent = '';

    fetch('{$apiUrl}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get-webhooks' })
    })
    .then(function(res) { return res.json(); })
    .then(function(payload) {
        if (!payload.success) {
            lknBbPixRenderWebhookRow(recUrlEl, recDateEl, null);
            lknBbPixRenderWebhookRow(cobrUrlEl, cobrDateEl, null);
            return;
        }
        lknBbPixRenderWebhookRow(recUrlEl,  recDateEl,  payload.data.rec);
        lknBbPixRenderWebhookRow(cobrUrlEl, cobrDateEl, payload.data.cobr);
    })
    .catch(function() {
        lknBbPixRenderWebhookRow(recUrlEl,  recDateEl,  null);
        lknBbPixRenderWebhookRow(cobrUrlEl, cobrDateEl, null);
    });
}

function lknBbPixRemoveWebhooks(btn) {
    if (btn.disabled) return;

    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Removendo...';

    var msgEl = document.getElementById('lknbbpix-remove-webhooks-msg');
    msgEl.style.color = '';
    msgEl.textContent = '';

    fetch('{$apiUrl}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove-webhooks' })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            msgEl.style.color = 'green';
            msgEl.textContent = 'Webhooks removidos com sucesso.';
        } else {
            msgEl.style.color = 'red';
            msgEl.textContent = 'Erro ao remover: ' + (data.data && data.data.error ? data.data.error : 'Verifique os logs.');
        }
    })
    .catch(function() {
        msgEl.style.color = 'red';
        msgEl.textContent = 'Falha na requisição. Verifique os logs.';
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = originalText;
        lknBbPixLoadWebhooks();
    });
}

document.addEventListener('DOMContentLoaded', lknBbPixLoadWebhooks);
</script>
