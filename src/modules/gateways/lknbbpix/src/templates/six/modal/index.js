class LknBbPixModal {
  static modal = document.getElementById('lkn-bb-pix-modal-root')
  static secondaryBtn = this.modal.querySelector('.btn-secondary')
  static title = this.modal.querySelector('.modal-title')
  static body = this.modal.querySelector('.modal-body')

  static reset () {
    this.secondaryBtn.innerHTML = ''

    return this
  }

  static show () {
    this.reset()

    this.secondaryBtn.style.display = this.secondaryBtn.innerHTML === '' ? 'none' : 'inline-block'

    $('#lkn-bb-pix-modal-root').modal('show')
  }

  static withErrors (title, errors) {
    this.setTitle(title)
    this.setListBody(errors)

    return this
  }

  static setSecondaryBtn (label, href) {
    this.secondaryBtn.innerHTML = label
    this.secondaryBtn.href = href

    return this
  }

  static setSecondaryBtnWithCallback (label, callback) {
    this.secondaryBtn.innerHTML = label
    this.secondaryBtn.addEventListener('click', event => {
      callback()
    })

    return this
  }

  static setTitle (title) {
    this.title.innerHTML = title

    return this
  }

  static setSimpleBody (text) {
    this.body.innerHTML = '<p>' + text + '</p>'

    return this
  }

  static setListBody (list) {
    this.body.innerHTML = '<ul>'

    list.forEach(item => {
      this.body.innerHTML += '<li>' + item + '</li>'
    })

    this.body.innerHTML += '</ul>'

    return this
  }
}
