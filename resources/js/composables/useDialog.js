import { reactive } from 'vue'

/**
 * Fenêtres de l'application à la place de celles du navigateur (confirm, prompt) : même usage, mais avec « await ».
 *   if (!(await confirmDialog('Supprimer ce contrat ?'))) return
 *   const reason = await promptDialog('Motif (facultatif) :')   // null si la personne annule
 * Une seule fenêtre à la fois, affichée par AppDialog (dans App.vue).
 */
export const dialogState = reactive({ open: false, kind: 'confirm', title: '', message: '', value: '', okLabel: '', danger: false, multiline: false, resolve: null })

// Actions qui détruisent ou annulent : bouton rouge
const DANGER = /^(supprimer|retirer|annuler|révoquer|déconnecter|désactiver|suspendre|refuser|effacer|oublier|délier|terminer|arrêter|clôturer|basculer)/i

function open(kind, message, options) {
  // Fenêtre déjà ouverte : la précédente est refermée comme annulée
  if (dialogState.open && dialogState.resolve) dialogState.resolve(kind === 'confirm' ? false : null)
  const text = String(message ?? '')
  // Première phrase en titre, le reste en explication (« Supprimer X ? Ses tarifs aussi. »)
  const split = options.title ? null : text.match(/^([^\n?]{1,90}\?)\s+([\s\S]+)$/)
  return new Promise((resolve) => {
    Object.assign(dialogState, {
      open: true,
      kind,
      title: options.title ?? (split ? split[1] : (kind === 'confirm' ? text : '')),
      message: options.title ? text : (split ? split[2] : (kind === 'confirm' ? '' : text)),
      value: options.value ?? '',
      // « Supprimer la zone ? » → bouton « Supprimer »
      okLabel: options.okLabel ?? (kind === 'confirm' ? (text.trim().match(DANGER)?.[1] ?? '').replace(/^./, (c) => c.toUpperCase()) : ''),
      danger: options.danger ?? DANGER.test(text.trim()),
      multiline: !!options.multiline,
      resolve,
    })
  })
}

/** Oui / non : renvoie true si la personne confirme */
export function confirmDialog(message, options = {}) {
  return open('confirm', message, options)
}

/** Texte à saisir : renvoie le texte (éventuellement vide), ou null si la personne annule */
export function promptDialog(message, value = '', options = {}) {
  return open('prompt', message, { ...options, value: value ?? '' })
}

export function closeDialog(answer) {
  const resolve = dialogState.resolve
  dialogState.open = false
  dialogState.resolve = null
  resolve?.(answer)
}
