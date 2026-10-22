// Entrée des applications Android et iOS : les mêmes écrans que le site (resources/js)
import '../resources/css/app.css'
import { App as NativeShell } from '@capacitor/app'
import '../resources/js/app.js'

// Bouton retour d'Android : page précédente, ou sortie de l'application depuis l'accueil
NativeShell.addListener('backButton', ({ canGoBack }) => {
  if (canGoBack) window.history.back()
  else NativeShell.exitApp()
})
