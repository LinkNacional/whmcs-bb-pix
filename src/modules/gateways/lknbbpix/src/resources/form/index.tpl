{if $pixFlow === 'COBR_AUTOMATICO'}
    <div class="lkn-pix-bb-feedback alert alert-info" role="alert">
        {$autoPixMessage|default:'Esta fatura será cobrada automaticamente no vencimento via Pix Automático.'}
    </div>

{elseif isset($errorMsg)}
    <div
        class="lkn-pix-bb-feedback alert alert-info"
        role="alert"
    >
        <div>{$errorMsg}</div>
        {if isset($errorProfileUrl) && $errorProfileUrl}
            <div style="margin-top: 10px;">
                <a href="{$errorProfileUrl}" class="btn btn-warning btn-sm">Atualizar dados do perfil</a>
            </div>
        {/if}
    </div>

{elseif $pixFlow === 'JORNADA4'}
    {include "../notification/index.tpl"}

    <div class="container" id="lknbbpix-auto-flow">
        <div class="row">
            <div class="col-12" style="margin-top: 15px; margin-bottom: 15px;">
                <div id="lknbbpix-auto-loader" class="alert alert-info" role="alert">
                    Carregando proposta do Pix Automático...
                </div>
                <div id="lknbbpix-auto-error" class="alert alert-danger" role="alert" style="display: none;"></div>
            </div>

            <div class="col-12" id="lknbbpix-auto-qr-wrapper" style="display: none;">
                <img
                    id="lknbbpix-auto-qr-image"
                    src=""
                    alt="QR Code Pix Automático"
                    width="100%"
                    style="background-color: white;"
                />
            </div>

            <div class="col-12" style="margin-top: 12px;">
                <textarea
                    id="qr-code-text"
                    style="
                    display: none;
                    margin: 0px;
                    font-size: 0.75em;
                    color: #bebebe;
                    background-color: #eee;
                    border: none;
                    width: 100%;
                    border-radius: 3px;
                    height: 80px;
                    overflow: hidden;
                    resize: none;
                    "
                    disabled
                ></textarea>
            </div>

            <div class="col-12" style="max-width: 320px; margin: 0 auto 0;">
                <a
                    id="btn-copy-qr-code-text"
                    class="btn btn-xs btn-link btn-block"
                    style="color: #7777779c; margin: 0 auto 0; display: none;"
                    type="button"
                    role="button"
                    tabindex="0"
                >
                    <i class="fas fa-hand-pointer"></i>
                    Clique aqui para copiar o código do Pix
                </a>

                <button
                    id="lknbbpix-retry-journey4-btn"
                    class="btn btn-warning btn-sm btn-block"
                    type="button"
                    style="display: none; margin-top: 10px;"
                >
                    Tentar novamente
                </button>
            </div>
        </div>
    </div>

    <input
        type="hidden"
        id="lkn-bb-pix-token"
        value="{$csrfToken}"
    >

    <input type="hidden" id="lkn-bb-pix-flow-mode" value="JORNADA4">
    <input type="hidden" id="lknbbpix-invoice-id" value="{$invoiceId}">

    <script type="text/javascript">
        localStorage.setItem('pixPaymentMaxChecks', {$max_client_manual_checks|default:5})
    </script>
    <input
        class="lknBbPixInstallUrl"
        type="hidden"
        value="{$whmcsInstallUrl}"
    >
    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/js/utils.js"
        defer
    ></script>

    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/form/index.js"
        defer
    ></script>

