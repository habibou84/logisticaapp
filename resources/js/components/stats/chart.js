// Outils communs des graphiques de statistiques (SVG dessinés à la main, sans bibliothèque)

// Couleurs validées (contraste et daltonisme) : séries dans cet ordre, jamais réordonnées
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100']
// Dégradé d'une seule teinte pour les intensités (heures de pointe)
export const BLUES = ['#f1f6fd', '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b']
export const INK = { muted: '#898781', grid: '#e1e0d9' }

// Graduation « ronde » de l'axe : 0 → max arrondi, 4 intervalles
export function niceTicks(max, count = 4) {
  if (!max || max <= 0) return [0, 1]
  const raw = max / count
  const pow = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((s) => s >= raw)
  return Array.from({ length: count + 1 }, (_, i) => Math.round(step * i * 100) / 100)
}

export function compact(value) {
  if (value === null || value === undefined) return '—'
  if (Math.abs(value) >= 1e6) return `${(value / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} M`
  if (Math.abs(value) >= 1e4) return `${Math.round(value / 1e3).toLocaleString('fr-FR')} k`
  return value.toLocaleString('fr-FR')
}

// « 2026-10-14 » → « 14/10 » ; avec le jour de la semaine : « mer. 14/10 »
export function shortDate(iso, withDay = false) {
  const d = new Date(`${iso}T00:00:00`)
  const text = d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  return withDay ? `${d.toLocaleDateString('fr-FR', { weekday: 'short' })} ${text}` : text
}

// Libellés de l'axe des dates : au plus ~7, régulièrement espacés
export function labelIndexes(n, max = 7) {
  if (n <= max) return [...Array(n).keys()]
  const step = Math.ceil((n - 1) / (max - 1))
  const out = []
  for (let i = 0; i < n; i += step) out.push(i)
  if (out.at(-1) !== n - 1) out.push(n - 1)
  return out
}

// Export CSV lisible par Excel (séparateur « ; », accents conservés)
export function downloadCsv(filename, rows) {
  const csv = rows.map((r) => r.map((v) => {
    let text = v === null || v === undefined ? '' : String(v)
    // Un nom qui commence par = + - @ serait lu comme une formule par Excel : on le neutralise
    if (typeof v === 'string' && /^[=+\-@\t\r]/.test(text) && Number.isNaN(Number(text))) text = `'${text}`
    return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
  }).join(';')).join('\n')
  const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
  const a = Object.assign(document.createElement('a'), { href: url, download: filename })
  a.click()
  URL.revokeObjectURL(url)
}
