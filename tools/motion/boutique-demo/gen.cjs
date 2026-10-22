// Visuels des produits et de l'accueil de la boutique de démonstration (PNG à côté de ce fichier)
const { chromium } = require('playwright')
const D = __dirname
;(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  const p = await b.newPage({ viewport: { width: 1600, height: 1200 } })
  await p.goto(`file://${D}/gen.html`)
  const names = [...await p.evaluate(() => window.NAMES), 'hero']
  for (const n of names) {
    await p.evaluate((n) => window.render(n), n)
    await p.locator('svg').screenshot({ path: `${D}/${n}.png` })
  }
  await b.close()
  console.log(names.join(' '))
})()
