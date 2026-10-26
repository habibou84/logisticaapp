/**
 * Familles des motifs (miroir de App\Models\IncidentReason::FAMILIES) et filtre par étape et par activité.
 */
export const REASON_FAMILIES = {
  unavailable: { label: 'Indisponible', icon: '🚪' },
  postponed: { label: 'Report', icon: '📅' },
  address: { label: 'Adresse, contact', icon: '📍' },
  documents: { label: 'Documents', icon: '📄' },
  recipient: { label: 'Destinataire', icon: '🏢' },
  pickup: { label: 'Ramassage', icon: '📦' },
  other: { label: 'Autre', icon: '✏️' },
}

/** Motifs proposés pour une étape (pickup, delivery) et l'activité de la course */
export function reasonsFor(reasons, stage, activity) {
  return reasons.filter((r) => (r.applies_to === 'both' || r.applies_to === stage)
    && (!r.activities?.length || !activity || r.activities.includes(activity)))
}

/** Effet d'un motif sur la fiche de l'annuaire (miroir de IncidentReason::DESTINATION_EFFECTS) */
export const DESTINATION_EFFECTS = {
  inactive: 'Fiche désactivée',
  check_name: 'Nom à vérifier',
  check_address: 'Adresse à vérifier',
  check_phone: 'Numéro à vérifier',
}
