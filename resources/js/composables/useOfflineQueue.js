import { computed, reactive } from 'vue'
import http from '../bootstrap/axios'
import { STATUS_LABELS } from '../utils/format'

/**
 * Mode hors connexion des livreurs : les actions faites sans réseau (statut, livraison, échec, note)
 * sont gardées sur le téléphone, dans l'ordre, puis envoyées une à une au retour de la connexion.
 * Chaque action porte un identifiant unique : renvoyée deux fois, elle n'est appliquée qu'une fois.
 * Une action refusée par le serveur (course annulée ou réattribuée entre-temps) est mise de côté
 * avec son motif, pour que le livreur la voie.
 */
const KEY = 'offline-actions'
const RETRY_MS = 30000

const state = reactive({ pending: [], rejected: [], syncing: false, userId: null })

export const offlineQueue = state
export const pendingCount = computed(() => state.pending.length)

function storageKey() {
  return `${KEY}:${state.userId}`
}

function save() {
  try {
    localStorage.setItem(storageKey(), JSON.stringify({ pending: state.pending, rejected: state.rejected }))
  } catch {
    // Stockage plein ou indisponible : la file reste en mémoire jusqu'à l'envoi
  }
}

function newId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

/** Coupure réseau (pas de réponse du serveur, ou serveur momentanément indisponible) */
export function isNetworkError(error) {
  const status = error?.response?.status
  return !error?.response || status === 429 || status >= 500
}

/** File du livreur connecté, relue sur le téléphone (après fermeture de l'application) */
export function loadOfflineQueue(userId) {
  if (state.userId === userId) return
  state.userId = userId
  state.pending = []
  state.rejected = []
  if (!userId) return
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey()) || 'null')
    state.pending = saved?.pending ?? []
    state.rejected = saved?.rejected ?? []
  } catch {
    // Données illisibles : file vide
  }
}

/**
 * Garde une action pour l'envoyer plus tard ; renvoie l'action enregistrée.
 * @param {{ kind: 'status'|'note', order_id: number, tracking_code?: string, payload: object }} action
 */
export function enqueue(action) {
  const entry = { id: newId(), occurred_at: new Date().toISOString(), ...action }
  state.pending.push(entry)
  save()
  return entry
}

/** Une action en attente pour cette course : les suivantes doivent passer après elle */
export function hasPendingFor(orderId) {
  return state.pending.some((a) => a.order_id === orderId)
}

/** Dernier statut gardé hors connexion pour cette course (affiché à la place de celui du serveur) */
export function pendingStatus(orderId) {
  const last = [...state.pending].reverse().find((a) => a.order_id === orderId && a.kind === 'status')
  return last?.payload?.status ?? null
}

/** Missions avec les changements faits hors connexion */
export function withPending(missions) {
  return missions.map((m) => {
    const status = m.order && pendingStatus(m.order.id)
    if (!status) return m
    return { ...m, offline: true, order: { ...m.order, status, status_label: STATUS_LABELS[status] ?? status } }
  })
}

export function dismissRejected(id) {
  state.rejected = state.rejected.filter((a) => a.id !== id)
  save()
}

/**
 * Envoie les actions en attente, dans l'ordre. S'arrête à la première coupure réseau (nouvel essai
 * plus tard). Renvoie le nombre d'actions envoyées.
 */
export async function syncOfflineQueue() {
  if (state.syncing || !state.pending.length) return 0
  state.syncing = true
  let sent = 0
  try {
    while (state.pending.length) {
      const action = state.pending[0]
      let data
      try {
        ;({ data } = await http.post('/courier/sync', {
          id: action.id, kind: action.kind, order_id: action.order_id, occurred_at: action.occurred_at, payload: action.payload,
        }))
      } catch (e) {
        if (isNetworkError(e)) break
        // Demande mal formée : refusée, pour ne pas bloquer les suivantes
        data = { data: { result: 'rejected', message: e.response?.data?.message || 'Action refusée.' } }
      }
      state.pending.shift()
      if (data.data.result === 'rejected') {
        state.rejected.push({ ...action, message: data.data.message })
      }
      sent++
      save()
    }
  } finally {
    state.syncing = false
  }
  return sent
}

let started = false

/** Envoi automatique : au retour du réseau, au retour dans l'application, et toutes les 30 secondes */
export function startOfflineSync(onSynced = () => {}) {
  if (started) return
  started = true
  const run = async () => {
    const sent = await syncOfflineQueue()
    if (sent) onSynced(sent)
  }
  window.addEventListener('online', run)
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && run())
  setInterval(() => state.pending.length && navigator.onLine && run(), RETRY_MS)
  run()
}

/**
 * Changement de statut d'une course. Sans réseau (mode hors connexion de l'entreprise), l'action est
 * gardée sur le téléphone et renvoie { offline: true } ; elle partira au retour de la connexion.
 * @param {{ id: number, tracking_code?: string }} order
 */
export async function sendStatus(order, payload, offlineEnabled) {
  const keep = () => {
    enqueue({ kind: 'status', order_id: order.id, tracking_code: order.tracking_code, payload })
    return { offline: true }
  }
  // Une action déjà en attente pour cette course : la suivante passe après elle
  if (offlineEnabled && (!navigator.onLine || hasPendingFor(order.id))) return keep()
  try {
    const { data } = await http.post(`/orders/${order.id}/status`, payload)
    return { data, offline: false }
  } catch (e) {
    if (offlineEnabled && isNetworkError(e)) return keep()
    throw e
  }
}
