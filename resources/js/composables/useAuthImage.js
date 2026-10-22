import http from '../bootstrap/axios'

/**
 * Charge une image protégée (API authentifiée) et renvoie une URL locale affichable.
 */
export async function authImage(url) {
  if (!url) return null
  const { data } = await http.get(url.replace(/^\/api\/v1/, ''), { responseType: 'blob' })
  return URL.createObjectURL(data)
}
