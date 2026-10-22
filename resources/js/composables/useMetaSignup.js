// Bouton « Connecter WhatsApp » : inscription intégrée de Meta (Embedded Signup).
// La fenêtre de Meta laisse l'entreprise se connecter à Facebook, choisir ou créer son compte
// WhatsApp Business et confirmer son numéro ; elle renvoie un code d'autorisation (à échanger
// côté serveur) et les identifiants du numéro et du compte.

let sdk = null

function loadSdk(appId, version) {
  if (sdk) return sdk
  sdk = new Promise((resolve, reject) => {
    window.fbAsyncInit = () => {
      window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version })
      resolve(window.FB)
    }
    const script = document.createElement('script')
    script.src = 'https://connect.facebook.net/fr_FR/sdk.js'
    script.async = true
    script.defer = true
    script.crossOrigin = 'anonymous'
    script.onerror = () => {
      sdk = null
      reject(new Error('Impossible de charger Facebook. Vérifiez votre connexion ou désactivez le bloqueur de publicités.'))
    }
    document.body.appendChild(script)
  })
  return sdk
}

function fromFacebook(origin) {
  try {
    return /(^|\.)facebook\.com$/.test(new URL(origin).hostname)
  } catch {
    return false
  }
}

/**
 * Ouvre la fenêtre de Meta. Résout { code, phone_number_id, waba_id } ;
 * rejette avec un message lisible (fenêtre fermée, erreur, aucun numéro choisi).
 */
export async function connectWhatsApp({ app_id: appId, config_id: configId, graph_version: version }) {
  const FB = await loadSdk(appId, version)

  return new Promise((resolve, reject) => {
    let session = null
    let code = null
    let settled = false

    const settle = (fn, value) => {
      if (settled) return
      settled = true
      window.removeEventListener('message', onMessage)
      fn(value)
    }
    const finish = () => {
      if (code && session) settle(resolve, { code, ...session })
    }

    // Numéro et compte choisis dans la fenêtre de Meta
    function onMessage(event) {
      if (!fromFacebook(event.origin)) return
      let data
      try {
        data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
      } catch {
        return
      }
      if (data?.type !== 'WA_EMBEDDED_SIGNUP') return

      if (data.event === 'FINISH') {
        session = { phone_number_id: String(data.data.phone_number_id), waba_id: String(data.data.waba_id) }
        finish()
      } else if (data.event === 'CANCEL') {
        settle(reject, new Error(data.data?.error_message || 'Connexion interrompue avant la fin : recommencez quand vous voulez.'))
      } else if (data.event === 'FINISH_ONLY_WABA') {
        settle(reject, new Error('Aucun numéro n\'a été choisi : recommencez et ajoutez le numéro WhatsApp de l\'entreprise.'))
      }
    }
    window.addEventListener('message', onMessage)

    FB.login((response) => {
      code = response?.authResponse?.code || null
      if (!code) {
        settle(reject, new Error('Connexion annulée.'))
        return
      }
      finish()
      // Le numéro arrive normalement avant ; sinon la fenêtre a été fermée trop tôt
      setTimeout(() => settle(reject, new Error('Meta n\'a pas indiqué le numéro choisi : recommencez la connexion.')), 8000)
    }, {
      config_id: configId,
      response_type: 'code',
      override_default_response_type: true,
      extras: { setup: {}, featureType: '', sessionInfoVersion: '3' },
    })
  })
}
