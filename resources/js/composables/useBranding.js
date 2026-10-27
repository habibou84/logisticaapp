import { reactive } from 'vue'
import http from '../bootstrap/axios'
import { siteUrl } from '../native'

const CACHE_KEY = 'branding'

function readCache() {
  try {
    return JSON.parse(localStorage.getItem(CACHE_KEY)) || null
  } catch {
    return null
  }
}

// Nom, logo et accroche de l'entreprise de livraison (pages publiques). Affichage
// immédiat depuis le dernier chargement, puis mise à jour depuis le serveur.
export const branding = reactive({ name: '', tagline: '', phone: '', email: '', address: '', logo_url: null, signup_open: false, loaded: false, ...readCache() })

let loading = null

export function loadBranding(force = false) {
  if (loading && !force) return loading
  loading = http.get('/branding').then(({ data }) => {
    // Logo servi par le site de l'entreprise (lien absolu pour l'application)
    Object.assign(branding, data.data, { logo_url: siteUrl(data.data.logo_url), loaded: true })
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(data.data)) } catch { /* stockage indisponible */ }
    if (data.data.name) document.title = data.data.name
  }).catch(() => { branding.loaded = true })
  return loading
}

// Couleurs par défaut des espaces (sans réglage dans Paramètres › Apparence)
// Charte Logistica : rouge (client), rouge foncé (livreur), graphite (équipe)
export const DEFAULT_COLORS = { marchand: '#ec1d27', livreur: '#c02125', admin: '#1f2937' }

// Logo Logistica, affiché tant que l'entreprise n'a pas envoyé le sien (Réglages › Entreprise et marque)
export const DEFAULT_LOGO = '/brand/logistica.png'

/** Logo de l'entreprise, sinon celui de Logistica */
export function logoUrl() {
  return branding.logo_url || DEFAULT_LOGO
}

/** Couleur choisie par l'entreprise pour un espace (marchand, livreur, admin) */
export function spaceColor(space) {
  return branding.appearance?.spaces?.[space]?.color || DEFAULT_COLORS[space]
}

/** Nom de l'application d'un espace (affiché sous l'icône du téléphone) */
export function spaceAppName(space, fallback) {
  return branding.appearance?.spaces?.[space]?.app_name || fallback
}

// Numéro au format wa.me (chiffres seulement, indicatif compris)
export function whatsappLink(phone, text = '') {
  const digits = String(phone || '').replace(/\D/g, '')
  return digits ? `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}` : null
}
