import { reactive } from 'vue'
import http from '../bootstrap/axios'
import { currentPosition } from './useGeolocation'

/**
 * Application mécanicien : listes du diagnostic (types de panne, décisions, causes), étapes notées hors connexion
 * (« Je pars », « Je suis arrivé » gardent l'heure du clic et partent au retour du réseau), position pendant le service.
 */

export const mechanicSetup = reactive({ loaded: false, categories: [], decisions: [], causes: [], offer_minutes: 5 })

export async function loadMechanicSetup() {
  if (mechanicSetup.loaded) return mechanicSetup
  const { data } = await http.get('/mechanic/setup')
  Object.assign(mechanicSetup, data.data, { loaded: true })
  return mechanicSetup
}

export const REPAIR_STATUS_STYLES = {
  searching: 'bg-red-100 text-red-800',
  offered: 'bg-orange-100 text-orange-800',
  accepted: 'bg-sky-100 text-sky-800',
  en_route: 'bg-sky-100 text-sky-800',
  on_site: 'bg-violet-100 text-violet-800',
  repairing: 'bg-violet-100 text-violet-800',
  done: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-slate-100 text-slate-600',
}

export const SEVERITY_BORDERS = {
  urgent: 'border-l-red-500',
  blocking: 'border-l-orange-500',
  hindrance: 'border-l-amber-400',
  info: 'border-l-sky-400',
}

export const time = (v) => (v ? new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—')

/** Durée lisible entre deux heures (« 33 min », « 1 h 05 ») */
export function duration(from, to = new Date()) {
  if (!from) return ''
  const minutes = Math.max(0, Math.round((new Date(to) - new Date(from)) / 60000))
  if (minutes < 60) return `${minutes} min`
  return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`
}

/** Il y a combien de temps (« à l'instant », « il y a 12 min », « il y a 2 h ») */
export function ago(value) {
  if (!value) return ''
  const minutes = Math.round((Date.now() - new Date(value)) / 60000)
  if (minutes < 1) return 'à l\'instant'
  if (minutes < 60) return `il y a ${minutes} min`
  if (minutes < 1440) return `il y a ${Math.round(minutes / 60)} h`
  return new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
}

// ─── Étapes notées hors connexion ───
const QUEUE_KEY = 'mechanic-steps'
export const stepQueue = reactive({ pending: [] })

function save() {
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(stepQueue.pending))
  } catch {
    // Stockage indisponible : la file reste en mémoire pour cette session
  }
}

export function loadStepQueue() {
  try {
    stepQueue.pending = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
  } catch {
    stepQueue.pending = []
  }
}

/**
 * Étape « Je pars » ou « Je suis arrivé » : envoyée tout de suite ; sans réseau, gardée avec l'heure du clic.
 * Renvoie la panne à jour, ou null si l'étape attend le réseau.
 */
export async function sendStep(repairId, action, extra = {}) {
  const step = { repairId, action, at: new Date().toISOString(), ...extra }
  try {
    const { data } = await http.post(`/mechanic/repairs/${repairId}/${action}`, step)
    return data.data
  } catch (error) {
    if (error.response) throw error
    stepQueue.pending.push(step)
    save()
    return null
  }
}

let syncing = false
export async function syncSteps() {
  if (syncing || !stepQueue.pending.length || !navigator.onLine) return
  syncing = true
  try {
    while (stepQueue.pending.length) {
      const step = stepQueue.pending[0]
      try {
        await http.post(`/mechanic/repairs/${step.repairId}/${step.action}`, step)
      } catch (error) {
        // Toujours pas de réseau : on réessaiera ; refusée (étape déjà passée, panne annulée) : abandonnée
        if (!error.response) break
      }
      stepQueue.pending.shift()
      save()
    }
  } finally {
    syncing = false
  }
}

export function startStepSync() {
  window.addEventListener('online', syncSteps)
  const timer = setInterval(syncSteps, 30000)
  syncSteps()
  return () => {
    window.removeEventListener('online', syncSteps)
    clearInterval(timer)
  }
}

/** Position du mécanicien (attribution au plus proche), envoyée pendant le service */
export async function sendPosition(extra = {}) {
  const position = await currentPosition(5000)
  if (!position && !Object.keys(extra).length) return null
  const { data } = await http.patch('/mechanic/status', { ...(position || {}), ...extra })
  return data.data
}

// ─── Tableau des pannes (accueil, alerte de proposition) ───
export const board = reactive({ data: null, loading: false, error: '' })

export async function loadBoard() {
  board.loading = true
  try {
    board.data = (await http.get('/mechanic/repairs')).data.data
    board.error = ''
  } catch (error) {
    if (!board.data) board.error = error.response?.data?.message || 'Pannes indisponibles. Vérifiez votre réseau.'
  } finally {
    board.loading = false
  }
  return board.data
}
