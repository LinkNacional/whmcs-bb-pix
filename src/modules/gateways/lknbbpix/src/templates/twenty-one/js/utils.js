/**
 * @param {String} action
 * @param {Object} data
 *
 * @returns {Promise}
 */
async function lknBbPixApiRequest(action, data = {}) {
  data.action = action
  let apiUrl = document.getElementsByClassName('lknBbPixInstallUrl')

  if (apiUrl && apiUrl[0]) {
    apiUrl = apiUrl[0].value + '/modules/gateways/lknbbpix/api.php'
  } else {
    apiUrl = '/modules/gateways/lknbbpix/api.php'
  }

  const token = document.getElementById('lkn-bb-pix-token')

  if (token) {
    data.token = token.value
  }

  return await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
}
