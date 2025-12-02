{if isset($errorMsg)}
    <div
        class="lkn-pix-bb-feedback alert alert-info"
        role="alert"
    >{$errorMsg}</div>
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
                    <div class="col-12" style="padding-top: 5px;">
                        <button
                            id="btn-copy-qr-code-text"
                            class="btn btn--primary btn--sm btn--block"
                            type="button"
                            data-toggle="tooltip"
                            data-placement="bottom"
                            title="{$qrCodeText}"
                        >
                            <i class="ls ls-copy" aria-hidden="true"></i>
                            Copiar código Pix
                        </button>
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