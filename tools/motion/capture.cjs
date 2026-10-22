/**
 * Captures d'écran des deux applications (téléphone 390 × 844, échelle 3) pour les vidéos en motion design.
 * Applications locales avec les données de démonstration (voir README.md) :
 *   JIBIAT=http://127.0.0.1:8001 MARCHAND=http://demo.localhost:8000 node tools/motion/capture.cjs
 */
const path = require('path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')

const JIBIAT = process.env.JIBIAT || 'http://127.0.0.1:8001'
const MARCHAND = process.env.MARCHAND || 'http://demo.localhost:8000'
const OUT = path.join(__dirname, 'assets')

// [identifiant, connexion, page]
const SHOTS = [
  ['jibiat-marchand-courses', 'merchant', `${JIBIAT}/marchand/courses`],
  ['jibiat-marchand-argent', 'merchant', `${JIBIAT}/marchand/paiements`],
  ['jibiat-livreur-missions', 'courier', `${JIBIAT}/livreur`],
  ['jibiat-admin-courses', 'admin', `${JIBIAT}/admin/courses`],
  ['marchand-accueil', 'shop', `${MARCHAND}/gestion`],
  ['marchand-commandes', 'shop', `${MARCHAND}/gestion/commandes`],
  ['marchand-entreprises', 'shop', `${MARCHAND}/gestion/livraisons/entreprises`],
  ['marchand-catalogue', 'shop', `${MARCHAND}/gestion/catalogue`],
  ['marchand-boutique', null, `${MARCHAND}/`],
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
  const only = process.argv.slice(2)
  for (const [name, login, url] of SHOTS) {
    if (only.length && !only.includes(name)) continue
    if (!contexts[login ?? 'guest']) {
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
      contexts[login ?? 'guest'] = context
    }
    const page = await contexts[login ?? 'guest'].newPage()
    await page.goto(url)
    await page.waitForLoadState('networkidle').catch(() => {})
    await page.waitForTimeout(1500)
    // Bandeaux d'installation, d'invitation ou de cookies : hors des images
    await page.addStyleTag({ content: '[class*="install" i], .toast, [role="status"]{display:none!important}' }).catch(() => {})
    await page.screenshot({ path: path.join(OUT, `${name}.png`) })
    await page.screenshot({ path: path.join(OUT, `${name}-long.png`), fullPage: true })
    console.log('✓', name)
    await page.close()
  }
  await browser.close()
})()
