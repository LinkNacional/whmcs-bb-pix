<div style="display: flex; flex-wrap: wrap; align-items: flex-start;">
    <form style="visibility: hidden;"></form> <!-- Solves the WHMCS bug of hiding the form tag below. -->

    <div class="row">
        <div
            class="col-md-12"
            style="margin-bottom: 15px;"
        >
            <div class="row">
                <div
                    class="col-md-12"
                    style="margin-bottom: 8px;"
                >
                    <p><strong>Certificado privado</strong></p>
                </div>

                <div
                    class="col-md-12"
                    style="display: flex;"
                >
                    <input
                        id="private-cert-input"
                        class="form-control"
                        style="max-width: 250px; min-height: 34px; margin-right: 5px;"
                        name="private-cert-input"
                        type="file"
                    >

                    <button
                        id="send-private-cert-btn"
                        class="btn btn-default"
                        type="button"
                        name="send-private-cert-btn"
                    >Enviar</button>
                </div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="row">
                <div
                    class="col-md-12"
                    style="margin-bottom: 8px;"
                >
                    <p><strong>Certificado público</strong></p>
                </div>

                <div
                    class="col-md-12"
                    style="display: flex;"
                >
                    <input
                        id="public-cert-input"
                        class="form-control"
                        style="max-width: 250px; min-height: 34px; margin-right: 5px;"
                        name="public-cert-input"
                        type="file"
                    >

                    <button
                        id="send-public-cert-btn"
                        class="btn btn-default"
                        type="button"
                        name="send-public-cert-btn"
                        value="send-json"
                    >Enviar</button>
                </div>
            </div>
        </div>

        <div
            class="col-md-12"
            style="margin-top: 15px;"
        >
            <p>
                O certificado privado deve estar desbloqueado (sem passphrase).<br>
                <a href="https://apoio.developers.bb.com.br/referency/post/5f80a25fd9493f0012973463">Mais
                    informações</a>
            </p>
            <p style="color: #910000;">
                Após enviar, acesse a pasta do gateway <code>/modules/gateways/lknbbpix/certs/</code> e certifique-se
                que os arquivos
                <code>private.key</code> e
                <code>public.key</code> estão com a permissão <code>400</code>.
                <br>
                Caso contrário, os certificados estarão expostos à internet.
            </p>
        </div>
    </div>
</div>
<script type="text/javascript">
    // const downloadJsonBtn = document.getElementById('download-json-btn')
    const inputPrivateCert = document.getElementById('private-cert-input')
    const btnSendPrivateCert = document.getElementById('send-private-cert-btn')
    const inputPublicCert = document.getElementById('public-cert-input')
    const btnSendPublicCert = document.getElementById('send-public-cert-btn')

    function sendFile(file, action) {
        const data = new FormData()
        data.append('cert', file)
        data.append('action', action)

            fetch('{$api_url}', { method: 'POST', body: data })
            .then(res => res.json())
            .then(res => {
                window.alert(res.msg)

                if (res.success) {
                    location.reload()
                }
            })
            .catch(res => {
                window.alert('Ocorreu um erro na sua conexão com o gateway. O certificado não foi atualizado.')
            })
    }

    btnSendPrivateCert.addEventListener('click', () => {
        sendFile(inputPrivateCert.files[0], 'update-private-cert')
    })

    btnSendPublicCert.addEventListener('click', () => {
        sendFile(inputPublicCert.files[0], 'update-public-cert')
    })
</script>
