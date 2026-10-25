/**
 * Guide vidéo « Bien démarrer » : un chapitre par étape essentielle de la mise en route (même clé), filmé sur
 * l'application avec voix off et sous-titres (tests/browser/tutorials). Fichiers dans public/videos/guide.
 */
export const GUIDE_CHAPTERS = [
  { key: 'company', title: 'Votre entreprise' },
  { key: 'zones', title: 'Les zones desservies' },
  { key: 'pricing', title: 'Les tarifs' },
  { key: 'order_rules', title: 'Les règles des courses' },
  { key: 'couriers', title: 'Les livreurs' },
  { key: 'pay', title: 'La paie des livreurs' },
  { key: 'merchants', title: 'Les clients' },
  { key: 'first_delivery', title: 'Une course d\'essai' },
]

export const guideFiles = (key) => ({
  video: `/videos/guide/${key}.mp4`,
  poster: `/videos/guide/${key}.jpg`,
  subtitles: `/videos/guide/${key}.vtt`,
})

export const hasGuideVideo = (key) => GUIDE_CHAPTERS.some((c) => c.key === key)
