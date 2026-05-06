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
</div>

<script>
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
    });
}
</script>
