/**
 * Position des éléments à montrer dans les captures (coordonnées 390 × 844 de la page, défilement compris) :
 *   node tools/motion/probe.cjs > tools/motion/assets/positions.json
 */
const path = require('path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')
const JIBIAT = process.env.JIBIAT || 'http://127.0.0.1:8001'
const MARCHAND = process.env.MARCHAND || 'http://demo.localhost:8000'

const TARGETS = [
  ['marchand-commandes', 'shop', `${MARCHAND}/gestion/commandes`, ['Mariam Traoré', 'Faire livrer', 'À encaisser']],
  ['marchand-accueil', 'shop', `${MARCHAND}/gestion`, ['Ventes du jour', 'Ventes des 7 derniers jours']],
  ['marchand-entreprises', 'shop', `${MARCHAND}/gestion/livraisons/entreprises`, ['Trouver une entreprise de livraison', 'Livraison Express CI', 'Réussite', 'Connecter', 'colis livrés en 90 jours']],
  ['jibiat-admin-courses', 'admin', `${JIBIAT}/admin/courses`, ['Christelle Aka']],
  ['jibiat-livreur-missions', 'courier', `${JIBIAT}/livreur`, ['Boutique Chic Abidjan', 'Je suis en service']],
  ['jibiat-marchand-courses', 'merchant', `${JIBIAT}/marchand/courses`, ['Livrée à', 'Un livreur vient']],
  ['jibiat-marchand-argent', 'merchant', `${JIBIAT}/marchand/paiements`, ['On vous doit', 'Payé le 17/10/2026']],
]
const LOGINS = {
  merchant: { url: `${JIBIAT}/`, phone: '0500000001' },
  courier: { url: `${JIBIAT}/livreur/connexion`, phone: '0700000004' },
  admin: { url: `${JIBIAT}/admin/connexion`, phone: '0700000001' },
  shop: { url: `${MARCHAND}/gestion/connexion`, phone: '0700000001' },
}

;(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  const contexts = {}
  const result = {}
  for (const [name, login, url, texts] of TARGETS) {
    if (!contexts[login]) {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, locale: 'fr-FR' })
      const page = await context.newPage()
      await page.goto(LOGINS[login].url)
      await page.fill('input[type="tel"], input[autocomplete="username"]', LOGINS[login].phone)
      await page.fill('input[type="password"]', 'password')
      await page.locator('form').first().locator('button').last().click()
      await page.waitForTimeout(2500)
      await page.close()
      contexts[login] = context
    }
    const page = await contexts[login].newPage()
    await page.goto(url)
    await page.waitForLoadState('networkidle').catch(() => {})
    await page.waitForTimeout(1500)
    result[name] = await page.evaluate((texts) => {
      const out = {}
      const round = (r) => ({ x: Math.round(r.x), y: Math.round(r.y + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) })
      for (const text of texts) {
        const all = [...document.querySelectorAll('body *')].filter((n) => n.children.length === 0 && n.textContent.includes(text) && n.getClientRects().length)
        const node = all[0] ?? [...document.querySelectorAll('body *')].find((n) => n.textContent.includes(text) && n.getClientRects().length && [...n.children].every((c) => !c.textContent.includes(text)))
        if (!node) { out[text] = null; continue }
        const box = node.closest('article, li, .card, .m-card, button, a, section, [class*="rounded-2xl"], [class*="rounded-xl"]') ?? node
        out[text] = { text: round(node.getBoundingClientRect()), box: round(box.getBoundingClientRect()) }
      }
      return out
    }, texts)
    await page.close()
  }
  console.log(JSON.stringify(result, null, 1))
  await browser.close()
})()
