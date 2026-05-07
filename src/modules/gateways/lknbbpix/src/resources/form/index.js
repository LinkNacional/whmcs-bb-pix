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
  const economicSummary = document.getElementById('lknbbpix-economic-summary')
  const invoiceValueLabel = document.getElementById('lknbbpix-invoice-value-label')
  const pixValueLabel = document.getElementById('lknbbpix-pix-value-label')
  const discountBadge = document.getElementById('lknbbpix-discount-badge')
  const taxLabel = document.getElementById('lknbbpix-tax-label')

  let inFlight = false

  const formatMoney = (value) => {
    return Number(value || 0).toFixed(2).replace('.', ',')
  }

  const updateEconomicSummary = (data) => {
    if (!economicSummary || !invoiceValueLabel || !pixValueLabel) {
      return
    }

    const invoiceValue = Number(data.invoiceValue || 0)
    const pixValue = Number(data.pixValue || 0)

    // Keep initial Smarty values when API returns invalid economic data.
    if (invoiceValue <= 0) {
      return
    }

    const hasDifference = Number(invoiceValue.toFixed(2)) !== Number(pixValue.toFixed(2))

    invoiceValueLabel.textContent = formatMoney(invoiceValue)
    pixValueLabel.textContent = formatMoney(pixValue)

    economicSummary.style.display = hasDifference ? 'block' : 'none'

    if (discountBadge) {
      if (data.discountPercentage) {
        discountBadge.style.display = 'inline-block'
        discountBadge.textContent = `${data.discountPercentage}% off`
      } else {
        discountBadge.style.display = 'none'
      }
    }

    if (taxLabel) {
      if (data.taxAmount) {
        taxLabel.style.display = 'inline'
        taxLabel.textContent = `+ R$ ${data.taxAmount} de juros`
      } else {
        taxLabel.style.display = 'none'
      }
    }
  }

  const renderJourney4Error = (message, profileUrl) => {
    if (!error) {
      return
    }

    error.style.display = 'block'
    error.textContent = ''

    const messageNode = document.createElement('span')
    messageNode.textContent = message
    error.appendChild(messageNode)

    if (profileUrl) {
      const actionWrapper = document.createElement('div')
      actionWrapper.style.marginTop = '10px'

      const profileLink = document.createElement('a')
      profileLink.href = profileUrl
      profileLink.className = 'btn btn-warning btn-sm'
      profileLink.textContent = 'Atualizar dados do perfil'

      actionWrapper.appendChild(profileLink)
      error.appendChild(actionWrapper)
    }
  }

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
          const apiError = new Error((res.data && res.data.error) || 'Não foi possível carregar o Pix Automático.')
          apiError.profileUrl = res.data && res.data.profileUrl
          throw apiError
        }

        const qrCodeText = res.data.qrCodeText
        const qrCodeBase64 = res.data.qrCodeBase64

        if (!qrCodeText || !qrCodeBase64) {
          throw new Error('Resposta inválida da jornada 4.')
        }

        updateEconomicSummary(res.data)

        if (pixTextArea) {
          pixTextArea.value = qrCodeText
          /* pixTextArea.style.display = 'block' */ // Keep the textarea hidden as per new design, but still populate it with the QR code text for copy functionality.
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

        renderJourney4Error(err.message, err.profileUrl)

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
