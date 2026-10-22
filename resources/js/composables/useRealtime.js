import { onBeforeUnmount, ref } from 'vue'
import { useEcho } from '../bootstrap/echo'
import { useAuthStore } from '../stores/auth'
import { useNotificationStore } from '../stores/notifications'
import { useToastStore } from '../stores/toasts'
import { useFieldReportStore } from '../stores/fieldReports'
import { useCourierMessageStore } from '../stores/courierMessages'
import { useHeldParcelStore } from '../stores/heldParcels'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { desktopNotify, playAlertSound } from './useAlertSound'
import router from '../router'

// Compteur global incrémenté à chaque changement de course reçu en temps réel :
// les écrans le surveillent pour se rafraîchir.
export const orderChanges = ref(0)
export const lastOrderChange = ref(null)

// Vibration d'une nouvelle mission (aussi dans le service worker) : trois longues
export const MISSION_VIBRATION = [500, 200, 500, 200, 500]

/**
 * Abonne l'utilisateur connecté à ses canaux temps réel :
 * - notifications personnelles (cloche + toast) ;
 * - courses de l'entreprise (personnel) ou du marchand.
 */
export function useRealtime() {
  const auth = useAuthStore()
  const notifications = useNotificationStore()
  const toasts = useToastStore()
  const echo = useEcho()
  const channels = []

  if (!echo || !auth.user) return

  const userChannel = `App.Models.User.${auth.user.id}`
  const fieldReports = useFieldReportStore()
  const courierMessages = useCourierMessageStore()
  const heldParcels = useHeldParcelStore()
  const dispatchCounts = useDispatchCountStore()

  echo.private(userChannel).notification((n) => {
    notifications.receive(n)

    // Remontée terrain d'un livreur (note, problème) : son, notification du bureau, toast prolongé
    if (n.field) {
      const alert = n.severity === 'alert'
      playAlertSound(n.severity)
      desktopNotify(n.title, n.body, () => router.push(`${auth.homeRoute}/courses/${n.order_id}`))
      fieldReports.received()
      toasts.push(n.body, alert ? 'error' : 'info', { title: `${alert ? '⚠️' : '📝'} ${n.title}`, to: n.order_id, timeout: alert ? 20000 : 10000 })
      return
    }

    // Colis resté trop longtemps chez un livreur : son, notification du bureau, toast vers l'écran de suivi
    if (n.custody) {
      const href = '/admin/colis-livreurs'
      playAlertSound('alert')
      desktopNotify(n.title, n.body, () => router.push(href))
      heldParcels.received()
      toasts.push(n.body, 'error', { title: n.title, timeout: 20000, href })
      return
    }

    // Nouvelle mission du livreur : son et vibration propres, reconnaissables en roulant
    if (n.kind === 'new_mission' && auth.role === 'courier') {
      playAlertSound('mission')
      navigator.vibrate?.(MISSION_VIBRATION)
      toasts.push(n.body, 'info', { title: n.title, timeout: 15000, href: n.assignment_id ? `/livreur/missions/${n.assignment_id}` : '/livreur' })
      return
    }

    // Courses restées sans livreur (ramassage, livraison), livreur injoignable, ramassage en retard : son, notification du bureau, toast
    if (n.kind?.startsWith('unassigned_') || ['courier_unreachable', 'late_pickup'].includes(n.kind)) {
      playAlertSound('alert')
      desktopNotify(n.title, n.body, () => router.push(n.href))
      dispatchCounts.received()
      toasts.push(n.body, 'error', { title: n.title, timeout: 20000, href: n.href })
      return
    }

    // Livreur libre (ou presque) avec des ramassages près de lui : son discret, toast vers sa mission ramassage
    if (n.kind === 'courier_available') {
      playAlertSound('note')
      desktopNotify(n.title, n.body, () => router.push(n.href))
      toasts.push(n.body, 'info', { title: n.title, timeout: 15000, href: n.href })
      return
    }

    // Consigne du dispatch reçue par le livreur : son, vibration, toast qui ouvre la mission
    if (n.dispatch_message) {
      playAlertSound('alert')
      navigator.vibrate?.([300, 150, 300])
      courierMessages.received()
      toasts.push(n.body, 'info', { title: n.title, timeout: 15000, href: n.assignment_id ? `/livreur/missions/${n.assignment_id}` : '/livreur/messages' })
      return
    }

    toasts.push(n.body, n.kind === 'incident' ? 'error' : 'info', n.href ? { title: n.title, href: n.href, timeout: 12000 } : { title: n.title, to: n.order_id })
  })
  channels.push(userChannel)

  const orderChannel = auth.user.merchant_id
    ? `merchant.${auth.user.merchant_id}`
    : (auth.user.company_id && auth.role !== 'courier' ? `company.${auth.user.company_id}` : null)

  if (orderChannel) {
    echo.private(orderChannel).listen('.order.changed', (payload) => {
      lastOrderChange.value = payload
      orderChanges.value++
    })
    channels.push(orderChannel)
  }

  // Connexion retrouvée (réseau coupé, téléphone en veille) : les alertes et changements de course
  // reçus pendant la coupure sont rattrapés en rechargeant la cloche et les écrans ouverts
  const connection = echo.connector?.pusher?.connection
  let wasConnected = connection?.state === 'connected'
  const onStateChange = ({ current }) => {
    if (current !== 'connected') return
    if (wasConnected) {
      notifications.fetch().catch(() => {})
      orderChanges.value++
    }
    wasConnected = true
  }
  connection?.bind('state_change', onStateChange)

  onBeforeUnmount(() => {
    connection?.unbind('state_change', onStateChange)
    channels.forEach((c) => echo.leave(c))
  })
}
