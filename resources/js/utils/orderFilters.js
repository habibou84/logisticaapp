/**
 * Catégories des courses du client, communes à l'accueil (compteurs) et à la liste :
 * mêmes statuts, même règle « à régler » que la file « À décider » de l'agence.
 */
export const ACTIVE = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress', 'picked_up', 'at_hub', 'delivery_assigned', 'out_for_delivery', 'rescheduled', 'return_assigned', 'returning']

export const ORDER_FILTERS = [
  { value: 'all', label: 'Toutes', short: 'Toutes', statuses: [], color: 'text-slate-900' },
  { value: 'active', label: 'En cours', short: 'En cours', statuses: ACTIVE, color: 'text-blue-700' },
  { value: 'problems', label: 'À régler', short: 'À régler', statuses: [], queue: 'to_decide', warn: true, color: 'text-amber-700' },
  // Demandes des agents à valider par le chef de service (départements)
  { value: 'approval', label: 'À valider', short: 'À valider', statuses: [], queue: 'to_approve', warn: true, color: 'text-amber-700', hideEmpty: true, anyDay: true },
  { value: 'delivered', label: 'Livrées', short: 'Livrées', statuses: ['delivered'], color: 'text-emerald-700' },
  { value: 'other', label: 'Retournées / annulées', short: 'Retours', statuses: ['returned', 'cancelled', 'rejected'], color: 'text-slate-600' },
]

/**
 * Paramètres de l'API pour une catégorie et une période. Le client voit ses courses au jour où
 * elles doivent être livrées (date prévue, sinon jour de création) : une course reportée de lundi à
 * mercredi passe au mercredi.
 */
export function filterParams(filter, period) {
  // Demandes à valider : quel que soit leur jour
  if (filter.anyDay) period = null
  return {
    status: filter.statuses.length ? filter.statuses : undefined,
    queue: filter.queue,
    from: period?.from || undefined,
    to: period?.to || undefined,
    date_basis: period?.from || period?.to ? 'delivery' : undefined,
  }
}
