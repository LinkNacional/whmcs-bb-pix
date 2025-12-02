/* globals lknBbPixApiRequest $ */

const productsSelect = document.getElementById('products-select')
const btnAddNewDiscount = document.getElementById('btn-add-new-discount')
const discountsTable = document.getElementById('discounts-table')
const btnCloseModal = document.getElementById('btn-close-modal')

const handleProductsSelectChange = evt => {
  btnAddNewDiscount.disabled = evt.target.value === ''
}

const handleRemoveDiscountBtnClick = evt => {
  const confirmPrompt = window.confirm('Deseja remover o desconto para o produto? A remoção terá e feito imediato.')

  if (!confirmPrompt) {
    return
  }

  const rowId = evt.target.dataset.rowId
  const rowElement = discountsTable.querySelector(`tr[data-id="${rowId}"]`)

  const saveBtn = rowElement.lastElementChild.firstElementChild
  const removeBtn = rowElement.lastElementChild.lastElementChild

  saveBtn.disabled = true
  removeBtn.disabled = true

  lknBbPixApiRequest('delete-discount', { productId: rowId })
    .then(res => res.json())
    .then(res => {
      if (res.success) {
        window.alert('Desconto removido.')
        rowElement.remove()
      } else {
        saveBtn.disabled = false
        removeBtn.disabled = false

        window.alert(res.data.reason)
      }
    })
}

const handleSaveDiscountBtnClick = evt => {
  const rowId = evt.target.dataset.rowId
  const rowElement = discountsTable.querySelector(`tr[data-id="${rowId}"]`)

  const percentageInput = rowElement.children[1].firstElementChild
  const percentage = parseFloat(percentageInput.value.replace(/[^0-9.-]/g, '')).toFixed(2)

  if (typeof parseFloat(percentage) !== 'number' || !(percentage >= 0.1 && percentage <= 100)) {
    window.alert('A porcentagem de desconto deve estar entre 0.1 e 100 e conter no máximo duas casas decimais.')

    return
  }

  const confirmPrompt = window.confirm(
    `Realmente deseja definir um desconto de ${percentage}% para este produto?`
  )

  if (!confirmPrompt) {
    return
  }

  const saveBtn = rowElement.lastElementChild.firstElementChild
  const removeBtn = rowElement.lastElementChild.lastElementChild

  saveBtn.disabled = true
  removeBtn.disabled = true

  lknBbPixApiRequest('save-discount', { productId: rowId, percentage })
    .then(res => res.json())
    .then(res => {
      window.alert(res.success ? 'Desconto salvo.' : res.data.reason)
    })
    .finally(() => {
      saveBtn.disabled = false
      removeBtn.disabled = false
    })
}

const handleBtnAddNewDiscountClick = evt => {
  const selectedProductOptionTag = productsSelect.options[productsSelect.selectedIndex]
  const selectedProductId = selectedProductOptionTag.value

  const productAlreadyHasDefinedDiscount = discountsTable.querySelector(`tr[data-id="${selectedProductId}"]`)

  if (productAlreadyHasDefinedDiscount) {
    window.alert('O produto já tem desconto definido.')

    return
  }

  const newDiscountRow = discountsTable.insertRow(1)

  newDiscountRow.setAttribute('data-id', selectedProductId)

  for (let i = 1; i < 6; i++) {
    const newCell = newDiscountRow.insertCell(i - 1)
    newCell.style.verticalAlign = 'middle'

    if (i === 1) {
      const text = document.createTextNode(
        `#${selectedProductOptionTag.value} ${selectedProductOptionTag.dataset.label}`
      )

      newCell.style.fontSize = '0.85em'
      newCell.style.overflowX = 'auto'
      newCell.style.textWrap = 'nowrap'
      newCell.style.maxWidth = '300px'
      newCell.style.width = '300px'

      newCell.appendChild(text)
    } else if (i === 2) {
      const input = document.createElement('input')
      input.type = 'number'
      input.classList.add('form-control', 'input-sm')
      input.min = 0
      input.max = 100
      input.step = 0.01
      input.placeholder = '0 a 100%'

      newCell.appendChild(input)
    } else if (i === 3) {
      const text = document.createTextNode('-')

      newCell.style.fontSize = '0.85em'

      newCell.appendChild(text)
    } else if (i === 4) {
      const text = document.createTextNode('-')

      newCell.style.fontSize = '0.85em'

      newCell.appendChild(text)
    } else {
      const addBtn = document.createElement('button')

      addBtn.style.marginRight = '10px'
      addBtn.classList.add('btn', 'btn-info', 'btn-xs')
      addBtn.textContent = 'Salvar'
      addBtn.dataset.rowId = selectedProductId
      addBtn.addEventListener('click', handleSaveDiscountBtnClick)

      newCell.appendChild(addBtn)

      const removeBtn = document.createElement('button')

      removeBtn.classList.add('btn', 'btn-danger', 'btn-xs')
      removeBtn.textContent = 'Remover'
      removeBtn.dataset.rowId = selectedProductId
      removeBtn.addEventListener('click', handleRemoveDiscountBtnClick)

      newCell.appendChild(removeBtn)
    }
  }

  productsSelect.value = ''
  btnAddNewDiscount.disabled = true
}

const handleRowsDiscountInputType = evt => {
  const rowSaveBtn = evt.target.parentElement.parentElement.children[4].firstElementChild

  rowSaveBtn.disabled = false
}

const handleBtnCloseModalClick = evt => {
  $('#lknbbpix-discounts-modal').modal('hide')
}

function addEventListenersToServerGeneratedRows () {
  const rows = discountsTable.rows
  const tbodyRowsNumber = discountsTable.rows.length - 1

  if (!tbodyRowsNumber > 0) {
    return
  }

  for (let index = 1; index < tbodyRowsNumber + 1; index++) {
    document.querySelector('#mylink > img:first-of-type')
    const row = rows[index]

    row.querySelector('button:first-of-type').addEventListener('click', handleSaveDiscountBtnClick)
    row.querySelector('button:nth-of-type(2)').addEventListener('click', handleRemoveDiscountBtnClick)

    row.children[1].firstElementChild.addEventListener('keyup', handleRowsDiscountInputType)
  }
}

addEventListenersToServerGeneratedRows()

btnCloseModal.addEventListener('click', handleBtnCloseModalClick)

productsSelect.addEventListener('change', handleProductsSelectChange)

btnAddNewDiscount.addEventListener('click', handleBtnAddNewDiscountClick)
