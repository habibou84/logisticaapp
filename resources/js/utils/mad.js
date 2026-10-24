const DAYS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim']

/** « Réservé à Ivoire Assurances lun–ven 08:00–17:00, jusqu'au 31/12 » */
export function madTitle(mad) {
  if (!mad) return ''
  const days = mad.days.length === 7 ? 'tous les jours' : mad.days.map((d) => DAYS[d - 1]).join(', ')
  const hours = mad.from ? ` ${mad.from}–${mad.to}` : ''
  const until = mad.ends_on ? `, jusqu'au ${mad.ends_on.slice(8, 10)}/${mad.ends_on.slice(5, 7)}` : ''
  const state = mad.reserved_now ? (mad.exclusive ? 'en ce moment, réservé à' : 'en ce moment, chez') : 'pas en ce moment ; MAD chez'
  return `MAD ${state} ${mad.merchant} (${days}${hours}${until})`
}

/** Texte court pour une liste déroulante de livreurs */
export const madSuffix = (c) => (c?.mad ? ` · MAD ${c.mad.merchant}${c.mad.reserved_now && c.mad.exclusive ? ' (réservé)' : ''}` : '')
