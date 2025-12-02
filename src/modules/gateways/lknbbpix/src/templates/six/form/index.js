/* globals LknBbPixNotification lknBbPixApiRequest $ */

let invoiceStatuscheckerCounter = 1
const invoiceStatusMaxChecks = 5
let invoiceCheckIntervalFuncId

const urlParams = new URLSearchParams(window.location.search)
const invoiceId = parseInt(urlParams.get('id'))

const pixPaymentMaxChecks = localStorage.getItem('pixPaymentMaxChecks')

if (!getPixPaymentCheckCounter() || invoiceId !== getPixPaymentCheckerCounterInvoiceId()) {
  setPixPaymentCheckCounter(1)
}

function getPixPaymentCheckCounter () {
  return parseInt(localStorage.getItem('pixPaymentCheckerCounter'))
}

function getPixPaymentCheckerCounterInvoiceId () {
  return parseInt(localStorage.getItem('pixPaymentCheckerCounterInvoiceId'))
}

function setPixPaymentCheckCounter (count) {
  localStorage.setItem('pixPaymentCheckerCounter', count)
  localStorage.setItem('pixPaymentCheckerCounterInvoiceId', invoiceId)
}

const pixTextArea = document.getElementById('qr-code-text')
const copyPixTextBtn = document.getElementById('btn-copy-qr-code-text')
const btnConfirmation = document.getElementById('lknbbpix-manual-confirmation-btn')

copyPixTextBtn.addEventListener('click', copyQrCodeTextToClipboard)

if (btnConfirmation) {
  btnConfirmation.addEventListener('click', manualPaymentCheck)
}

if (getPixPaymentCheckCounter() < pixPaymentMaxChecks) {
  setTimeout(() => {
    $('#lknbbpix-manual-confirmation-btn').slideDown()
  }, 10000)
}

function copyQrCodeTextToClipboard () {
  pixTextArea.select()
  pixTextArea.setSelectionRange(0, 99999)

  navigator.clipboard.writeText(pixTextArea.value)
    .then(() => {
      LknBbPixNotification.show('Copiado!', 'Código Pix copiado para área de transferência')
      // Exibe tooltip no botão
      const btn = document.getElementById('btn-copy-qr-code-text');
      if (btn) {
        // Salva o título original
        const originalTitle = btn.getAttribute('title');
        btn.setAttribute('data-original-title', 'Copiado com sucesso');
        if (window.jQuery && window.jQuery.fn.tooltip) {
          window.jQuery(btn).tooltip('show');
        }
        setTimeout(() => {
          btn.setAttribute('data-original-title', originalTitle);
          if (window.jQuery && window.jQuery.fn.tooltip) {
            window.jQuery(btn).tooltip('hide');
          }
        }, 1500);
      }
    })
}

function checkInvoiceStatus () {
  if (invoiceStatuscheckerCounter > invoiceStatusMaxChecks) {
    clearInterval(invoiceCheckIntervalFuncId)

    return
  }

  lknBbPixApiRequest('check-invoice-status', { invoiceId })
    .then(res => res.json())
    .then((res) => {
      if (res.data.isInvoicePaid) {
        clearInterval(invoiceCheckIntervalFuncId)

        window.location.reload()
      }
    })
    .finally(() => {
      invoiceStatuscheckerCounter++
    })
}

setTimeout(
  () => {
    invoiceCheckIntervalFuncId = setInterval(
      checkInvoiceStatus,
      18000
    )
  },
  5000
)

function manualPaymentCheck () {
  btnConfirmation.disabled = true

  lknBbPixApiRequest('manual-payment-confirmation', { invoiceId })
    .then(res => res.json())
    .then(res => {
      const code = res.data.code

      if (code === 'payment-confirmed' || code === 'invoice-status-is-not-unpaid') {
        localStorage.removeItem('pixPaymentCheckerCounter')
        window.location.reload()
      } else if (code === 'pix-still-active') {
        LknBbPixNotification.show('Nenhum pagamento identificado', 'QR Code ainda está disponível para pagamento.')
      } else {
        LknBbPixNotification.show('O pagamento não foi identificado', 'Houve uma falha e não foi possível confirmar o pagamento.')
      }
    })
    .catch(() => {
      LknBbPixNotification.show('O pagamento não foi identificado', 'Houve uma falha e não foi possível confirmar o pagamento.')
    })
    .finally(() => {
      const count = getPixPaymentCheckCounter() + 1

      setPixPaymentCheckCounter(count)

      if (count >= pixPaymentMaxChecks) {
        $('#lknbbpix-manual-confirmation-btn').slideUp()

        return
      }

      setTimeout(() => {
        btnConfirmation.disabled = false
      }, 15000)
    })
}
