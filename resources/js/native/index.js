// Applications Android et iOS (Capacitor) : le même code que le site, compilé pour une application.
// « livreur » ou « ecommercant » selon l'application, null dans le navigateur (fixé à la compilation).
export const nativeApp = import.meta.env.VITE_NATIVE_APP || null

// Adresse de la plateforme, qui retrouve une entreprise à partir de son code
const PLATFORM_URL = (import.meta.env.VITE_PLATFORM_URL || 'https://jibiat.com').replace(/\/$/, '')

const COMPANY_KEY = 'native-company'

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

/** Entreprise choisie sur l'appareil : { code, name, url, logo_url } */
export function nativeCompany() {
  try {
    return JSON.parse(localStorage.getItem(COMPANY_KEY) || 'null')
  } catch {
    return null
  }
}

export function setNativeCompany(company) {
  localStorage.setItem(COMPANY_KEY, JSON.stringify(company))
  // Nom et logo de l'entreprise précédente : rechargés depuis la nouvelle
  localStorage.removeItem('branding')
}

export function clearNativeCompany() {
  localStorage.removeItem(COMPANY_KEY)
  localStorage.removeItem('branding')
}

/** Adresse du site de l'entreprise (application) ou du site ouvert (navigateur) */
export function siteOrigin() {
  return nativeApp ? (nativeCompany()?.url || PLATFORM_URL) : window.location.origin
}

/** Lien absolu vers une page ou un fichier du site (« /suivi/… » → « https://…/suivi/… ») */
export function siteUrl(path) {
  if (!path || /^(https?:|data:|blob:)/.test(path)) return path
  return siteOrigin() + (path.startsWith('/') ? path : `/${path}`)
}

/**
 * Retrouve l'entreprise à partir de son code (son adresse sur la plateforme, ex. « rapide-express »).
 */
export async function findCompany(code) {
  const clean = String(code || '').trim().toLowerCase().replace(/^https?:\/\//, '').split('.')[0]
  const response = await fetch(`${PLATFORM_URL}/api/public/apps/companies/${encodeURIComponent(clean)}`, {
    headers: { Accept: 'application/json' },
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(body.message || 'Code introuvable. Vérifiez-le auprès de votre entreprise de livraison.')
  }
  return body.data
}

/** Chemin autorisé dans cette application (les autres espaces restent sur le site) */
export function allowedInApp(path) {
  const lock = appLock()
  if (!lock) return true
  return path === '/' || path === '/entreprise' || lock.paths.some((p) => path === p || path.startsWith(`${p}/`))
}
