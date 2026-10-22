// Applications Android et iOS (Capacitor) : le même code que le site, compilé pour une application.
// « livreur » ou « ecommercant » selon l'application, null dans le navigateur (fixé à la compilation).
export const nativeApp = import.meta.env.VITE_NATIVE_APP || null

// Adresse du site auquel l'application parle (fixée à la compilation)
const SITE_URL = (import.meta.env.VITE_APP_URL || 'https://app.logisticadelivery.com').replace(/\/$/, '')

// Chaque application ne s'ouvre qu'aux comptes de son espace
const APPS = {
  livreur: {
    roles: ['courier'],
    login: '/livreur/connexion',
    home: '/livreur',
    paths: ['/livreur', '/mot-de-passe-oublie'],
    other: 'Cette application est réservée aux livreurs. Les e-commerçants utilisent l\'application « Jibiat E-commerçant ».',
  },
  ecommercant: {
    roles: ['merchant_owner', 'merchant_staff'],
    login: '/marchand/connexion',
    home: '/marchand',
    paths: ['/marchand', '/inscription', '/mot-de-passe-oublie', '/suivi'],
    other: 'Cette application est réservée aux e-commerçants. Les livreurs utilisent l\'application « Jibiat Livreur ».',
  },
}

export const nativeConfig = nativeApp ? APPS[nativeApp] : null

// Application installée depuis le navigateur (PWA) : verrouillée sur l'espace d'où elle a été
// installée, comme les applications Android. Repéré à l'ouverture (adresse de départ du manifeste,
// ou fenêtre d'application sur une page de l'espace) et gardé pour la durée de la fenêtre.
const LOCK_KEY = 'app-lock'
function detectInstalledApp() {
  if (nativeApp || typeof window === 'undefined') return null
  try {
    const saved = sessionStorage.getItem(LOCK_KEY)
    if (saved) return saved
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
    const fromManifest = new URLSearchParams(window.location.search).get('app')
    if (!standalone && !fromManifest) return null
    const path = window.location.pathname
    const app = fromManifest === 'livreur' || path.startsWith('/livreur') ? 'livreur'
      : (fromManifest === 'marchand' || path === '/' || path.startsWith('/marchand') ? 'ecommercant' : null)
    if (app) sessionStorage.setItem(LOCK_KEY, app)
    return app
  } catch {
    return null
  }
}
const installedApp = detectInstalledApp()

/** Espace auquel l'application est limitée (Android, ou installée depuis le navigateur) ; null sinon */
export function appLock() {
  return nativeConfig || (installedApp ? APPS[installedApp] : null)
}

/** Adresse du site (application) ou du site ouvert (navigateur) */
export function siteOrigin() {
  return nativeApp ? SITE_URL : window.location.origin
}

/** Lien absolu vers une page ou un fichier du site (« /suivi/… » → « https://…/suivi/… ») */
export function siteUrl(path) {
  if (!path || /^(https?:|data:|blob:)/.test(path)) return path
  return siteOrigin() + (path.startsWith('/') ? path : `/${path}`)
}

/** Chemin autorisé dans cette application (les autres espaces restent sur le site) */
export function allowedInApp(path) {
  const lock = appLock()
  if (!lock) return true
  return path === '/' || lock.paths.some((p) => path === p || path.startsWith(`${p}/`))
}
