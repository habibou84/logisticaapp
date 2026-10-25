// Annuaire des destinations : quand le proposer, et comment la destination choisie remplit la fiche

export function usesDirectory(company, activity, isMerchant) {
  const s = company?.destination_settings
  return !!s?.enabled && (s.activities || []).includes(activity) && (!isMerchant || s.clients_see)
}

const local = (p) => (p ? p.replace(/^\+225/, '').replace(/(\d{2})(?=\d)/g, '$1 ') : '')

// Remplit la fiche ; un numéro « à vérifier » n'est jamais repris
export function applyDestination(form, d) {
  Object.assign(form, {
    destination_id: d.id,
    recipient_name: d.label,
    recipient_phone: local(d.phone),
    recipient_phone2: local(d.phone2),
    delivery_zone_id: d.zone_id ?? form.delivery_zone_id,
    delivery_address: [d.district, d.address, d.landmark].filter(Boolean).join(', '),
  })
}

export function clearDestination(form) {
  Object.assign(form, { destination_id: null, recipient_name: '', recipient_phone: '', recipient_phone2: '', delivery_address: '' })
}
