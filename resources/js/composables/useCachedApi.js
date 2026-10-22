import http from '../bootstrap/axios'

const PREFIX = 'cache:'

/**
 * GET avec repli hors ligne : la dernière réponse réussie est gardée dans
 * localStorage et renvoyée (avec stale = true) quand le réseau est coupé.
 * Les autres erreurs (403, 404, 500…) sont propagées normalement.
 */
export async function cachedGet(url, config = {}) {
  const key = PREFIX + url + (config.params ? '?' + new URLSearchParams(config.params) : '')
  try {
    const { data } = await http.get(url, config)
    try {
      localStorage.setItem(key, JSON.stringify(data))
    } catch {
      // Stockage plein ou indisponible : on continue sans cache
    }
    return { data, stale: false }
  } catch (error) {
    if (error.response) throw error
    const cached = localStorage.getItem(key)
    if (cached === null) throw error
    return { data: JSON.parse(cached), stale: true }
  }
}

/** Vide le cache (à la déconnexion). */
export function clearApiCache() {
  Object.keys(localStorage)
    .filter((k) => k.startsWith(PREFIX))
    .forEach((k) => localStorage.removeItem(k))
}