{else}
    {include "../notification/index.tpl"}

    <textarea
        id="qr-code-text"
        style="
        display: none;
        margin: 0px;
        font-size: 0.75em;
        color: #bebebe;
        background-color: #eee;
        border: none;
        width: 100%;
        border-radius: 3px;
        height: 80px;
        overflow: hidden;
        resize: none;
        height: 80px;
    "
        disabled
    >{$qrCodeText}</textarea>

    <div class="container">
        <div class="row">

            {if $invoiceValue|string_format:"%.2f" !== $pixValue|string_format:"%.2f"}
                <div
                    class="col-12"
                    style="margin-top: 15px;"
                >
                    <div>De <s>R$ {$invoiceValue|string_format:"%.2f"}</s></div>
                    <div style="color: #91960F; font-weight: 500; font-size: 1.2em;">
                        por R$ {$pixValue|string_format:"%.2f"}
                        {if $discountPercentage}
                            <span class="badge badge-danger">{$discountPercentage}% off</span>
                        {/if}
                    </div>
                </div>
            {/if}

            <div class="col-12">
                <img
                    src="{$qrCodeBase64}"
                    alt="Red dot"
                    width="100%"
                    style="background-color: white;"
                />
            </div>

            <div
                class="col-12"
                style="max-width: 300px; margin: 0 auto 0;"
            >
                <div class="row">
                    <div
                        class="col-12"
                        style="padding-top: 5px;"
                    >
                        <a
                            id="btn-copy-qr-code-text"
                            class="btn btn-xs btn-link btn-block"
                            style="color: #7777779c; margin: 0 auto 0;"
                            type="button"
                            role="button"
                            tabindex="0"
                            data-toggle="tooltip"
                            data-placement="bottom"
                            title="{$qrCodeText}"
                        >
                            <i class="fas fa-hand-pointer"></i>
                            Clique aqui para copiar o código do Pix
                        </a>
                    </div>

                    {if $enable_share_pix_btn}
                        <div
                            class="col-12 row"
                            style="max-width: 300px; margin: 0 auto 0; padding-bottom: 5px; margin-top: 10px;"
                        >
                            <div class="col-12 text-center">
                                <div class="dropdown dropright">
                                    <button
                                        class="btn btn-light btn-xs dropdown-toggle"
                                        type="button"
                                        id="dropdownMenuButton"
                                        data-toggle="dropdown"
                                        aria-haspopup="true"
                                        aria-expanded="false"
                                    >
                                        <i class="fas fa-share"></i> Compartilhar Pix
                                    </button>
                                    <div
                                        class="dropdown-menu"
                                        aria-labelledby="dropdownMenuButton"
                                    >
                                        <a
                                            class="dropdown-item"
                                            href="https://wa.me/?text={$qrCodeText}"
                                            target="_blank"
                                        >
                                            <small><i class="fab fa-whatsapp"></i> WhatsApp</small>
                                        </a>
                                        <a
                                            class="dropdown-item"
                                            href="https://twitter.com/intent/tweet?text={$qrCodeText}"
                                            target="_blank"
                                        >
                                            <small><i class="fab fa-twitter"></i> Twitter</small>
                                        </a>
                                        <a
                                            class="dropdown-item"
                                            href="mailto:?body={$qrCodeText}"
                                            target="_blank"
                                        >
                                            <small><i class="far fa-at"></i> E-mail</small>
                                        </a>
                                        {* <a
                                            class="dropdown-item"
                                            href="https://www.facebook.com/sharer/sharer.php?u={$qrCodeText}"
                                            target="_blank"
                                        >
                                            <small><i class="fab fa-facebook"></i> Facebook</small>
                                        </a> *}
                                    </div>
                                </div>
                            </div>
                        </div>
                    {/if}

                    {if $enable_client_manual_check}
                        <div class="col-12">
                            <button
                                id="lknbbpix-manual-confirmation-btn"
                                class="btn btn-success btn-sm btn-block"
                                type="button"
                                style="margin-top: 30px; display: none;"
                            >
                                <i class="fas fa-check-circle fa-xs"></i> confirmar pagamento
                            </button>
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    </div>

    <input
        type="hidden"
        id="lkn-bb-pix-token"
        value="{$csrfToken}"
    >

    <input type="hidden" id="lkn-bb-pix-flow-mode" value="MANUAL_TRADICIONAL">

    <script type="text/javascript">
        localStorage.setItem('pixPaymentMaxChecks', {$max_client_manual_checks})
    </script>
    <input
        class="lknBbPixInstallUrl"
        type="hidden"
        value="{$whmcsInstallUrl}"
    >
    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/js/utils.js"
        defer
    ></script>

    <script
        src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/form/index.js"
        defer
    ></script>
{/if}