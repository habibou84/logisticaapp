import { setCachedUser, setToken } from './session'

// Session ouverte depuis l'adresse de la console : assistance (#support=…) ou fin de l'inscription
// d'une entreprise (#session=…). Le jeton arrive dans le fragment, jamais envoyé au serveur. Il est
// gardé puis retiré de l'adresse AVANT la création du routeur, qui sinon le remettrait dans
// l'adresse (et l'historique).
const handoff = window.location.hash.match(/^#(support|session)=(.+)$/)
if (handoff) {
  try {
    // Rangée dans l'espace de la page ouverte (administration de l'entreprise)
    setToken(decodeURIComponent(handoff[2]))
    setCachedUser(null)
  } catch {
    // stockage indisponible : la session ne peut pas être ouverte dans ce navigateur
  }
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
}
