// Pointage des livreurs : heures, distances, jours, états

export const hm = (iso) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—')

export const meters = (m) => (m === null || m === undefined ? '—' : m >= 1000 ? `${(m / 1000).toFixed(1).replace('.', ',')} km` : `${Math.round(m)} m`)

// Distance en mètres entre deux points (haversine)
export function distance(lat1, lng1, lat2, lng2) {
  const r = 6371000
  const rad = (d) => (d * Math.PI) / 180
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2
  return 2 * r * Math.asin(Math.min(1, Math.sqrt(a)))
}

export const directionsLink = (lat, lng) => (lat && lng ? `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}` : null)

export const DAYS = [
  { value: 1, short: 'Lun', label: 'Lundi' },
  { value: 2, short: 'Mar', label: 'Mardi' },
  { value: 3, short: 'Mer', label: 'Mercredi' },
  { value: 4, short: 'Jeu', label: 'Jeudi' },
  { value: 5, short: 'Ven', label: 'Vendredi' },
  { value: 6, short: 'Sam', label: 'Samedi' },
  { value: 7, short: 'Dim', label: 'Dimanche' },
]

export function daysText(days) {
  const list = [...(days || [])].sort()
  if (list.length === 7) return 'Tous les jours'
  if (list.join() === '1,2,3,4,5') return 'Du lundi au vendredi'
  if (list.join() === '1,2,3,4,5,6') return 'Du lundi au samedi'
  return list.map((d) => DAYS[d - 1]?.short).join(', ')
}

export const ACTIONS = [
  { value: 'pickup', label: 'Récupérer' },
  { value: 'drop', label: 'Déposer' },
  { value: 'visit', label: 'Passer' },
]

export const SHIFT_STATES = {
  missing: { label: 'Pas pointé', cls: 'bg-red-100 text-red-800' },
  late: { label: 'En retard', cls: 'bg-amber-100 text-amber-800' },
  waiting: { label: 'Attendu', cls: 'bg-slate-100 text-slate-700' },
  on_time: { label: 'À l\'heure', cls: 'bg-emerald-100 text-emerald-800' },
  none: { label: 'Pas prévu', cls: 'bg-slate-50 text-slate-600' },
}
