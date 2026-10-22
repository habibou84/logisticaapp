import { longDay } from '../composables/useDeliveryDate'
import { today } from './format'

/**
 * Où en est une course, en une phrase pour l'e-commerçant (au lieu du nom technique du statut),
 * avec une petite icône (StatusIcon) et un ton : bleu en route, vert livrée, orange à régler,
 * violet retour, rouge refusée ou perdue, gris le reste.
 */
const TONES = {
  blue: { color: 'text-blue-700', bg: 'bg-blue-50' },
  green: { color: 'text-emerald-700', bg: 'bg-emerald-50' },
  amber: { color: 'text-amber-700', bg: 'bg-amber-50' },
  violet: { color: 'text-violet-700', bg: 'bg-violet-50' },
  red: { color: 'text-red-700', bg: 'bg-red-50' },
  slate: { color: 'text-slate-500', bg: 'bg-slate-100' },
}

const hour = (value) => (value ? new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')

function line(order) {
  switch (order.status) {
    case 'pending': return ['clock', 'En attente de validation par l\'agence', 'slate']
    case 'confirmed': return ['clipboard', 'Validée : un livreur va venir la chercher', 'slate']
    case 'pickup_assigned':
    case 'pickup_in_progress': return ['store', 'Un livreur vient chercher le colis', 'blue']
    case 'picked_up':
    case 'at_hub':
    case 'delivery_assigned': return ['package', 'Colis récupéré, bientôt en route', 'blue']
    case 'out_for_delivery': return ['moto', 'Le livreur est en route vers le client', 'blue']
    case 'delivered': return ['check', order.delivered_at ? `Livrée à ${hour(order.delivered_at)}` : 'Livrée', 'green']
    // Décision prise : le colis revient, ou une nouvelle livraison est prévue
    case 'delivery_failed': return order.return_requested
      ? ['back', 'Retour demandé : le colis va vous revenir', 'violet']
      : ['alert', order.last_incident?.label || 'Livraison échouée : à régler', 'amber']
    case 'rescheduled': return ['calendar', order.delivery?.scheduled_date ? `Relivraison prévue le ${longDay(order.delivery.scheduled_date)}` : 'Relivraison prévue', 'blue']
    case 'return_assigned':
    case 'returning': return ['back', 'En retour vers votre boutique', 'violet']
    case 'returned': return ['store', 'Retournée à votre boutique', 'violet']
    case 'cancelled': return ['x', 'Annulée', 'slate']
    case 'rejected': return ['ban', 'Refusée par l\'agence', 'red']
    case 'lost': return ['search', 'Colis perdu : l\'agence vous contacte', 'red']
    default: return ['package', order.status_label || order.status, 'slate']
  }
}

export function merchantStatus(order) {
  const [icon, text, tone] = line(order)

  return { icon, text, tone, ...TONES[tone] }
}

/** La course attend-elle une décision ? (calculé par le serveur, comme la file « À décider » de l'agence) */
export const needsDecision = (order) => !!order.needs_decision

const FINAL = ['delivered', 'returned', 'cancelled', 'rejected', 'lost']

/**
 * Jour de livraison d'une course prévue après aujourd'hui, en court : « Demain », « Mer. 22 » (vide sinon).
 */
export function laterDay(order) {
  const day = order.delivery?.scheduled_date
  const now = today()
  if (!day || day <= now || FINAL.includes(order.status)) return ''
  const tomorrow = new Date(`${now}T12:00:00`)
  tomorrow.setDate(tomorrow.getDate() + 1)
  if (day === tomorrow.toISOString().slice(0, 10)) return 'Demain'
  const label = new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' })
  return label.charAt(0).toUpperCase() + label.slice(1)
}
