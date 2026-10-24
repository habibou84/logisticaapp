import { reactive } from 'vue'
import http from '../bootstrap/axios'
import { isNetworkError } from './useOfflineQueue'

/**
 * Événements terrain du livreur (panne, accident…). Sans réseau, le signalement (photo réduite et note vocale
 * comprises) est gardé sur le téléphone et envoyé dès que le réseau revient ; son identifiant unique évite
 * qu'il soit créé deux fois.
 */
const KEY = 'field-events-pending'
const state = reactive({ pending: [], userId: null, syncing: false })

export const fieldEventQueue = state

function storageKey() {
  return `${KEY}:${state.userId}`
}

function save() {
  try {
    localStorage.setItem(storageKey(), JSON.stringify(state.pending))
  } catch {
    // Stockage plein : le signalement reste en mémoire jusqu'à l'envoi
  }
}

export function loadFieldEventQueue(userId) {
  if (state.userId === userId) return
  state.userId = userId
  try {
    state.pending = JSON.parse(localStorage.getItem(storageKey()) || '[]')
  } catch {
    state.pending = []
  }
}

function newId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

const blobToDataUrl = (blob) => new Promise((resolve, reject) => {
  const reader = new FileReader()
  reader.onload = () => resolve(reader.result)
  reader.onerror = reject
  reader.readAsDataURL(blob)
})

async function dataUrlToBlob(url) {
  return (await fetch(url)).blob()
}

/** Photo réduite (1280 px, JPEG) : envoi plus rapide sur un réseau faible, et place sur le téléphone */
export async function shrinkPhoto(file, max = 1280) {
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.75))
    return blob || file
  } catch {
    return file
  }
}

function formData(report) {
  const form = new FormData()
  for (const [key, value] of Object.entries(report.fields)) {
    if (value === null || value === undefined || value === '') continue
    if (Array.isArray(value)) value.forEach((v) => form.append(`${key}[]`, v))
    else form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value)
  }
  report.photos.forEach((p, i) => form.append('photos[]', p, `photo-${i + 1}.jpg`))
  if (report.voice) form.append('voice', report.voice, `note.${report.voice.type.includes('ogg') ? 'ogg' : report.voice.type.includes('mp4') ? 'm4a' : 'webm'}`)
  return form
}

/**
 * Envoie un signalement ; sans réseau, le garde pour plus tard. Renvoie { data } ou { offline: true }.
 * @param {{ fields: object, photos: Blob[], voice: Blob|null }} report
 */
export async function sendFieldEvent(report) {
  report.fields.client_uuid ||= newId()
  report.fields.occurred_at ||= new Date().toISOString()
  try {
    const { data } = await http.post('/courier/field-events', formData(report))
    return { data: data.data }
  } catch (e) {
    if (!isNetworkError(e)) throw e
    state.pending.push({
      fields: report.fields,
      photos: await Promise.all(report.photos.map(blobToDataUrl)),
      voice: report.voice ? await blobToDataUrl(report.voice) : null,
    })
    save()
    return { offline: true }
  }
}

/** Signalements gardés hors connexion : envoyés dans l'ordre, arrêt à la première coupure */
export async function syncFieldEvents() {
  if (state.syncing || !state.pending.length) return 0
  state.syncing = true
  let sent = 0
  try {
    while (state.pending.length) {
      const saved = state.pending[0]
      const report = {
        fields: saved.fields,
        photos: await Promise.all(saved.photos.map(dataUrlToBlob)),
        voice: saved.voice ? await dataUrlToBlob(saved.voice) : null,
      }
      try {
        await http.post('/courier/field-events', formData(report))
      } catch (e) {
        if (isNetworkError(e)) break
        // Refusé (type retiré entre-temps…) : abandonné pour ne pas bloquer les suivants
      }
      state.pending.shift()
      save()
      sent++
    }
  } finally {
    state.syncing = false
  }
  return sent
}

let started = false
export function startFieldEventSync(onSynced = () => {}) {
  if (started) return
  started = true
  const run = async () => {
    if (await syncFieldEvents()) onSynced()
  }
  window.addEventListener('online', run)
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && run())
  setInterval(() => state.pending.length && navigator.onLine && run(), 30000)
  run()
}

export const SEVERITY_STYLES = {
  urgent: 'bg-red-100 text-red-800',
  blocking: 'bg-orange-100 text-orange-800',
  hindrance: 'bg-amber-100 text-amber-800',
  info: 'bg-sky-100 text-sky-800',
}

export const STATUS_LABELS = { open: 'Envoyé, en attente', taken: 'Pris en charge', resolved: 'Réglé' }
