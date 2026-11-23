/**
 * Prix d'un trajet, comme le calcule PricingService : d'abord un trajet départ → arrivée (du plus précis, quartier
 * vers quartier, au plus large, commune vers commune ; un trajet sans prix dans l'autre sens vaut dans les deux
 * sens), puis le prix habituel de la destination (« depuis partout »), puis le prix de base de la grille.
 *
 * prices : { "départ-arrivée": prix }, own : { zone: prix habituel | null }, base : prix de base | null.
 */
export const routeKey = (a, b) => `${a}-${b}`

export function lineage(zone, byId) {
  const ids = [zone.id]
  let parent = zone.parent_id
  for (let depth = 0; parent && depth < 4 && !ids.includes(parent); depth++) {
    ids.push(parent)
    parent = byId[parent]?.parent_id ?? null
  }
  return ids
}

/** @returns {{ price: number|null, kind: 'route'|'usual'|'base'|'none', origin?: number, destination?: number, reverse?: boolean }} */
export function resolveRoute(from, to, { byId, prices, own, base }) {
  const origins = lineage(from, byId)
  const destinations = lineage(to, byId)
  for (const o of origins) {
    for (const d of destinations) {
      if (prices[routeKey(o, d)] !== undefined) return { price: prices[routeKey(o, d)], kind: 'route', origin: o, destination: d, reverse: false }
      if (prices[routeKey(d, o)] !== undefined && prices[routeKey(o, d)] === undefined) return { price: prices[routeKey(d, o)], kind: 'route', origin: d, destination: o, reverse: true }
    }
  }
  for (const d of destinations) {
    if (own[d] !== null && own[d] !== undefined) return { price: own[d], kind: 'usual', destination: d }
  }
  if (base !== null && base !== undefined) return { price: base, kind: 'base' }
  return { price: null, kind: 'none' }
}
