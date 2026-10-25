// Remise par un département du client au ramassage : ce qui est demandé au livreur, et sa vérification

// Le livreur doit-il relever qui lui remet la course ?
export function asksHandover(order) {
  const p = order?.department ? order.department_proof : null
  return !!p && (p.handover_name !== 'off' || p.handover_signature !== 'off')
}

export function blankHandover(order) {
  return { handed_by_name: '', handed_by_role: '', signature: order?.department_proof?.handover_signature === 'required' ? '' : null }
}

// Champs envoyés avec « récupéré », ou une erreur à afficher
export function handoverPayload(order, model) {
  const p = order?.department_proof || {}
  if (p.handover_name === 'required' && !model.handed_by_name.trim()) throw new Error('Indiquez qui vous remet la course.')
  if (p.handover_signature === 'required' && !model.signature) throw new Error('Faites signer la personne qui vous remet la course.')
  const extra = {}
  if (model.handed_by_name.trim()) {
    extra.handed_by_name = model.handed_by_name.trim()
    if (model.handed_by_role.trim()) extra.handed_by_role = model.handed_by_role.trim()
  }
  if (model.signature) extra.signature = model.signature
  return extra
}
