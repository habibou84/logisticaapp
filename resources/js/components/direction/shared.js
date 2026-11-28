// Outils communs des statistiques de direction
import { SERIES } from '../stats/chart'

// Une couleur par activité, toujours la même (ordre validé des séries)
export const COLORS = { ecommerce: SERIES[0], distribution: SERIES[1], mad: SERIES[2] }
// Couleurs d'état des barres : à surveiller, en défaut, neutre
export const TONES = { warn: '#d97706', bad: '#b91c1c', muted: '#94a3b8' }

export const percent = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('fr-FR')} %`)
export const number = (v) => (v === null || v === undefined ? '—' : v.toLocaleString('fr-FR'))
export const minutes = (v) => (v === null || v === undefined ? '—' : `${v} min`)
export const share = (part, total) => (total > 0 ? Math.round((part / total) * 100) : null)
export const change = (now, before) => (before > 0 ? Math.round((now / before - 1) * 100) : null)
export const changeText = (v) => (v === null ? '—' : `${v > 0 ? '▲ +' : v < 0 ? '▼ ' : '= '}${v} %`)
export const changeClass = (v) => (!v ? 'text-slate-600' : v > 0 ? 'text-emerald-700' : 'text-red-600')

// Forfait : dépassé, sous-utilisé, normal (seuils des réglages des statistiques)
export const USAGE = {
  raise: { label: 'À revoir à la hausse', chip: 'border-amber-200 bg-amber-50 text-amber-800', color: TONES.warn },
  underused: { label: 'Sous-utilisé', chip: 'border-red-200 bg-red-50 text-red-700', color: TONES.bad },
  ok: { label: 'Normal', chip: 'border-emerald-200 bg-emerald-50 text-emerald-700', color: null },
}
