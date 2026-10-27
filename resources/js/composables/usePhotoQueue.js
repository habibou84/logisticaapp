import { reactive } from 'vue'
import http from '../bootstrap/axios'
import { isNetworkError, offlineQueue, rejectOffline } from './useOfflineQueue'

/**
 * Photos des courses prises sans réseau (accusé, décharge, ticket…) : gardées dans le téléphone (IndexedDB,
 * assez de place pour la photo traitée et son original) et envoyées au retour de la connexion. Chaque photo
 * porte un identifiant (client_ref) : renvoyée deux fois, le serveur ne la garde qu'une fois.
 */
const DB = 'photo-queue'
const STORE = 'photos'
const RETRY_MS = 30000

const state = reactive({ count: 0, syncing: false })
export const photoQueue = state

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, 1)
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' })
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

async function tx(mode, run) {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const t = db.transaction(STORE, mode)
    const result = run(t.objectStore(STORE))
    t.oncomplete = () => resolve(result?.result ?? result)
    t.onerror = () => reject(t.error)
  }).finally(() => db.close())
}

function newId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

function formData(entry) {
  const form = new FormData()
  Object.entries(entry.fields).forEach(([k, v]) => v !== null && v !== undefined && form.append(k, v))
  Object.entries(entry.files).forEach(([k, blob]) => blob && form.append(k, blob, blob.name || `${k}.jpg`))
  return form
}

async function refreshCount() {
  try {
    state.count = await tx('readonly', (s) => s.count())
  } catch {
    state.count = 0
  }
}

/**
 * Photo d'une course (et son original) envoyée au serveur ; sans réseau et si l'entreprise le permet,
 * gardée pour plus tard : renvoie { offline: true }.
 * @param {{ id: number, tracking_code?: string }} order
 * @param {{ file: File, original?: File|null, meta?: object }} photo
 */
export async function sendOrderPhoto(order, photo, fields, offlineEnabled) {
  const entry = {
    id: newId(),
    order_id: order.id,
    tracking_code: order.tracking_code,
    occurred_at: new Date().toISOString(),
    url: `/orders/${order.id}/attachments`,
    fields: { ...fields, file_meta: photo.meta ? JSON.stringify(photo.meta) : null },
    files: { file: photo.file, file_original: photo.original || null },
  }
  entry.fields.client_ref = entry.id
  if (offlineEnabled && !navigator.onLine) return keep(entry)
  try {
    const { data } = await http.post(entry.url, formData(entry))
    return { data, offline: false }
  } catch (e) {
    if (offlineEnabled && isNetworkError(e)) return keep(entry)
    throw e
  }
}

async function keep(entry) {
  await tx('readwrite', (s) => s.put(entry))
  await refreshCount()
  return { offline: true }
}

/** Envoie les photos gardées, dans l'ordre ; s'arrête à la première coupure. Renvoie le nombre envoyé. */
export async function syncPhotoQueue() {
  if (state.syncing) return 0
  state.syncing = true
  let sent = 0
  try {
    const entries = (await tx('readonly', (s) => s.getAll())) || []
    entries.sort((a, b) => a.occurred_at.localeCompare(b.occurred_at))
    for (const entry of entries) {
      try {
        await http.post(entry.url, formData(entry))
      } catch (e) {
        if (isNetworkError(e)) break
        // Refusée (course qui n'est plus la sienne…) : mise de côté avec son motif, pour que le livreur la voie
        rejectOffline({ id: entry.id, kind: 'photo', order_id: entry.order_id, tracking_code: entry.tracking_code, occurred_at: entry.occurred_at, message: e.response?.data?.message || 'Photo refusée.' })
      }
      await tx('readwrite', (s) => s.delete(entry.id))
      sent++
    }
  } catch {
    // Stockage indisponible : rien à envoyer
  } finally {
    state.syncing = false
    await refreshCount()
  }
  return sent
}

let started = false

/** Envoi automatique : au retour du réseau, au retour dans l'application, et toutes les 30 secondes */
export function startPhotoSync() {
  if (started || !window.indexedDB) return
  started = true
  const run = () => !offlineQueue.syncing && syncPhotoQueue()
  window.addEventListener('online', run)
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && run())
  setInterval(() => state.count && navigator.onLine && run(), RETRY_MS)
  refreshCount().then(run)
}
