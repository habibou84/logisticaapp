import { reactive } from 'vue'

// Alertes sonores et notifications du bureau pour les remontées terrain.
// Les navigateurs n'autorisent le son qu'après une action de l'utilisateur (clic, touche) :
// le contexte audio est donc débloqué au premier clic dans la page.
const KEY = 'alert-sound-settings'

function readSettings() {
  try {
    return { enabled: true, volume: 0.6, ...JSON.parse(localStorage.getItem(KEY) || '{}') }
  } catch {
    return { enabled: true, volume: 0.6 }
  }
}

export const alertSound = reactive({
  ...readSettings(),
  unlocked: false,
  desktop: typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
})

let context = null

function save() {
  try {
    localStorage.setItem(KEY, JSON.stringify({ enabled: alertSound.enabled, volume: alertSound.volume }))
  } catch {
    // Stockage indisponible : réglage gardé pour la session
  }
}

function audio() {
  const Ctx = window.AudioContext || window.webkitAudioContext
  if (!Ctx) return null
  context ??= new Ctx()
  return context
}

export function unlockAlertSound() {
  const ctx = audio()
  if (!ctx) return
  ctx.resume().then(() => { alertSound.unlocked = ctx.state === 'running' }).catch(() => {})
}

// Débloque le son au premier geste de l'utilisateur
if (typeof window !== 'undefined') {
  const once = () => {
    unlockAlertSound()
    window.removeEventListener('pointerdown', once)
    window.removeEventListener('keydown', once)
  }
  window.addEventListener('pointerdown', once)
  window.addEventListener('keydown', once)
}

function tone(ctx, frequency, start, duration, volume) {
  const osc = ctx.createOscillator()
  const gain = ctx.createGain()
  osc.type = 'sine'
  osc.frequency.value = frequency
  gain.gain.setValueAtTime(0.0001, ctx.currentTime + start)
  gain.gain.exponentialRampToValueAtTime(volume, ctx.currentTime + start + 0.02)
  gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + duration)
  osc.connect(gain).connect(ctx.destination)
  osc.start(ctx.currentTime + start)
  osc.stop(ctx.currentTime + start + duration + 0.05)
}

/**
 * « alert » (problème : 3 bips aigus, insistants), « note » (carillon doux à deux tons)
 * ou « mission » (nouvelle mission du livreur : quatre notes montantes, jouées deux fois, à reconnaître sans regarder).
 */
export function playAlertSound(severity = 'note', force = false) {
  if (!alertSound.enabled && !force) return
  const ctx = audio()
  if (!ctx) return
  if (ctx.state !== 'running') ctx.resume().catch(() => {})
  const v = Math.max(0.05, Math.min(1, alertSound.volume))

  if (severity === 'alert') {
    ;[0, 0.22, 0.44].forEach((t) => tone(ctx, 988, t, 0.16, v))
  } else if (severity === 'mission') {
    ;[0, 0.9].forEach((start) => [523, 659, 784, 1047].forEach((f, i) => tone(ctx, f, start + i * 0.14, 0.2, v)))
  } else {
    tone(ctx, 659, 0, 0.25, v * 0.8)
    tone(ctx, 880, 0.18, 0.35, v * 0.8)
  }
}

export function setAlertSound(changes) {
  Object.assign(alertSound, changes)
  save()
}

export async function enableDesktopNotifications() {
  if (typeof Notification === 'undefined') return 'unsupported'
  alertSound.desktop = await Notification.requestPermission()
  return alertSound.desktop
}

/**
 * Notification du système quand l'onglet n'est pas au premier plan.
 */
export function desktopNotify(title, body, onClick) {
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted' || !document.hidden) return
  try {
    const n = new Notification(title, { body, tag: `field-${title}`, renotify: true })
    n.onclick = () => {
      window.focus()
      onClick?.()
      n.close()
    }
  } catch {
    // Certains navigateurs (mobiles) exigent le service worker : on se contente du son et du toast
  }
}
