// Miroir côté interface des transitions autorisées (App\Enums\OrderStatus).
// Le serveur reste seul juge : ceci sert uniquement à proposer les bonnes actions.
export const TRANSITIONS = {
  pending: ['confirmed', 'rejected', 'cancelled'],
  confirmed: ['cancelled', 'at_hub'],
  pickup_assigned: ['pickup_in_progress', 'picked_up', 'confirmed', 'cancelled'],
  pickup_in_progress: ['picked_up', 'confirmed', 'cancelled'],
  picked_up: ['at_hub', 'out_for_delivery'],
  at_hub: ['out_for_delivery', 'rescheduled', 'returned'],
  delivery_assigned: ['out_for_delivery', 'at_hub'],
  out_for_delivery: ['delivered', 'delivery_failed', 'rescheduled'],
  delivery_failed: ['rescheduled', 'out_for_delivery', 'at_hub', 'returned'],
  rescheduled: ['out_for_delivery', 'at_hub', 'rescheduled', 'returned'],
  return_assigned: ['returning', 'returned'],
  returning: ['returned'],
}

export const FINAL = ['delivered', 'returned', 'cancelled', 'rejected', 'lost']
export const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']

export const ASSIGNABLE = {
  pickup: ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress'],
  delivery: ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress', 'picked_up', 'at_hub', 'delivery_assigned', 'delivery_failed', 'rescheduled'],
  return: ['picked_up', 'at_hub', 'delivery_assigned', 'delivery_failed', 'rescheduled', 'return_assigned'],
}

// Transitions propres aux commandes d'entrepôt (préparation, remise en stock) : boutons dédiés
export function isWarehouseStep(from, to) {
  return (from === 'confirmed' && to === 'at_hub') || (to === 'returned' && !['return_assigned', 'returning'].includes(from))
}

export const ASSIGNMENT_LABELS = { pickup: 'Ramassage', delivery: 'Livraison', return: 'Retour' }

// Transitions qui demandent des informations complémentaires
export function needsReason(from, to) {
  return to === 'delivery_failed' || (to === 'confirmed' && BEFORE_PICKUP.includes(from) && from !== 'pending')
}

export function needsDate(to) {
  return to === 'rescheduled'
}
