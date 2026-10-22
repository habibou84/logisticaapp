import { nativeApp } from '../native'

/**
 * Une session par espace (e-commerçant, livreur, équipe) : une personne qui a deux comptes peut être
 * connectée aux deux dans le même navigateur, et l'application livreur installée ne reprend jamais la
 * session e-commerçant du navigateur (elles partagent le même stockage).
 */
const SPACE_KEY = 'space'
const NATIVE_SPACES = { livreur: 'livreur', ecommercant: 'marchand' }

/** Espace d'une page ; null pour les pages communes (mot de passe oublié, suivi d'un colis…) */
export function spaceForPath(path) {
  if (path.startsWith('/livreur')) return 'livreur'
  if (path.startsWith('/admin') || path.startsWith('/console')) return 'admin'
  if (path === '/' || path.startsWith('/marchand') || path.startsWith('/inscription')) return 'marchand'
  return null
}

/** Valeur attendue par le serveur pour la page de connexion de cet espace */
export const SERVER_SPACES = { marchand: 'merchant', livreur: 'courier', admin: 'staff' }
/** Page de connexion de chaque espace (et inversement, depuis la réponse du serveur) */
export const LOGIN_PAGES = { marchand: '/', livreur: '/livreur/connexion', admin: '/admin/connexion' }
export const SPACE_FROM_SERVER = { merchant: 'marchand', courier: 'livreur', staff: 'admin' }

function read(key) {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function write(key, value) {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, value)
  } catch {
    // stockage indisponible : la session ne survivra pas au rechargement
  }
}

// Ancienne session unique (avant la séparation) : rangée dans l'espace de son compte
function migrate() {
  const token = read('token')
  if (!token) return
  let role = null
  try {
    role = JSON.parse(read('user') || 'null')?.role
  } catch {
    // profil illisible : session rangée d'après la page ouverte
  }
  const space = role === 'courier' ? 'livreur' : ['merchant_owner', 'merchant_staff'].includes(role) ? 'marchand' : role ? 'admin' : (spaceForPath(window.location.pathname) || 'marchand')
  if (!read(`token:${space}`)) {
    write(`token:${space}`, token)
    if (read('user')) write(`user:${space}`, read('user'))
  }
  write('token', null)
  write('user', null)
}
migrate()

let current = NATIVE_SPACES[nativeApp] || spaceForPath(window.location.pathname) || read(SPACE_KEY) || 'marchand'

export function currentSpace() {
  return current
}

/** Change d'espace ; renvoie vrai si l'espace a changé */
export function setSpace(space) {
  if (nativeApp || !space || space === current) return false
  current = space
  write(SPACE_KEY, space)
  return true
}

export const getToken = () => read(`token:${current}`)
export const setToken = (token) => write(`token:${current}`, token)
export const getCachedUser = () => {
  try {
    return JSON.parse(read(`user:${current}`) || 'null')
  } catch {
    return null
  }
}
export const setCachedUser = (user) => write(`user:${current}`, user ? JSON.stringify(user) : null)
