/**
 * Familles des motifs (miroir de App\Models\IncidentReason::FAMILIES) et filtre par étape et par activité.
 */
export const REASON_FAMILIES = {
  info: { label: 'Consigne du destinataire (la course continue)', icon: 'note' },
  postponed: { label: 'Autre date (report)', icon: 'date' },
  unavailable: { label: 'Destinataire injoignable ou absent', icon: 'absent' },
  address: { label: 'Adresse, téléphone', icon: 'adresse' },
  recipient: { label: 'Refus, annulation, litige', icon: 'entreprise' },
  documents: { label: 'Documents', icon: 'document' },
  pickup: { label: 'Ramassage', icon: 'course' },
  other: { label: 'Problème grave, autre', icon: 'alerte' },
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

/** Report ou retour du livreur validé par l'équipe ou le client avant de prendre effet (miroir de IncidentReason::validates) */
export function reasonValidates(r) {
  return !!r && r.needs_validation && r.effect !== 'note' && (r.requires_date || r.triggers_return)
}
