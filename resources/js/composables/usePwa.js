import { reactive } from 'vue'
import { nativeApp } from '../native'
import { spaceForPath } from '../bootstrap/session'
import { branding, spaceColor } from './useBranding'

// État partagé : installation de l'application et connexion réseau
export const pwa = reactive({
  canInstall: false, // Android/Chrome : invite d'installation disponible
  // Application des stores : déjà installée, pas d'invitation à installer
  installed: !!nativeApp || window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true,
  isIos: /iphone|ipad|ipod/i.test(window.navigator.userAgent),
  online: window.navigator.onLine,
})

let deferredPrompt = null

export function setupPwa() {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredPrompt = event
    pwa.canInstall = true
  })
  window.addEventListener('appinstalled', () => {
    pwa.installed = true
    pwa.canInstall = false
  })
  window.addEventListener('online', () => { pwa.online = true })
  window.addEventListener('offline', () => { pwa.online = false })

  // Service worker uniquement sur le build de production (pas avec le serveur Vite)
  if (import.meta.env.PROD && !nativeApp && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
  }
}

export async function promptInstall() {
  if (!deferredPrompt) return false
  deferredPrompt.prompt()
  const { outcome } = await deferredPrompt.userChoice
  deferredPrompt = null
  pwa.canInstall = false
  return outcome === 'accepted'
}

/**
 * Adapte le manifeste, l'icône et la couleur de la barre du téléphone à l'espace affiché
 * (navigation interne de l'application, sans rechargement de page), avec la couleur choisie
 * par l'entreprise (Paramètres › Apparence).
 */
export function applyAppTheme(path) {
  const space = spaceForPath(path)
  if (!space) return

  const set = (selector, create, attr, value) => {
    let el = document.head.querySelector(selector)
    if (!el) {
      el = create()
      document.head.appendChild(el)
    }
    el.setAttribute(attr, value)
  }
  const link = (rel) => () => Object.assign(document.createElement('link'), { rel })

  set('meta[name="theme-color"]', () => Object.assign(document.createElement('meta'), { name: 'theme-color' }), 'content', spaceColor(space))
  // L'espace équipe ne s'installe pas
  if (space === 'admin') return
  const icon = branding.appearance?.spaces?.[space]?.icon_url || `/app-icons/${space}-192.png`
  set('link[rel="manifest"]', link('manifest'), 'href', `/manifest/${space}.webmanifest`)
  set('link[rel="apple-touch-icon"]', link('apple-touch-icon'), 'href', icon.replace('192.png', 'apple-180.png'))
}
