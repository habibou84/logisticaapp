// Vérification de numéro en cours, gardée sur l'appareil : la page la reprend si le
// navigateur l'a rechargée pendant que la personne était dans WhatsApp.
const key = (purpose) => `verification:${purpose}`

export function savePending(purpose, value) {
  try { localStorage.setItem(key(purpose), JSON.stringify(value)) } catch { /* stockage indisponible */ }
}

export function readPending(purpose) {
  try {
    const value = JSON.parse(localStorage.getItem(key(purpose)))
    if (value?.token && value?.data?.expires_at && new Date(value.data.expires_at) > new Date()) return value
  } catch { /* valeur illisible */ }
  clearPending(purpose)
  return null
}

export function clearPending(purpose) {
  try { localStorage.removeItem(key(purpose)) } catch { /* stockage indisponible */ }
}
