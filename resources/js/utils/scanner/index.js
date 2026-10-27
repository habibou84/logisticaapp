/**
 * Scanner de documents, côté écran : envoie les pixels au worker (OpenCV.js) et reçoit les coins trouvés ou la
 * feuille nettoyée. Le worker est créé au premier usage et gardé pour les photos suivantes.
 */
let worker = null
let seq = 0
const calls = new Map()

function fail(error) {
  calls.forEach(({ reject }) => reject(error))
  calls.clear()
  worker?.terminate()
  worker = null
}

function call(message, transfer = []) {
  if (!worker) {
    worker = new Worker(new URL('./scanner.worker.js', import.meta.url))
    worker.onmessage = ({ data }) => {
      const pending = calls.get(data.id)
      if (!pending) return
      calls.delete(data.id)
      if (data.ok) pending.resolve(data)
      else pending.reject(new Error(data.error))
    }
    worker.onerror = (e) => fail(new Error(e.message || 'Scanner indisponible.'))
  }
  const id = ++seq
  return new Promise((resolve, reject) => {
    // Premier chargement en réseau lent : jusqu'à deux minutes
    const timer = setTimeout(() => {
      if (calls.delete(id)) reject(new Error('Le scanner met trop de temps à répondre.'))
    }, 120000)
    calls.set(id, {
      resolve: (v) => (clearTimeout(timer), resolve(v)),
      reject: (e) => (clearTimeout(timer), reject(e)),
    })
    worker.postMessage({ id, ...message }, transfer)
  })
}

/** Charge OpenCV.js à l'avance (ouverture de la feuille de livraison) */
export function warmUpScanner() {
  return call({ type: 'load' }).then(() => true, () => false)
}

/** Coins de la feuille en proportions (haut-gauche, haut-droite, bas-droite, bas-gauche), ou null */
export async function detectCorners(image) {
  return (await call({ type: 'detect', image })).corners
}

/**
 * Feuille redressée et nettoyée.
 * @returns {Promise<{ image: { data, width, height }, quality: { blur, brightness, glare, warnings: string[] } }>}
 */
export async function renderDocument(image, corners, mode, maxSide) {
  const { image: out, quality } = await call({ type: 'render', image, corners, mode, maxSide })
  return { image: out, quality }
}

/** Avertissements qui empêchent l'envoi quand le contrôle de qualité est bloquant */
export const BLOCKING = ['blur', 'dark']

export const WARNING_LABELS = {
  blur: 'La photo est floue : tenez le téléphone immobile et reprenez-la.',
  dark: 'La photo est trop sombre : rapprochez-vous de la lumière.',
  glare: 'Un reflet cache une partie du document : inclinez un peu le téléphone.',
  small: 'Le document est pris de trop loin : rapprochez-vous pour qu\'il remplisse l\'écran.',
  no_document: 'Document non trouvé : placez les quatre coins sur ceux de la feuille.',
}
