import { useAuthStore } from '../stores/auth'

/** Réglages par défaut, comme au serveur (PhotoSettings::DEFAULTS) */
export const PHOTO_DEFAULTS = {
  scan: { delivery_proof: true, receipts: true, returns: true, vehicle: true },
  mode: 'color',
  quality_check: 'block',
  keep_original: true,
  resolution: 'standard',
}

/** Réglages des photos de documents de l'entreprise (Réglages › Courses et livraison › Photos de documents) */
export function photoSettings() {
  const saved = useAuthStore().user?.company?.photo_settings || {}
  return { ...PHOTO_DEFAULTS, ...saved, scan: { ...PHOTO_DEFAULTS.scan, ...(saved.scan || {}) } }
}
