<div
    class="modal fade"
    id="lknbbpix-discounts-modal"
    tabindex="-1"
    role="dialog"
    aria-labelledby="myModalLabel"
    data-backdrop="static"
    data-keyboard="false"
>
    <div
        class="modal-dialog modal-lg"
        role="document"
    >
        <div class="modal-content">
            <div class="modal-header">
                <button
                    type="button"
                    class="close"
                    data-dismiss="modal"
                    aria-label="Close"
                ><span aria-hidden="true">×</span></button>
                <h4
                    class="modal-title"
                    id="myModalLabel"
                >Definições de desconto por produto</h4>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div
                        class="col-xs-12 row"
                        style="display: flex; align-items: end; margin-bottom: 40px;"
                    >
                        <div class="col-xs-7 form-group">
                            <label>Adicionar produto para definir desconto</label>
                            <select
                                id="products-select"
                                class="form-control"
                            >
                                <option value="">Selecionar produto</option>

                                {foreach from=$products_labels_by_id_list item=$product_label key=$product_id}
                                    <option
                                        value="{$product_id}"
                                        data-label="{$product_label}"
                                    >#{$product_id} {$product_label}</option>
                                {/foreach}
                            </select>
                        </div>
                        <div class="col form-group">
                            <label></label>
                            <button
                                id="btn-add-new-discount"
                                class="btn btn-default btn-sm"
                                disabled
                            >
                                Adicionar
                            </button>
                        </div>
                    </div>

                    <div class="col-xs-12">
                        <h4>Descontos atuais</h4>
                    </div>

                    <div
                        class="col-xs-12 row table-responsive"
                        style="padding-left: 30px; padding-right: 0px;"
                    >
                        <div class="panel panel-default">
                            <table
                                id="discounts-table"
                                class="table table-hover"
                            >
                                <thead style="font-size: 1em;">
                                    <tr>
                                        <th>Produto</th>
                                        <th>
                                            <span
                                                data-toggle="tooltip"
                                                data-placement="left"
                                                title="Digite apenas números e ponto."
                                            >
                                                % desconto <i class="fas fa-info-circle fa-sm"></i>
                                            </span>
                                        </th>
                                        <th>Definido em</th>
                                        <th>Alterado em</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach from=$products_discounts item=$discount}
                                        <tr data-id={$discount->product_id}>
                                            <td
                                                style="font-size: 0.85em; vertical-align: middle; width: 300px; max-width: 300px; overflow-x: auto; text-wrap: nowrap;">
                                                #{$discount->product_id}
                                                {$products_labels_by_id_list[$discount->product_id]} hospedam de site cpanel
                                                gratis
                                            </td>
                                            <td style="font-size: 0.85em; vertical-align: middle;">
                                                <input
                                                    type="number"
                                                    class="form-control input-sm"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    placeholder="0.1 a 100"
                                                    value="{$discount->percentage}"
                                                >
                                            </td>
                                            <td style="font-size: 0.85em; vertical-align: middle;">
                                                {$discount->created_at|date_format:"%d/%m/%Y, %Hh%M"}
                                            </td>
                                            <td style="font-size: 0.85em; vertical-align: middle;">
                                                {$discount->updated_at|date_format:"%d/%m/%Y, %Hh%M"}
                                            </td>
                                            <td style="vertical-align: middle;">
                                                <button
                                                    class="btn btn-info btn-xs"
                                                    style="margin-right: 10px;"
                                                    data-row-id="{$discount->product_id}"
                                                >
                                                    Salvar
                                                </button>
                                                <button
                                                    class="btn btn-danger btn-xs"
                                                    data-row-id="{$discount->product_id}"
                                                >

                                                    Remover
                                                </button>
                                            </td>
                                        </tr>
                                    {/foreach}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button
                    id="btn-close-modal"
                    type="button"
                    class="btn btn-primary btn-sm"
                >
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>
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
    src="{$whmcsInstallUrl}/modules/gateways/lknbbpix/src/resources/discount_per_product/index.js"
    defer
></script>