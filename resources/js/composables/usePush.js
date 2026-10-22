import { reactive } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { nativeApp } from '../native'
import { pwa } from './usePwa'

// Notifications push de l'appareil (Web Push) : prévenir le livreur même téléphone en veille.
export const push = reactive({
  // Applications Android et iOS : notifications natives (étape suivante), pas de Web Push
  supported: !nativeApp && typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
  serverEnabled: false,
  // E-commerçant dont les messages passent en notification (sinon WhatsApp seulement)
  merchantMessages: false,
  permission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
  subscribed: false,
  busy: false,
  error: '',
  checked: false,
})

let publicKey = null

function keyToBytes(base64url) {
  const padding = '='.repeat((4 - (base64url.length % 4)) % 4)
  const raw = atob((base64url + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

async function registration() {
  // Le service worker n'existe qu'en production : on n'attend pas indéfiniment
  return Promise.race([navigator.serviceWorker.ready, new Promise((resolve) => setTimeout(() => resolve(null), 4000))])
}

async function sendToServer(subscription) {
  await http.post('/push/subscriptions', subscription.toJSON())
}

/**
 * iPhone : les notifications push n'existent que pour l'application installée (iOS 16.4 et plus).
 */
export function needsInstallFirst() {
  return pwa.isIos && !pwa.installed
}

export async function initPush() {
  if (push.checked) return
  try {
    const { data } = await http.get('/push')
    push.serverEnabled = data.data.enabled
    push.merchantMessages = Boolean(data.data.merchant_messages)
    publicKey = data.data.public_key
  } catch {
    return
  }
  push.checked = true
  if (!push.supported || !push.serverEnabled) return

  push.permission = Notification.permission
  const reg = await registration()
  const subscription = await reg?.pushManager.getSubscription()
  push.subscribed = Boolean(subscription) && push.permission === 'granted'
  // Abonnement déjà présent sur le téléphone : on le rattache au compte connecté
  if (push.subscribed) sendToServer(subscription).catch(() => {})
}

export async function enablePush() {
  push.error = ''
  if (needsInstallFirst()) {
    push.error = 'Sur iPhone, installez d\'abord l\'application : Partager → « Sur l\'écran d\'accueil », puis ouvrez-la depuis l\'icône.'
    return false
  }
  push.busy = true
  try {
    push.permission = await Notification.requestPermission()
    if (push.permission !== 'granted') {
      push.error = 'Notifications refusées : autorisez-les dans les réglages du navigateur pour ce site.'
      return false
    }
    const reg = await registration()
    if (!reg) {
      push.error = 'Application pas encore prête : rechargez la page puis réessayez.'
      return false
    }
    const subscription = (await reg.pushManager.getSubscription())
      || (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) }))
    await sendToServer(subscription)
    push.subscribed = true
    return true
  } catch (e) {
    push.error = e?.response ? apiErrorMessage(e) : 'Activation impossible sur cet appareil.'
    return false
  } finally {
    push.busy = false
  }
}

export async function disablePush() {
  push.busy = true
  try {
    const reg = await registration()
    const subscription = await reg?.pushManager.getSubscription()
    if (subscription) {
      await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => {})
      await subscription.unsubscribe()
    }
    push.subscribed = false
  } finally {
    push.busy = false
  }
}

export async function testPush() {
  const { data } = await http.post('/push/test')
  return data.data.devices
}

/**
 * Déconnexion : le téléphone ne doit plus recevoir les alertes de ce compte.
 */
export async function forgetPushDevice() {
  if (!push.supported || !push.subscribed) return
  try {
    const reg = await registration()
    const subscription = await reg?.pushManager.getSubscription()
    if (subscription) await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } })
  } catch {
    // Sans réseau : le serveur rattachera l'appareil au prochain compte connecté
  }
  push.subscribed = false
  push.checked = false
}
