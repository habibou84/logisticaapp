// Véhicules : libellés et couleurs communs aux écrans de l'équipe et du livreur
export const VEHICLE_TYPES = {
  moto: { label: 'Moto', icon: '🏍️' },
  tricycle: { label: 'Tricycle', icon: '🛺' },
  voiture: { label: 'Voiture', icon: '🚗' },
  velo: { label: 'Vélo', icon: '🚲' },
  pieton: { label: 'À pied', icon: '🚶' },
}

export const STATUS_STYLES = {
  active: 'bg-emerald-100 text-emerald-800',
  broken: 'bg-red-100 text-red-800',
  maintenance: 'bg-sky-100 text-sky-800',
  immobilized: 'bg-orange-100 text-orange-800',
  out: 'bg-slate-200 text-slate-700',
  sold: 'bg-slate-100 text-slate-500',
}

export const OWNERSHIP_STYLES = {
  company: 'bg-slate-100 text-slate-700',
  courier: 'bg-amber-50 text-amber-800',
  rental: 'bg-sky-50 text-sky-800',
  lease: 'bg-violet-100 text-violet-800',
}

// État d'une échéance : dépassée, proche, à jour
export const STATE_STYLES = {
  expired: 'bg-red-100 text-red-800',
  due: 'bg-red-100 text-red-800',
  soon: 'bg-amber-100 text-amber-800',
  ok: 'bg-emerald-100 text-emerald-800',
  permanent: 'bg-slate-100 text-slate-700',
}

export const PERIODS = { day: 'jour', week: 'semaine', month: 'mois' }

/** « dans 19 j », « dépassée de 4 j », « aujourd'hui » */
export function daysText(days) {
  if (days === null || days === undefined) return ''
  if (days < 0) return `dépassé de ${-days} j`
  if (days === 0) return 'aujourd\'hui'
  return `dans ${days} j`
}

/** Ce qui reste avant une échéance d'entretien : km et/ou jours */
export function remainingText(item) {
  if (item.state === 'due') {
    const over = item.remaining_km !== null && item.remaining_km <= 0 ? `dépassé de ${(-item.remaining_km).toLocaleString('fr-FR')} km` : 'à faire'
    return over
  }
  return [
    item.remaining_km !== null ? `dans ${item.remaining_km.toLocaleString('fr-FR')} km` : null,
    item.remaining_days !== null ? `dans ${item.remaining_days} j` : null,
  ].filter(Boolean).join(' ou ')
}

/** « tous les 2 000 km », « tous les 6 mois » */
export function everyText(item) {
  return [
    item.every_km ? `${Number(item.every_km).toLocaleString('fr-FR')} km` : null,
    item.every_months ? `${item.every_months} mois` : null,
  ].filter(Boolean).join(' ou ')
}

export const km = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('fr-FR')} km`)
