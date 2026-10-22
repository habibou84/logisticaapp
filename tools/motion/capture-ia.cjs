/**
 * Captures des écrans de l'assistant de Jibiat Marchand et des écrans « vendre plus », pour les vidéos.
 * Boutique de démonstration locale (voir README.md), serveur lancé avec une clé factice
 * (ANTHROPIC_API_KEY=demo-local) pour afficher l'assistant. Les réponses de l'assistant sont jouées ici,
 * dans le navigateur : aucun appel n'atteint le serveur, ni Claude.
 *   MARCHAND=http://demo.localhost:8000 node tools/motion/capture-ia.cjs [identifiants…]
 */
const fs = require('fs')
const path = require('path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')

const MARCHAND = process.env.MARCHAND || 'http://demo.localhost:8000'
const OUT = path.join(__dirname, 'assets')
const PHOTO = path.join(__dirname, 'props', 'sac-wax.jpg')

// Message du client, tel qu'il arrive : en plusieurs fois, sans ponctuation
const MESSAGE = 'Bonsoir 🙏\nje veux la robe wax évasée en M bleu\nc pour Yopougon Siporex derrière la pharmacie\nAminata Koné 07 48 52 61 30\nlivrez demain matin svp'
const CLEAN = 'Aminata Koné\n07 48 52 61 30\nYopougon Siporex, derrière la pharmacie\n1 Robe wax évasée M Bleu'

// Réponses jouées de l'assistant (même forme que celles du serveur)
const PRODUCT = {
  title: 'Sac à main en pagne wax',
  description: 'Sac à main en pagne wax aux motifs orange, bleu et vert, avec rabat et anse en simili-cuir marron et fermoir doré. Assez grand pour le téléphone, le portefeuille et les clés : parfait pour le bureau comme pour les cérémonies.',
  brand: null,
  options: [{ name: 'Couleur', values: ['Orange et bleu', 'Vert et jaune'] }],
  tags: ['sac', 'wax', 'pagne', 'fait main', 'cadeau'],
  collection_ids: [],
  seo_title: 'Sac à main en pagne wax – fait main',
  seo_description: 'Sac à main en pagne wax, motifs colorés, anse en simili-cuir. Livraison rapide à Abidjan.',
  to_check: 'Vérifiez les dimensions avant de publier.',
}
const REPLY = {
  reply: 'Bonsoir Awa 😊 Oui, la robe wax évasée bleue est disponible en taille M, à 15 000 F.\nLivraison à Yopougon : 1 500 F, demain matin si vous confirmez ce soir.\nJe vous la réserve ?',
  to_check: null,
}
const CAPTIONS = [
  { text: 'Nouveau 😍 Notre sac en pagne wax, fait main, à 12 000 F seulement.\nLivraison rapide partout à Abidjan.\nCommandez ici : {lien}', hashtags: ['#wax', '#abidjan', '#modeafricaine'] },
  { text: 'Le sac qui va avec toutes vos tenues de cérémonie ✨ 12 000 F.\nStock limité, réservez le vôtre : {lien}', hashtags: ['#pagne', '#tabaski', '#faitmain'] },
  { text: 'Cadeau parfait pour maman ou ta copine 🎁 Sac en wax 12 000 F, livré chez toi.\n{lien}', hashtags: ['#cadeau', '#wax', '#ci225'] },
]
const ANSWER = 'Cette semaine, vous avez vendu 9 robes wax évasées, pour 135 000 F.\nC\'est votre produit le plus vendu, devant le sac en pagne (5).\nÀ surveiller : il ne reste que 2 robes en taille M.'

