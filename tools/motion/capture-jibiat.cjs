/**
 * Captures des écrans de Jibiat côté entreprise de livraison (administration, livreur, e-commerçant client,
 * suivi, annuaire), pour les vidéos de jibiat.com. Base locale avec la journée de démonstration
 * (php tools/motion/demo-jour.php) ; site public jibiat.com servi en local pour l'annuaire.
 *   JIBIAT=http://127.0.0.1:8001 SITE=http://jibiat.test:8002 node tools/motion/capture-jibiat.cjs [identifiants…]
 * Chaque vue donne une capture (identifiant, identifiant-2…) et la position des éléments à montrer
 * (assets/positions-jibiat.json, coordonnées de l'écran 390 × 844).
 */
const fs = require('fs')
const path = require('path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')

const JIBIAT = process.env.JIBIAT || 'http://127.0.0.1:8001'
const SITE = process.env.SITE || 'http://jibiat.test:8002'
const OUT = path.join(__dirname, 'assets')
const ORDER = { auto: process.env.ORDER_AUTO || '391', route: process.env.ORDER_ROUTE || '387' }
const TRACKING = process.env.TRACKING || 'LV-39PV-3XBJ'

const LOGINS = {
  admin: { url: `${JIBIAT}/admin/connexion`, phone: '0700000001' },
  courier: { url: `${JIBIAT}/livreur/connexion`, phone: '0700000004' },
  merchant: { url: `${JIBIAT}/`, phone: '0500000001' },
}

const MESSAGE = 'Bonsoir, une livraison pour Mariam Touré 07 58 41 22 90 à Cocody Angré 8e tranche, 2 robes 15 000 F à encaisser'

// [identifiant, connexion, page, gestes avant la capture, vues, éléments à repérer]
// Vue : 0 (haut), un nombre (défilement en pixels) ou un texte (titre amené en haut de l'écran)
const SHOTS = [
  ['j-planning', 'admin', '/admin/planning', null, [0, 'Livraisons ce jour'], ['Ramassage automatique', 'Moussa Coulibaly', 'Serge Kouassi', 'Clients sans ramasseur', 'Yacouba Ouattara', 'Koffi Livreur']],
  ['j-course-auto', 'admin', `/admin/courses/${ORDER.auto}`, null, ['Historique'], ['Historique', 'planning']],
  ['j-alertes', 'admin', '/admin', async (page) => {
    await page.getByRole('button', { name: 'Notifications' }).first().click()
    await page.waitForTimeout(1200)
  }, [0], ['Notifications', 'sans livreur', 'Échec']],
  ['j-courses', 'admin', '/admin/courses', null, [0], ['Décider', 'Échec de livraison', 'À ramasser', 'En retard']],
  ['j-caisse', 'admin', '/admin/caisse', null, [0], ['Argent chez les livreurs', 'Encaissé aujourd', 'Yacouba Ouattara', 'Recevoir le versement']],
  ['j-caisse-versement', 'admin', '/admin/caisse', async (page) => {
    await page.getByRole('button', { name: 'Recevoir le versement' }).first().click()
    await page.waitForTimeout(1000)
  }, [0], ['Recevoir', 'Montant', 'Valider', 'Enregistrer']],
  ['j-colis-livreurs', 'admin', '/admin/colis-livreurs', null, [0], ['Colis chez les livreurs', 'Koffi Livreur', 'Échec']],
  ['j-disponibilites', 'admin', '/admin/disponibilites', null, [0], ['Livreurs libres', 'Libre']],
  ['j-course-route', 'admin', `/admin/courses/${ORDER.route}`, null, [0, 'Historique'], ['En chemin', 'Livreur', 'Historique']],
  ['j-import', 'admin', '/admin/courses/import', null, [0], ['Importer des courses', 'Télécharger le modèle']],
  ['j-integrations', 'admin', '/admin/integrations', null, [0], ['Jibiat Marchand', 'Clés API']],
  ['j-demandes', 'admin', '/admin/marchands', null, [0], ['nouveau client potentiel', 'Awa Mode', 'Accepter', 'via Jibiat Marchand']],
  ['j-reglages-annuaire', 'admin', '/admin/reglages/e-commercants', null, ['Annuaire des entreprises'], ['Annuaire des entreprises', 'Figurer dans l', 'Votre présentation']],
  ['j-stats', 'admin', '/admin/statistiques', null, [0, 'Taux de livraison'], ['Courses créées', 'Taux de livraison', 'Chiffre d', 'Encaissé pour']],
  ['j-livreur', 'courier', '/livreur', null, [0], ['Je suis en service', 'À verser à la caisse', 'Ramasser', 'Livrer']],
  ['j-livreur-caisse', 'courier', '/livreur/caisse', null, [0], ['À verser à la caisse', 'Gagné aujourd', 'Colis à rapporter']],
  ['j-ecommercant-message', 'merchant', '/marchand/courses/nouvelle', async (page) => {
    await page.getByText(/Coller un message/).first().click()
    await page.waitForTimeout(600)
    await page.locator('textarea').first().fill(MESSAGE)
  }, [0], ['Coller un message', 'css:textarea']],
  ['j-ecommercant-courses', 'merchant', '/marchand/courses', null, [0], ['Mes courses', 'Aujourd']],
  ['j-suivi', null, `/suivi/${TRACKING}`, null, [0], ['Suivi de colis', 'En chemin', 'Récupéré', 'Votre livreur']],
  ['j-annuaire', null, `${SITE}/annuaire`, null, ['Livraison Express CI'], ['Livraison Express CI', 'Réussite', 'livrés']],
]

