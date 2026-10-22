// Parcours d'achat sur la boutique de démonstration de Jibiat Marchand, captures 390 × 844 pour la vidéo marchand-boutique,
// puis la commande reçue dans la gestion. Positions dans assets/positions-boutique.json.
//   BOUTIQUE=http://demo.localhost:8000 node tools/motion/capture-boutique.cjs
const { chromium } = require('playwright')
const fs = require('fs')
const OUT = require('path').join(__dirname, 'assets')
const S = process.env.BOUTIQUE || 'http://demo.localhost:8000'
const pos = {}
const box = async (p, sel) => { const b = await p.locator(sel).first().boundingBox({ timeout: 3000 }).catch(() => null); return b && { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) } }
;(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  const c = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, locale: 'fr-FR', reducedMotion: 'reduce' })
  const p = await c.newPage()
  const shot = async (name, marks = {}) => {
    await p.waitForTimeout(500)
    await p.screenshot({ path: `${OUT}/${name}.png` })
    pos[name] = {}
    for (const [k, sel] of Object.entries(marks)) pos[name][k] = await box(p, sel)
    console.log('✓', name, JSON.stringify(pos[name]))
  }
  const hideWa = () => p.addStyleTag({ content: 'a[href*="wa.me"].fixed, .fixed a[href*="wa.me"], [class*="fixed"][href*="wa.me"]{display:none!important}' })
  // Accueil : image d'en-tête et nouveautés
  await p.goto(S + '/'); await p.waitForTimeout(1500); await hideWa()
  await shot('bs-accueil', { bouton: 'text=Voir la boutique' })
  await p.evaluate(() => { const h = [...document.querySelectorAll('h2')].find((e) => e.textContent.includes('Nouveautés')); window.scrollTo(0, h.getBoundingClientRect().top + scrollY - 80) })
  await shot('bs-nouveautes', { titre: 'h2:has-text("Nouveautés")' })
  // Fiche produit
  await p.goto(S + '/produits/robe-wax-evasee'); await p.waitForTimeout(1500); await hideWa()
  await p.evaluate(() => window.scrollTo(0, 40))
  await shot('bs-produit', { prix: 'text=15 000 F' })
  await p.evaluate(() => { const h = [...document.querySelectorAll('h1')][0]; window.scrollTo(0, h.getBoundingClientRect().top + scrollY - 60) })
  await p.getByRole('button', { name: 'Ajouter au panier' }).click(); await p.waitForTimeout(900)
  await shot('bs-ajout', { ajouter: 'button:has-text("Ajouter au panier")', panier: 'text=Ajouté au panier' })
  // Panier
  await p.goto(S + '/panier'); await p.waitForTimeout(1500); await hideWa()
  await shot('bs-panier', { commander: 'text=Commander · paiement à la livraison' })
  // Commande : coordonnées et quartier
  await p.goto(S + '/commande'); await p.waitForTimeout(1500); await hideWa()
  await p.fill('#name', 'Aminata Koné'); await p.fill('#phone', '07 48 52 61 30')
  await p.fill('#place', 'Cocody Ang'); await p.waitForTimeout(1500)
  const opt = p.locator('[role="option"], li:has-text("Angré"), button:has-text("Angré")').first()
  if (await opt.count()) await opt.click(); else await p.fill('#place', 'Cocody Angré')
  await p.waitForTimeout(800)
  await p.fill('#address', 'Angré 8e tranche, villa 12'); await p.fill('#landmark', 'Derrière la pharmacie Les Oscars')
  await p.evaluate(() => window.scrollTo(0, 120))
  await shot('bs-commande', { coordonnees: '#name', quartier: '#place' })
  await p.evaluate(() => { const h = [...document.querySelectorAll('h2, h3')].find((e) => e.textContent.includes('Récapitulatif')); window.scrollTo(0, h.getBoundingClientRect().top + scrollY - 40) })
  await shot('bs-recap', { total: 'text=Total', confirmer: 'button:has-text("Confirmer la commande")' })
  await p.getByRole('button', { name: 'Confirmer la commande' }).click(); await p.waitForTimeout(3000); await hideWa()
  await shot('bs-merci', {})
  console.log('URL', p.url())
  // Côté marchand : la commande arrive dans la gestion
  await p.goto(S + '/gestion/connexion')
  await p.fill('input[type="tel"]', '0700000001'); await p.fill('input[type="password"]', 'password')
  await p.locator('form').first().locator('button').last().click(); await p.waitForTimeout(2500)
  await p.goto(S + '/gestion/commandes'); await p.waitForTimeout(2500)
  await p.addStyleTag({ content: '[class*="install" i], .toast, [role="status"]{display:none!important}' })
  await shot('bs-gestion', { commande: 'text=Aminata Koné' })
  fs.writeFileSync(`${OUT}/positions-boutique.json`, JSON.stringify(pos, null, 1))
  await b.close()
})()