// [identifiant, page, étapes avant la capture, vues (haut de page, ou titre amené en haut de l'écran) ;
// une vue par capture : identifiant, identifiant-2…]
const SHOTS = [
  ['ia-commande-message', '/gestion/commandes/nouvelle', async (page) => {
    await page.getByText('Coller le message du client').click()
    await page.locator('textarea').first().fill(MESSAGE)
    await page.locator('textarea').first().evaluate((t) => { t.rows = 6 })
  }],
  ['ia-commande-remplie', '/gestion/commandes/nouvelle', async (page) => {
    await page.route('**/api/v1/orders/draft', async (route) => {
      // Analyse réelle (sans IA) du message remis au propre ; Node ne résout pas *.localhost, d'où l'en-tête Host
      const url = new URL(route.request().url().replace('/orders/draft', '/orders/parse'))
      const headers = { ...route.request().headers(), 'content-type': 'application/json', host: url.host }
      url.hostname = '127.0.0.1'
      const response = await route.fetch({ url: url.toString(), method: 'POST', postData: JSON.stringify({ text: CLEAN }), headers })
      // Ce que l'assistant comprend en plus des règles : le nom et la consigne de livraison
      const json = await response.json()
      Object.assign(json.data, { customer_name: 'Aminata Koné', note: 'Livrer demain matin' })
      await route.fulfill({ json })
    })
    await page.getByText('Coller le message du client').click()
    await page.locator('textarea').first().fill(MESSAGE)
    await page.getByRole('button', { name: /Analyser avec l.assistant/ }).click()
    await page.waitForTimeout(1500)
    await page.getByText('Coller le message du client').click()
  }, [0, 640, 1300]],
  ['ia-produit-photo', '/gestion/catalogue/nouveau', async (page) => {
    await page.locator('input[type=file][capture]').first().setInputFiles(PHOTO)
    await page.locator('input[placeholder^="Précision"]').fill('Sac fait main, 2 couleurs')
  }],
  ['ia-produit-fiche', '/gestion/catalogue/nouveau', async (page) => {
    await page.route('**/api/v1/products/draft', (route) => route.fulfill({ json: { data: PRODUCT } }))
    await page.locator('input[type=file][capture]').first().setInputFiles(PHOTO)
    await page.getByRole('button', { name: 'Proposer la fiche' }).click()
    await page.waitForTimeout(1200)
  }, [0, 'Photos']],
  ['ia-reponse', '/gestion/messages/1', async (page) => {
    await page.route('**/api/v1/conversations/*/suggest', (route) => route.fulfill({ json: { data: REPLY } }))
    await hide(page, 'Les messages des clients arrivent ici quand')
    await page.getByRole('button', { name: /Proposer une réponse/ }).click()
    await page.waitForTimeout(1200)
    await page.locator('textarea').last().evaluate((t) => { t.style.height = '210px' })
  }],
  ['ia-reponse-avant', '/gestion/messages/1', (page) => hide(page, 'Les messages des clients arrivent ici quand')],
  ['ia-reseaux', '/gestion/catalogue/2', async (page) => {
    await page.route('**/api/v1/products/*/social/captions', (route) => route.fulfill({ json: { data: CAPTIONS } }))
    await page.getByRole('button', { name: /Publier sur les réseaux|Réseaux sociaux|Publier/ }).first().click()
    await page.waitForTimeout(800)
    await hide(page, 'Aucun réseau connecté')
    await page.getByRole('button', { name: 'Proposer' }).click()
    await page.waitForTimeout(1200)
  }],
  ['ia-chiffres', '/gestion/statistiques', async (page) => {
    await page.route('**/api/v1/stats/ask', (route) => route.fulfill({ json: { data: { answer: ANSWER } } }))
    await page.locator('input[placeholder^="Ex. Combien"]').fill('Quel produit je vends le plus cette semaine ?')
    await page.getByRole('button', { name: 'Demander' }).click()
    await page.waitForTimeout(1200)
  }],
  ['vendre-reseaux', '/gestion/marketing/reseaux', async () => {}, ['Ce que rapporte chaque réseau']],
  ['vendre-conseils', '/gestion', async () => {}, ['Conseils du jour']],
  ['vendre-abonnement', '/gestion/abonnement', async () => {}, [0, 'Pro']],
]

