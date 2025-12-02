class LknBbPixNotification {
  static toast = document.getElementById('lkn-bb-pix-toast')
  static title = this.toast.getElementsByTagName('strong')[0]
  static body = this.toast.querySelector('.toast-body')

  /**
   * @param {String} body
   */
  static setTitle (title) {
    this.title.innerText = title
  }

  /**
   * @param {String} body
   */
  static setBody (body) {
    this.body.innerText = body
  }

  /**
   * @param {String} title
   * @param {String} body
   */
  static show (title, body) {
    this.setTitle(title)
    this.setBody(body)

    $('#lkn-bb-pix-toast').toast('show')
    const mobileCss = 'position: fixed;top: 25px;left: 50%;transform: translateX(-50%);width: 100%;'
    const desktopCss = 'position: fixed; top: 25px; right: 25px;'

    const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
    const css = isMobileDevice ? mobileCss : desktopCss

    this.toast.parentElement.style = css + ' background-color: white !important; z-index: 999999 !important;'
  }
}
