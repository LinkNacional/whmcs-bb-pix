/* globals LknBbPixNotification lknBbPixApiRequest $ */

let invoiceStatuscheckerCounter = 1
const invoiceStatusMaxChecks = 5
let invoiceCheckIntervalFuncId

const urlParams = new URLSearchParams(window.location.search)
const invoiceId = parseInt(urlParams.get('id'))
const flowModeInput = document.getElementById('lkn-bb-pix-flow-mode')
const flowMode = flowModeInput ? flowModeInput.value : 'MANUAL_TRADICIONAL'

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

if (copyPixTextBtn) {
  copyPixTextBtn.addEventListener('click', copyQrCodeTextToClipboard)
}

if (btnConfirmation) {
  btnConfirmation.addEventListener('click', manualPaymentCheck)
}

if (flowMode === 'JORNADA4') {
  setupJourney4Flow()
} else if (getPixPaymentCheckCounter() < pixPaymentMaxChecks) {
  setTimeout(() => {
    if (window.$) {
      $('#lknbbpix-manual-confirmation-btn').slideDown()
    }
  }, 10000)
}

function copyQrCodeTextToClipboard () {
  pixTextArea.select()
  pixTextArea.setSelectionRange(0, 99999)

  navigator.clipboard.writeText(pixTextArea.value)
    .then(() => {
      LknBbPixNotification.show('Copiado!', 'Código Pix copiado para área de transferência')
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

if (flowMode !== 'JORNADA4') {
  setTimeout(
    () => {
      invoiceCheckIntervalFuncId = setInterval(
        checkInvoiceStatus,
        18000
      )
    },
    5000
  )
}

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
        if (window.$) {
          $('#lknbbpix-manual-confirmation-btn').slideUp()
        }

        return
      }

      setTimeout(() => {
        btnConfirmation.disabled = false
      }, 15000)
    })
}

function setupJourney4Flow () {
  const loader = document.getElementById('lknbbpix-auto-loader')
  const error = document.getElementById('lknbbpix-auto-error')
  const retryBtn = document.getElementById('lknbbpix-retry-journey4-btn')
  const qrWrapper = document.getElementById('lknbbpix-auto-qr-wrapper')
  const qrImage = document.getElementById('lknbbpix-auto-qr-image')
  const invoiceIdInput = document.getElementById('lknbbpix-invoice-id')

  let inFlight = false

  const handleLoad = () => {
    if (inFlight) {
      return
    }

    const targetInvoiceId = parseInt(invoiceIdInput ? invoiceIdInput.value : invoiceId)

    inFlight = true

    if (loader) {
      loader.style.display = 'block'
      loader.textContent = 'Carregando proposta do Pix Automático...'
    }

    if (error) {
      error.style.display = 'none'
      error.textContent = ''
    }

    if (retryBtn) {
      retryBtn.disabled = true
      retryBtn.style.display = 'none'
    }

    lknBbPixApiRequest('load-journey4-qrcode', { invoiceId: targetInvoiceId })
      .then(res => res.json())
      .then(res => {
        if (!res.success) {
          throw new Error((res.data && res.data.error) || 'Não foi possível carregar o Pix Automático.')
        }

        const qrCodeText = res.data.qrCodeText
        const qrCodeBase64 = res.data.qrCodeBase64

        if (!qrCodeText || !qrCodeBase64) {
          throw new Error('Resposta inválida da jornada 4.')
        }

        if (pixTextArea) {
          pixTextArea.value = qrCodeText
          pixTextArea.style.display = 'block'
        }

        if (copyPixTextBtn) {
          copyPixTextBtn.style.display = 'block'
          copyPixTextBtn.setAttribute('title', qrCodeText)
        }

        if (qrImage) {
          qrImage.src = qrCodeBase64
        }

        if (qrWrapper) {
          qrWrapper.style.display = 'block'
        }

        if (loader) {
          loader.style.display = 'none'
        }
      })
      .catch((err) => {
        if (loader) {
          loader.style.display = 'none'
        }

        if (error) {
          error.style.display = 'block'
          error.textContent = err.message
        }

        if (retryBtn) {
          retryBtn.style.display = 'block'
        }
      })
      .finally(() => {
        inFlight = false

        if (retryBtn) {
          retryBtn.disabled = false
        }
      })
  }

  if (retryBtn) {
    retryBtn.addEventListener('click', handleLoad)
  }

  handleLoad()
}