// Éléments dont on note la position (coordonnées de l'écran 390 × 844) pour les cadres et les touchers
const TEXTS = {
  'ia-commande-message': ['css:textarea', 'Analyser avec l\'assistant'],
  'ia-commande-remplie': ['Trouvé :', 'Téléphone *'],
  'ia-commande-remplie-2': ['Votre zone Abidjan', 'Quartier / commune', 'Nom du client'],
  'ia-commande-remplie-3': ['Robe wax évasée', 'Créer la commande', 'Frais de livraison'],
  'ia-produit-photo': ['Remplir la fiche avec une photo', 'Proposer la fiche', 'photo(s) pour l\'assistant'],
  'ia-produit-fiche': ['Fiche proposée', 'Nom du produit', 'Description'],
  'ia-produit-fiche-2': ['Photos', 'Couleur', 'Prix et variantes'],
  'ia-reponse-avant': ['Et livraison à Yopougon', 'Proposer une réponse'],
  'ia-reponse': ['css:textarea', 'Envoyer', 'Bonsoir 🙏 la robe'],
  'ia-reseaux': ['Textes proposés', 'Nouveau 😍', 'Le sac qui va'],
  'ia-chiffres': ['Posez une question sur vos chiffres', 'Cette semaine, vous avez vendu', 'Demander'],
  'vendre-reseaux': ['64 500 F', '40 000 F', '25 500 F', 'Ce que rapporte chaque réseau'],
  'vendre-conseils': ['Conseils du jour', 'Commandes à confirmer'],
  'vendre-abonnement': ['Essai gratuit jusqu', 'Offre Pro', 'actions de l\'assistant IA par mois'],
  'vendre-abonnement-2': ['15 000 F', 'Statistiques avancées'],
}

// Bandeau sans intérêt pour la vidéo (réglage pas encore fait dans la boutique de démonstration)
function hide(page, text) {
  return page.evaluate((t) => {
    const el = [...document.querySelectorAll('div, p, section')].filter((e) => e.textContent.trim().startsWith(t)).sort((a, b) => a.textContent.length - b.textContent.length)[0]
    if (el) el.style.display = 'none'
  }, text)
}

;(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, locale: 'fr-FR', reducedMotion: 'reduce' })
  const login = await context.newPage()
  await login.goto(`${MARCHAND}/gestion/connexion`)
  await login.fill('input[type="tel"], input[autocomplete="username"]', '0700000001')
  await login.fill('input[type="password"]', 'password')
  await login.locator('form').first().locator('button').last().click()
  await login.waitForTimeout(2500)
  await login.close()

  const only = process.argv.slice(2)
  const POS = path.join(OUT, 'positions-ia.json')
  const positions = fs.existsSync(POS) ? JSON.parse(fs.readFileSync(POS, 'utf8')) : {}
  for (const [name, url, steps, views] of SHOTS) {
    if (only.length && !only.includes(name)) continue
    const page = await context.newPage()
    try {
      await page.goto(MARCHAND + url)
      await page.waitForLoadState('networkidle').catch(() => {})
      await page.waitForTimeout(1200)
      await page.addStyleTag({ content: '[class*="install" i], .toast, [role="status"]{display:none!important}' }).catch(() => {})
      await steps(page)
      await page.waitForTimeout(500)
      for (const [i, view] of (views ?? [0]).entries()) {
        await page.evaluate((text) => {
          if (!text || typeof text === 'number') return window.scrollTo(0, text || 0)
          // Titre de section (le plus court des éléments qui contiennent exactement ce texte)
          const all = [...document.querySelectorAll('h1, h2, h3, p, span, div')].filter((e) => e.textContent.trim().endsWith(text) && e.offsetParent)
          const target = all.sort((a, b) => a.textContent.length - b.textContent.length)[0]
          if (target) window.scrollTo(0, target.getBoundingClientRect().top + window.scrollY - 80)
        }, view)
        await page.waitForTimeout(400)
        const file = i ? `${name}-${i + 1}` : name
        await page.screenshot({ path: path.join(OUT, `${file}.png`) })
        if (TEXTS[file]) positions[file] = await page.evaluate((texts) => Object.fromEntries(texts.map((t) => {
          const el = t.startsWith('css:') ? [...document.querySelectorAll(t.slice(4))].filter((e) => e.offsetParent).pop()
            : [...document.querySelectorAll('body *')].filter((e) => e.offsetParent && e.textContent.trim().startsWith(t)).sort((a, b) => a.textContent.length - b.textContent.length)[0]
          if (!el) return [t, null]
          const r = (e) => { const b = e.getBoundingClientRect(); return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) } }
          const box = el.closest('.card, .rounded-lg, .rounded-xl, button, label, a') ?? el
          return [t, { text: r(el), box: r(box) }]
        })), TEXTS[file])
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