// Défilement de la page (fenêtre ou zone qui défile dans l'application)
function scroller() {
  const all = [document.scrollingElement, ...document.querySelectorAll('main, [class*="overflow-y-auto"], [class*="overflow-auto"]')]
  return all.filter(Boolean).sort((a, b) => (b.scrollHeight - b.clientHeight) - (a.scrollHeight - a.clientHeight))[0]
}

;(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--host-resolver-rules=MAP jibiat.test 127.0.0.1, MAP *.jibiat.test 127.0.0.1'],
  })
  const contexts = {}
  const only = process.argv.slice(2)
  const POS = path.join(OUT, 'positions-jibiat.json')
  const positions = fs.existsSync(POS) ? JSON.parse(fs.readFileSync(POS, 'utf8')) : {}

  for (const [name, login, url, steps, views, texts] of SHOTS) {
    if (only.length && !only.includes(name)) continue
    const key = login ?? 'public'
    if (!contexts[key]) {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, locale: 'fr-FR', reducedMotion: 'reduce' })
      if (login) {
        const page = await context.newPage()
        await page.goto(LOGINS[login].url)
        await page.fill('input[type="tel"], input[autocomplete="username"]', LOGINS[login].phone)
        await page.fill('input[type="password"]', 'password')
        await page.locator('form').first().locator('button').last().click()
        await page.waitForTimeout(2500)
        await page.close()
      }
      contexts[key] = context
    }
    const page = await contexts[key].newPage()
    try {
      await page.goto(url.startsWith('http') ? url : JIBIAT + url)
      await page.waitForLoadState('networkidle').catch(() => {})
      await page.waitForTimeout(1500)
      // Bandeaux sans intérêt pour la vidéo (installation, mise en route de l'entreprise de démonstration)
      await page.addStyleTag({ content: '[class*="install" i], .toast, [role="status"]{display:none!important}' }).catch(() => {})
      await page.evaluate(() => {
        const el = [...document.querySelectorAll('div, p, a')].filter((e) => e.textContent.trim().startsWith('🧭 Mise en route') || e.textContent.trim().startsWith('Mise en route :'))
          .sort((a, b) => a.textContent.length - b.textContent.length)[0]
        if (el) (el.closest('[class*="bg-amber"], [class*="bg-yellow"]') ?? el).style.display = 'none'
      })
      if (steps) await steps(page)
      for (const [i, view] of views.entries()) {
        await page.evaluate(([v, find]) => {
          const box = new Function(`return (${find})`)()()
          if (typeof v === 'number') return box.scrollTo(0, v)
          const t = [...document.querySelectorAll('h1, h2, h3, p, span, div, a')].filter((e) => e.offsetParent && e.textContent.trim().startsWith(v))
            .sort((a, b) => a.textContent.length - b.textContent.length)[0]
          if (t) box.scrollTo(0, t.getBoundingClientRect().top + box.scrollTop - 90)
        }, [view, scroller.toString()])
        await page.waitForTimeout(500)
        const file = i ? `${name}-${i + 1}` : name
        await page.screenshot({ path: path.join(OUT, `${file}.png`) })
        positions[file] = await page.evaluate((list) => Object.fromEntries(list.map((t) => {
          const el = t.startsWith('css:') ? [...document.querySelectorAll(t.slice(4))].filter((e) => e.offsetParent)[0]
            : [...document.querySelectorAll('body *')].filter((e) => e.offsetParent && e.textContent.trim().includes(t)).sort((a, b) => a.textContent.length - b.textContent.length)[0]
          if (!el) return [t, null]
          const r = (e) => { const b = e.getBoundingClientRect(); return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) } }
          return [t, { text: r(el), box: r(el.closest('.card, .rounded-lg, .rounded-xl, .rounded-2xl, button, label, a') ?? el) }]
        })), texts)
        console.log('✓', file)
      }
    } catch (e) {
      console.log('✗', name, e.message.split('\n')[0])
    }
    await page.close()
  }
  fs.writeFileSync(POS, JSON.stringify(positions, null, 1))
  await browser.close()
})()
