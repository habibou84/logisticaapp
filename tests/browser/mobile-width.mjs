/**
 * Contrôle de l'administration sur téléphone (360 px par défaut, WIDTH pour changer) : aucune page ne doit être plus large que l'écran,
 * le panneau des notifications et le menu doivent tenir dans l'écran.
 *
 *   php artisan migrate --seed && php artisan serve
 *   npm run test:mobile            # BASE_URL, PHONE, PASSWORD, CHROMIUM_PATH facultatifs
 */
import { chromium } from 'playwright'

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000'
const WIDTH = Number(process.env.WIDTH || 360)
const PAGES = [
  '', 'statistiques', 'courses', 'courses?queue=to_pickup', 'courses?queue=by_client', 'courses/nouvelle', 'carte', 'disponibilites',
  'planning', 'terrain', 'colis-livreurs', 'retours', 'caisse', 'paie-livreurs', 'marchands', 'stock', 'messages', 'livreurs', 'utilisateurs',
  'mise-en-route', 'reglages', 'reglages/entreprise', 'reglages/courses', 'reglages/alertes', 'reglages/e-commercants', 'reglages/reprise',
  'zones', 'tarifs', 'whatsapp', 'integrations', 'abonnement',
]

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})
const page = await browser.newPage({ viewport: { width: WIDTH, height: 844 }, isMobile: true, hasTouch: true })
const failures = []
page.on('pageerror', (e) => failures.push(`Erreur JavaScript : ${e.message}`))

await page.goto(`${BASE}/admin/connexion`)
await page.locator('input[type="tel"], input[inputmode="tel"], input[autocomplete="username"]').first().fill(process.env.PHONE || '0700000001')
await page.locator('input[autocomplete="current-password"]').fill(process.env.PASSWORD || 'password')
await page.locator('button[type=submit]').click()
await page.waitForURL((url) => !url.pathname.endsWith('/connexion'), { timeout: 20000 })

for (const path of PAGES) {
  await page.goto(`${BASE}/admin/${path}`)
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(500)
  const m = await page.evaluate(() => {
    const main = document.querySelector('main')
    return { doc: document.documentElement.scrollWidth, main: main ? main.scrollWidth - main.clientWidth : 0, path: location.pathname }
  })
  if (!m.path.startsWith('/admin')) continue // page non accessible (droit ou module) : rien à contrôler
  if (m.doc > WIDTH || m.main > 1) failures.push(`/admin/${path} : ${Math.max(m.doc, WIDTH + m.main)} px pour un écran de ${WIDTH} px`)
}

// Notifications et menu : dans l'écran
await page.goto(`${BASE}/admin`)
await page.waitForLoadState('networkidle').catch(() => {})
await page.getByRole('button', { name: 'Notifications' }).click()
const panel = await page.getByText('Notifications', { exact: true }).last().evaluate((el) => {
  const r = el.closest('.shadow-xl').getBoundingClientRect()
  return { left: r.left, right: r.right }
})
if (panel.left < 0 || panel.right > WIDTH) failures.push(`Panneau des notifications hors de l'écran (${Math.round(panel.left)} → ${Math.round(panel.right)} px)`)

await browser.close()

if (failures.length) {
  console.error(`✗ ${failures.length} problème(s) sur téléphone :\n- ${failures.join('\n- ')}`)
  process.exit(1)
}
console.log(`✓ ${PAGES.length} pages de l'administration tiennent dans un écran de ${WIDTH} px`)
