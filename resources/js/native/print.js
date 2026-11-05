// Impression d'une page (étiquette, bon de retour) : dans l'application Android, la vue web ignore window.print(),
// le module natif « Print » ouvre l'impression d'Android (imprimante ou « Enregistrer en PDF »).
import { Capacitor, registerPlugin } from '@capacitor/core'

const Print = registerPlugin('Print')

export async function printPage(name = 'Étiquette') {
  if (Capacitor.isNativePlatform() && Capacitor.isPluginAvailable('Print')) {
    await Print.print({ name })
    return
  }
  window.print()
}
