import { date as formatDate } from './format'

// Date locale au format AAAA-MM-JJ, décalée de n jours
export function shift(days) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}

/** Raccourcis de période (accueil et liste des courses) */
export const PERIOD_PRESETS = {
  all: { key: 'all', label: 'Toutes les dates', from: () => '', to: () => '' },
  today: { key: 'today', label: 'Aujourd\'hui', from: () => shift(0), to: () => shift(0) },
  // Courses à livrer après aujourd'hui (date de livraison prévue)
  later: { key: 'later', label: 'Prévues plus tard', from: () => shift(1), to: () => '' },
  yesterday: { key: 'yesterday', label: 'Hier', from: () => shift(-1), to: () => shift(-1) },
  week: { key: 'week', label: '7 derniers jours', from: () => shift(-6), to: () => shift(0) },
  month: { key: 'month', label: 'Ce mois-ci', from: () => `${shift(0).slice(0, 8)}01`, to: () => shift(0) },
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/
const short = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`

/** Période d'une adresse (?periode=…&du=…&au=…), sinon celle par défaut */
export function periodFromQuery(query, fallback = 'today') {
  const { periode, du, au } = query
  if (PERIOD_PRESETS[periode] && periode !== 'custom') {
    const p = PERIOD_PRESETS[periode]
    return { key: p.key, label: p.label, from: p.from(), to: p.to() }
  }
  if (periode === 'custom' && DATE_RE.test(du || '') && DATE_RE.test(au || '')) {
    return { key: 'custom', label: du === au ? `Le ${formatDate(du)}` : `${short(du)} → ${short(au)}`, from: du, to: au }
  }
  const p = PERIOD_PRESETS[fallback]
  return { key: p.key, label: p.label, from: p.from(), to: p.to() }
}

/** Paramètres d'adresse d'une période (rien pour celle par défaut) */
export function periodQuery(period, fallback = 'today') {
  if (period.key === fallback) return {}
  return period.key === 'custom' ? { periode: 'custom', du: period.from, au: period.to } : { periode: period.key }
}
