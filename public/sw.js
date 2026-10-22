/*
 * Service worker des applications e-commerçant et livreur.
 * - Pages : réseau d'abord, sinon la dernière version de l'application (hors ligne).
 * - Fichiers compilés (/build, noms avec empreinte) : cache d'abord.
 * - Icônes : cache puis mise à jour en arrière-plan.
 * - Manifestes : réseau d'abord (une version gardée en cache ferait installer l'application
 *   avec d'anciens réglages, ex. une portée qui recouvre l'autre application), cache hors ligne.
 * - API, temps réel : jamais mis en cache (données toujours fraîches).
 */
// Changer la version vide les anciens caches (v2 : icônes déplacées dans /app-icons, /icons étant réservé par Apache ;
// v3 : seule l'application est gardée pour le hors ligne, plus la documentation de l'API ;
// v4 : manifestes en réseau d'abord, les anciens manifestes gardés en cache sont effacés ;
// v5 : efface les pages HTML qui ont pu être gardées à la place de fichiers compilés disparus)
const VERSION = 'v5'
const SHELL = `shell-${VERSION}`
const ASSETS = `assets-${VERSION}`
const SHELL_KEY = '/__app-shell'

self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys()
    await Promise.all(keys.filter((k) => ![SHELL, ASSETS].includes(k)).map((k) => caches.delete(k)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (request.method !== 'GET' || url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/broadcasting')) return

  // Navigation dans l'application (toutes les pages servent le même HTML)
  if (request.mode === 'navigate') {
    if (url.pathname.startsWith('/admin')) return
    event.respondWith((async () => {
      try {
        const response = await fetch(request)
        // Seule la page de l'application sert hors ligne (pas la documentation de l'API,
        // ni la page « service suspendu »)
        if (response.ok && (await response.clone().text()).includes('<div id="app">')) {
          const cache = await caches.open(SHELL)
          await cache.put(SHELL_KEY, response.clone())
        }
        return response
      } catch {
        const cached = await caches.match(SHELL_KEY)
        return cached || new Response('<h1>Hors ligne</h1><p>Reconnectez-vous à Internet.</p>', {
          headers: { 'Content-Type': 'text/html; charset=utf-8' },
        })
      }
    })())
    return
  }

  if (url.pathname.startsWith('/build/')) {
    event.respondWith((async () => {
      const cached = await caches.match(request)
      if (cached) return cached
      const response = await fetch(request)
      // Jamais une page HTML gardée à la place d'un fichier compilé disparu
      if (response.ok && !(response.headers.get('content-type') || '').includes('text/html')) {
        (await caches.open(ASSETS)).put(request, response.clone())
      }
      return response
    })())
    return
  }

  if (url.pathname.startsWith('/manifest/')) {
    event.respondWith((async () => {
      const cache = await caches.open(ASSETS)
      try {
        const response = await fetch(request)
        if (response.ok) cache.put(request, response.clone())
        return response
      } catch {
        return (await cache.match(request)) || Response.error()
      }
    })())
    return
  }

  if (url.pathname.startsWith('/app-icons/')) {
    event.respondWith((async () => {
      const cache = await caches.open(ASSETS)
      const cached = await cache.match(request)
      const network = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone())
        return response
      }).catch(() => cached)
      return cached || network
    })())
  }
})

// Notifications push (nouvelle mission, consigne de l'agence) : affichées même application fermée
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'Livraison', body: event.data?.text() || '' }
  }

  const url = data.url || '/livreur'
  const app = url.startsWith('/marchand') ? 'marchand' : 'livreur'

  event.waitUntil(self.registration.showNotification(data.title || 'Nouvelle notification', {
    body: data.body || '',
    icon: data.icon || `/app-icons/${app}-192.png`,
    badge: `/app-icons/${app}-192.png`,
    tag: data.tag,
    renotify: Boolean(data.tag),
    requireInteraction: Boolean(data.urgent || data.mission),
    // Nouvelle mission : trois longues vibrations, à reconnaître sans regarder le téléphone
    vibrate: data.mission ? [500, 200, 500, 200, 500] : (data.urgent ? [300, 150, 300, 150, 300] : [200, 100, 200]),
    data: { url },
  }))
})

// Toucher la notification : ouvre (ou ramène au premier plan) l'application sur la bonne page
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/livreur', self.location.origin).href

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const existing = windows.find((w) => new URL(w.url).origin === self.location.origin)
    if (existing) {
      await existing.focus()
      return existing.navigate(target).catch(() => existing.postMessage({ type: 'navigate', url: target }))
    }
    return self.clients.openWindow(target)
  })())
})
