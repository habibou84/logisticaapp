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
