// Départements des clients : libellés des réglages (entreprise et client)

export const LEVELS = { off: 'Jamais', optional: 'Si la personne veut', required: 'Toujours' }
export const INVOICE_MODES = {
  detailed: 'Une facture, ventilée par département',
  split: 'Une facture par département',
  none: 'Une facture, sans détail',
}
export const FORFAIT_SPLITS = {
  prorata: 'Réparti selon les envois de chaque département',
  main: 'Payé par le département principal',
}

// Réglages, dans l'ordre d'affichage : clé, type (bool, level, invoice, forfait), libellé, précision
export const DEPARTMENT_OPTIONS = [
  { group: 'Sur la course', key: 'required', type: 'bool', label: 'Département obligatoire sur chaque course', hint: 'Un employé rattaché à un seul département n\'a pas à le choisir.' },
  { group: 'Sur la course', key: 'requested_by', type: 'level', label: '« Demandé par » (nom de la personne)' },
  { group: 'Sur la course', key: 'internal_mail', type: 'bool', label: 'Courrier interne : course vers un autre département', hint: 'Adresse et responsable du département destinataire remplis tout seuls.' },
  { group: 'Comptes', key: 'staff_own_only', type: 'bool', label: 'Chaque employé ne voit et ne commande que pour ses départements', hint: 'Le gérant du client voit tout.' },
  { group: 'Comptes', key: 'client_manages', type: 'bool', label: 'Le gérant du client gère ses départements et ses employés', hint: 'Dans son application ; sinon seulement l\'agence.' },
  { group: 'Remise', key: 'handover_name', type: 'level', label: 'Au ramassage : nom et fonction de la personne qui remet' },
  { group: 'Remise', key: 'handover_signature', type: 'level', label: 'Au ramassage : signature de la personne qui remet' },
  { group: 'Remise', key: 'received_role', type: 'level', label: 'À la remise : fonction de la personne qui reçoit', hint: 'Le nom, la signature et la photo suivent la preuve de remise (Réglages › Courses).' },
  { group: 'Remise', key: 'slips', type: 'bool', label: 'Bordereau du jour par département', hint: 'Signé dans l\'application ou imprimé.' },
  { group: 'Facture et quotas', key: 'invoice_mode', type: 'invoice', label: 'Facture du mois' },
  { group: 'Facture et quotas', key: 'forfait_split', type: 'forfait', label: 'Forfait du mois' },
  { group: 'Facture et quotas', key: 'quota_alerts', type: 'bool', label: 'Avertir à 80 % et à 100 % du quota d\'un département' },
  { group: 'Facture et quotas', key: 'quota_block', type: 'bool', label: 'Refuser les courses au-delà du quota', hint: 'Pour les comptes du client ; l\'agence peut toujours passer outre.' },
]

export function optionChoices(o) {
  if (o.type === 'level') return LEVELS
  if (o.type === 'invoice') return INVOICE_MODES
  if (o.type === 'forfait') return FORFAIT_SPLITS
  return { true: 'Oui', false: 'Non' }
}

export function optionText(o, value) {
  const choices = optionChoices(o)
  return choices[String(value)] ?? ''
}

// Badge court d'un département sur une course
export function departmentText(d) {
  return d ? (d.code || d.name) : ''
}
