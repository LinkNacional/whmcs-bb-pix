{if $enable_admin_manual_check}
    {include "../modal/index.tpl"}
    <input
        class="lknBbPixInstallUrl"
        type="hidden"
        value="{$whmcsInstallUrl}"
    >

    <button
        type="button"
        class="btn btn-success"
        id="lknbbpix-manual-confirmation-btn"
    >
        Verificar pagamento do Pix
    </button>

    <button
        type="button"
        class="btn btn-warning"
        id="lknbbpix-reemit-pix-btn"
    >
        Reemitir Pix
    </button>

    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/js/utils.js"
        defer
    ></script>

    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/admin_invoices_controls_output/index.js"
        defer
    ></script>

{/if}