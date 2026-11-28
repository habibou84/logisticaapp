import axios from 'axios'
import { appLock, nativeApp, siteOrigin } from '../native'
import { currentSpace, getToken, LOGIN_PAGES, setToken } from './session'

const http = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

http.interceptors.request.use((config) => {
  // Application : l'API est celle de l'entreprise choisie sur l'appareil
  if (nativeApp) config.baseURL = `${siteOrigin()}/api/v1`
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Site momentanément indisponible (bascule d'une mise à jour : environ une seconde) : les lectures sont
// relancées seules, après 1 s puis 2 s, pour que personne ne voie d'erreur. Les enregistrements (POST, PUT…)
// ne sont jamais relancés : ils pourraient avoir été pris en compte.
const RETRY_DELAYS = [1000, 2000]
const RETRY_STATUSES = [502, 503, 504]

export function shouldRetry(error) {
  const config = error.config
  if (!config || error.code === 'ERR_CANCELED') return false
  if (!['get', 'head'].includes((config.method || 'get').toLowerCase())) return false
  if ((config.retryCount || 0) >= RETRY_DELAYS.length) return false
  if (error.response) return RETRY_STATUSES.includes(error.response.status)
  // Pas de réponse : serveur injoignable, sauf si l'appareil est hors ligne (inutile d'attendre)
  return typeof navigator === 'undefined' || navigator.onLine !== false
}

function retryWhenUnavailable(instance) {
  instance.interceptors.response.use(undefined, async (error) => {
    if (!shouldRetry(error)) return Promise.reject(error)
    const config = error.config
    const delay = RETRY_DELAYS[config.retryCount || 0]
    config.retryCount = (config.retryCount || 0) + 1
    await new Promise((resolve) => setTimeout(resolve, delay))
    return instance(config)
  })
}

retryWhenUnavailable(http)
// Pages publiques (suivi de colis, report de livraison) : elles utilisent axios directement
retryWhenUnavailable(axios)

// Jeton expiré ou révoqué : on nettoie et on renvoie vers la connexion
http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const isLogin = error.config?.url?.includes('/auth/login')

    if (status === 401 && !isLogin) {
      setToken(null)
      const path = window.location.pathname
      const login = appLock()?.login || LOGIN_PAGES[currentSpace()]
      if (path !== login) {
        window.location.assign(login)
      }
    }

    return Promise.reject(error)
  },
)

/**
 * Extrait un message lisible d'une erreur d'API ({ message, errors? }).
 */
export function apiErrorMessage(error, fallback = 'Une erreur est survenue.') {
  if (error.isAxiosError && !error.response) {
    return 'Pas de connexion. Réessayez quand le réseau revient.'
  }
  const data = error.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return data?.message || fallback
}

export default http
