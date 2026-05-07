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
                id="lknbbpix-register-webhooks-btn"
                type="button"
                style="padding: 6px 14px; font-size: 0.9em; cursor: pointer; margin-right: 8px;"
                onclick="lknBbPixRegisterWebhooks(this)"
            >
                Inserir Webhooks do Banco
            </button>
            <button
                id="lknbbpix-remove-webhooks-btn"
                type="button"
                style="padding: 6px 14px; font-size: 0.9em; cursor: pointer;"
                onclick="lknBbPixRemoveWebhooks(this)"
            >
                Remover Webhooks do Banco
            </button>
            <span id="lknbbpix-webhooks-action-msg" style="margin-left: 10px; font-size: 0.85em;"></span>
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
function lknBbPixExtractWebhookErrorData(webhookData) {
    var data = webhookData && webhookData.data ? webhookData.data : {};
    var type = String(data.type || '');
    var detail = String(data.detail || '');
    var statusCode = Number(data.statusCode || 0);

    return {
        type: type,
        detail: detail,
        statusCode: statusCode
    };
}

function lknBbPixIsWebhookNotRegistered(errorData) {
    if (!errorData) {
        return false;
    }

    if (
        errorData.type.indexOf('WebhookRecNaoEncontrado') >= 0 ||
        errorData.type.indexOf('WebhookCobRNaoEncontrado') >= 0
    ) {
        return true;
    }

    return (
        errorData.type.indexOf('OperacaoInvalida') >= 0 &&
        (
            errorData.detail.indexOf('Não há webhook cadastrado') >= 0 ||
            errorData.detail.indexOf('Nao ha webhook cadastrado') >= 0
        )
    );
}

function lknBbPixRenderWebhookRow(urlEl, dateEl, webhookData) {
    if (webhookData && webhookData.success && webhookData.data && webhookData.data.webhookUrl) {
        urlEl.innerHTML = '<span style="color:green;">' + webhookData.data.webhookUrl + '</span>';
        dateEl.textContent = webhookData.data.criacao || '';
        return;
    }

    if (webhookData && webhookData.success) {
        urlEl.innerHTML = '<em style="color:#999;">Não registrado</em>';
        dateEl.textContent = '';
        return;
    }

    if (webhookData && !webhookData.success) {
        var errorData = lknBbPixExtractWebhookErrorData(webhookData);

        if (lknBbPixIsWebhookNotRegistered(errorData)) {
            urlEl.innerHTML = '<em style="color:#999;">Não registrado</em>';
            dateEl.textContent = '';
            return;
        }

        if (errorData.statusCode === 503) {
            urlEl.innerHTML = '<em style="color:#b36b00;">Serviço BB indisponível</em>';
            dateEl.textContent = '';
            return;
        }
    }

    {
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

function lknBbPixSetActionMessage(color, text) {
    var msgEl = document.getElementById('lknbbpix-webhooks-action-msg');

    if (!msgEl) {
        return;
    }

    msgEl.style.color = color;
    msgEl.textContent = text;
}

function lknBbPixRegisterWebhooks(btn) {
    if (btn.disabled) return;

    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Inserindo...';

    lknBbPixSetActionMessage('', '');

    fetch('{$apiUrl}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'register-webhooks' })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            lknBbPixSetActionMessage('green', 'Webhooks inseridos com sucesso.');
        } else {
            var warnings = data.data && data.data.warnings ? data.data.warnings.join(' ') : '';
            var baseMessage = data.data && data.data.error ? data.data.error : 'Verifique os logs.';
            lknBbPixSetActionMessage('red', 'Erro ao inserir: ' + (warnings || baseMessage));
        }
    })
    .catch(function() {
        lknBbPixSetActionMessage('red', 'Falha na requisição. Verifique os logs.');
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = originalText;
        lknBbPixLoadWebhooks();
    });
}

function lknBbPixRemoveWebhooks(btn) {
    if (btn.disabled) return;

    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Removendo...';

    lknBbPixSetActionMessage('', '');

    fetch('{$apiUrl}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove-webhooks' })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            lknBbPixSetActionMessage('green', 'Webhooks removidos com sucesso.');
        } else {
            var warnings = data.data && data.data.warnings ? data.data.warnings.join(' ') : '';
            var baseMessage = data.data && data.data.error ? data.data.error : 'Verifique os logs.';
            lknBbPixSetActionMessage('red', 'Erro ao remover: ' + (warnings || baseMessage));
        }
    })
    .catch(function() {
        lknBbPixSetActionMessage('red', 'Falha na requisição. Verifique os logs.');
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = originalText;
        lknBbPixLoadWebhooks();
    });
}

document.addEventListener('DOMContentLoaded', lknBbPixLoadWebhooks);
</script>
