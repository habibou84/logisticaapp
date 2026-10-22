// Choix proposés dans les réglages (pages et résumés de la page Réglages)
export const VALIDATION_MODES = [
  { value: 'manual', label: 'Manuelle', short: 'manuelle', help: 'Chaque course attend la validation d\'un dispatcher (file « À valider »).' },
  { value: 'auto', label: 'Automatique', short: 'automatique', help: 'Toutes les courses sont validées dès leur enregistrement.' },
  {
    value: 'rules',
    label: 'Automatique avec règles',
    short: 'automatique avec règles',
    help: 'Validées dès leur enregistrement ; après l\'heure limite du jour, ou quand le dispatch ferme la journée, les courses du jour sont validées pour le lendemain (ramassage le jour même) et le marchand est prévenu.',
  },
]

export const CUSTODY_MODES = [
  { value: 'choose', label: 'Au choix du dispatch', help: 'Le dispatch choisit à chaque mission : garde ou dépôt.' },
  { value: 'keep', label: 'Restent chez le livreur', help: 'Il les garde jusqu\'à leur jour de livraison, qui lui est alors confiée automatiquement.' },
  { value: 'depot', label: 'Reviennent au dépôt', help: 'Il les rapporte au dépôt le soir même ; n\'importe quel livreur pourra les livrer.' },
]

// Assignation automatique selon le planning
export const AUTO_MODES = [
  { value: 'off', label: 'Désactivée', short: 'désactivée', help: 'L\'équipe confie chaque course, comme avant.' },
  { value: 'trial', label: 'À l\'essai', short: 'à l\'essai', help: 'Rien n\'est envoyé : le choix du planning est noté sur chaque course, pour vérifier.' },
  { value: 'on', label: 'Active', short: 'active', help: 'Les courses validées vont au livreur du planning, ou à son remplaçant.' },
]

// Livraison du jour même confiée automatiquement
export const DELIVERY_MODES = [
  { value: 'off', label: 'Désactivée', short: 'désactivée', help: 'L\'équipe confie chaque livraison.' },
  { value: 'trial', label: 'À l\'essai', short: 'à l\'essai', help: 'Rien n\'est envoyé : le livreur qui aurait été choisi est noté sur la course, avec la raison.' },
  { value: 'on', label: 'Active', short: 'active', help: 'Dès que le colis est récupéré ou au dépôt, la livraison du jour part au livreur choisi.' },
]

export const WEEKDAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']

// Acceptation des missions par le livreur
export const ACCEPT_MODES = [
  { value: 'choose', label: 'Le livreur accepte ou refuse', short: 'au choix du livreur', help: 'Chaque mission attend sa réponse. Un refus la rend à l\'équipe (ou au remplaçant du planning).' },
  { value: 'report', label: 'Acceptées d\'office, empêchement possible', short: 'd\'office, empêchement possible', help: 'Pas de réponse à donner. Avant de partir, il peut signaler un empêchement : la mission passe au remplaçant ou revient à l\'équipe.' },
  { value: 'auto', label: 'Acceptées d\'office', short: 'd\'office', help: 'Le livreur ne peut pas refuser. En cas de problème, il appelle l\'agence.' },
]

// « 30 min », « 2 h », « jamais »
export function delay(minutes) {
  if (!minutes) return 'jamais'
  return minutes % 60 === 0 ? `${minutes / 60} h` : `${minutes} min`
}
