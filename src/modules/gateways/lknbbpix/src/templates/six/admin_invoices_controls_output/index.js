/* globals LknBbPixModal */

const btnConfirmation = document.getElementById('lknbbpix-manual-confirmation-btn')

const urlParams = new URLSearchParams(window.location.search)

const invoiceId = urlParams.get('id')

btnConfirmation.addEventListener('click', () => {
  btnConfirmation.disabled = true

  lknBbPixApiRequest('manual-payment-confirmation', { invoiceId })
    .then(res => res.json())
    .then(res => {
      const code = res.data.code

      switch (code) {
        case 'payment-confirmed':
          LknBbPixModal
            .setTitle('Pagamento confirmado')
            .setSimpleBody('Atualizando a página...')
            .show()

          setTimeout(() => { window.location.reload() }, 1200)

          break
        case 'pix-is-concluded-and-not-paid':
          LknBbPixModal
            .setTitle('Pix expirado')
            .setSimpleBody('Pix não está mais válido para pagamento.')
            .show()

          break
        case 'pix-still-active':
          LknBbPixModal
            .setTitle('Pix ativo')
            .setSimpleBody('Pix ainda aceita pagamento.')
            .show()

          break

        case 'pix-is-active-but-expired':
          LknBbPixModal
            .setTitle('Pix expirado')
            .setSimpleBody('Pix não está mais válido para pagamento.')
            .show()

          break
        case 'invoice-status-is-not-unpaid':
          LknBbPixModal
            .setTitle('Fatura não está com status "Não Pago"')
            .setSimpleBody('O status da fatura deve estar como "Não Pago" para pode inserir a transação de pagamento do Pix.')
            .show()

          break
        case 'invoice-has-wrong-payment-method':
          LknBbPixModal
            .setTitle('A fatura tem gateway selecionado inválido')
            .setSimpleBody('O método de pagamento (gateway) da fatura deve ser Pix - Banco do Brasil.')
            .show()

          break
        case 'pix-removed-by-issuer':
          LknBbPixModal
            .setTitle('Pix removido pelo recebedor do pagamento')
            .setSimpleBody('A cobraça Pix foi cancelada (removida) pelo recebedor.')
            .show()

          break
        case 'pix-removed-by-psp':
          LknBbPixModal
            .setTitle('Pix removido pelo Banco')
            .setSimpleBody('A cobrança Pix, por conta de algum critério, foi cancelada (removida) pelo Banco.')
            .show()

          break
        default:
          LknBbPixModal
            .setTitle('Não foi possível verificar')
            .setSimpleBody('Acesse os logs do gateway para mais informações.')
            .show()

          break
      }
    })
    .catch(() => {
      LknBbPixModal
        .setTitle('Não foi possível verificar')
        .setSimpleBody('Acesse os logs do gateway para mais informações.')
        .show()
    })
    .finally(() => {
      btnConfirmation.disabled = false
    })
})

const btnReemitPix = document.getElementById('lknbbpix-reemit-pix-btn');
if (btnReemitPix) {
  btnReemitPix.addEventListener('click', () => {
    btnReemitPix.disabled = true;
    lknBbPixApiRequest('reemit-pix', { invoiceId })
      .then(res => res.json())
      .then(res => {
        if (res.success) {
          LknBbPixModal
            .setTitle('Novo Pix gerado')
            .setSimpleBody('Pix reemitido com sucesso!<br>Copia e Cola: ' + res.data.pixCopiaECola)
            .show();
        } else {
          LknBbPixModal
            .setTitle('Erro ao reemitir Pix')
            .setSimpleBody(res.data.msg || 'Não foi possível reemitir o Pix.')
            .show();
        }
      })
      .catch(() => {
        LknBbPixModal
          .setTitle('Erro ao reemitir Pix')
          .setSimpleBody('Acesse os logs do gateway para mais informações.')
          .show();
      })
      .finally(() => {
        btnReemitPix.disabled = false;
      });
  });
}
