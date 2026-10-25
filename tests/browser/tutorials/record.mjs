// Tournage d'un chapitre du guide « Bien démarrer » : l'application réelle (données de démonstration), curseur
// animé, éléments surlignés, titre ; chaque segment dure exactement le temps de sa phrase de voix off.
// node tests/browser/tutorials/record.mjs <clé> <durées des segments en secondes, séparées par des virgules> <dossier>
import { chromium } from 'playwright'
import fs from 'node:fs'
import { CHAPTERS } from './chapters.mjs'

const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const [key, durationsArg, out] = process.argv.slice(2)
const chapter = CHAPTERS.find((c) => c.key === key)
const durations = durationsArg.split(',').map(Number)
if (!chapter || durations.length !== chapter.segments.length) throw new Error(`Chapitre ${key} : ${chapter?.segments.length} segments, ${durations.length} durées`)

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' })
// Connexion hors caméra
const login = await browser.newContext({ viewport: { width: 1280, height: 720 } })
const lp = await login.newPage()
await lp.goto(`${BASE}/admin/connexion`)
await lp.locator('input[autocomplete="username"]').fill(process.env.LOGIN || '0700000001')
await lp.locator('input[autocomplete="current-password"]').fill('password')
await lp.keyboard.press('Enter')
await lp.waitForURL(/\/admin(?!\/connexion)/)
await lp.waitForTimeout(1500)
const state = await login.storageState()
await login.close()

const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 }, storageState: state, recordVideo: { dir: out, size: { width: 1280, height: 720 } } })
const born = Date.now()
const page = await ctx.newPage()

// Curseur, surlignage et titre dessinés par-dessus la page
const OVERLAY = `
  (() => {
    if (window.__tuto) return
    window.__tuto = true
    const css = document.createElement('style')
    css.textContent = '#tuto-cursor{position:fixed;z-index:99999;width:22px;height:22px;pointer-events:none;transition:left .7s cubic-bezier(.4,0,.2,1),top .7s cubic-bezier(.4,0,.2,1)}' +
      '#tuto-cursor svg{filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}' +
      '.tuto-ring{position:fixed;z-index:99998;pointer-events:none;border:3px solid #f59e0b;border-radius:10px;box-shadow:0 0 0 9999px rgba(15,23,42,.18);transition:all .5s}' +
      '.tuto-click{position:fixed;z-index:99999;width:30px;height:30px;margin:-15px 0 0 -15px;border-radius:50%;background:rgba(245,158,11,.45);pointer-events:none;animation:tuto-pop .6s ease-out forwards}' +
      '@keyframes tuto-pop{from{transform:scale(.3);opacity:1}to{transform:scale(2.2);opacity:0}}' +
      '#tuto-title{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.88);color:#fff;font:700 44px system-ui;flex-direction:column;gap:12px;transition:opacity .6s}' +
      '#tuto-title small{font:500 20px system-ui;opacity:.75}'
    document.head.appendChild(css)
    const c = document.createElement('div'); c.id = 'tuto-cursor'
    c.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 2l16 9-7 2-3 7z" fill="#fff" stroke="#111" stroke-width="1.5"/></svg>'
    c.style.left = (window.__tutoX ?? 640) + 'px'; c.style.top = (window.__tutoY ?? 360) + 'px'
    document.body.appendChild(c)
  })()`

const stage = {
  async ready() {
    await page.waitForLoadState('networkidle').catch(() => {})
    await page.evaluate(OVERLAY)
  },
  async go(path) {
    await page.goto(`${BASE}${path}`)
    await stage.ready()
    await page.waitForTimeout(700)
  },
  async wait(ms) { await page.waitForTimeout(ms) },
  async locate(selector) {
    const loc = page.locator(selector).first()
    await loc.waitFor({ state: 'visible', timeout: 6000 })
    return loc
  },
  async moveTo(loc) {
    await loc.scrollIntoViewIfNeeded().catch(() => {})
    const box = await loc.boundingBox()
    if (!box) return null
    const x = box.x + Math.min(box.width / 2, 60); const y = box.y + box.height / 2
    await page.evaluate(([x, y]) => { const c = document.getElementById('tuto-cursor'); if (c) { c.style.left = x + 'px'; c.style.top = y + 'px' } window.__tutoX = x; window.__tutoY = y }, [x, y])
    await page.waitForTimeout(750)
    return { box, x, y }
  },
  // Élément entouré, curseur dessus
  async hl(selector) {
    try {
      const loc = await stage.locate(selector)
      const at = await stage.moveTo(loc)
      if (!at) return
      const b = at.box
      await page.evaluate((b) => {
        document.querySelectorAll('.tuto-ring').forEach((e) => e.remove())
        const r = document.createElement('div'); r.className = 'tuto-ring'
        Object.assign(r.style, { left: b.x - 6 + 'px', top: b.y - 6 + 'px', width: b.width + 12 + 'px', height: Math.min(b.height, 520) + 12 + 'px' })
        document.body.appendChild(r)
        setTimeout(() => r.remove(), 2600)
      }, b)
      await page.waitForTimeout(900)
    } catch (e) { console.warn(`  (introuvable : ${selector})`) }
  },
  async click(selector) {
    try {
      const loc = await stage.locate(selector)
      const at = await stage.moveTo(loc)
      if (at) await page.evaluate(([x, y]) => { const d = document.createElement('div'); d.className = 'tuto-click'; d.style.left = x + 'px'; d.style.top = y + 'px'; document.body.appendChild(d); setTimeout(() => d.remove(), 700) }, [at.x, at.y])
      await loc.click()
      await stage.ready()
      await page.waitForTimeout(500)
    } catch (e) { console.warn(`  (clic impossible : ${selector})`) }
  },
  async type(selector, text, delay = 70) {
    try {
      const loc = await stage.locate(selector)
      await stage.moveTo(loc)
      await loc.click()
      await loc.pressSequentially(text, { delay })
    } catch (e) { console.warn(`  (saisie impossible : ${selector})`) }
  },
  async select(selector, label) {
    try {
      const loc = await stage.locate(selector)
      await stage.moveTo(loc)
      await loc.selectOption({ label })
      await page.waitForTimeout(600)
    } catch (e) { console.warn(`  (choix impossible : ${selector})`) }
  },
  async pickFirst() {
    try {
      const loc = await stage.locate('ul.absolute button')
      await stage.moveTo(loc)
      await loc.dispatchEvent('mousedown')
      await page.waitForTimeout(600)
    } catch (e) { console.warn('  (aucune suggestion)') }
  },
  async scrollTo(selector) {
    try {
      const loc = await stage.locate(selector)
      await loc.evaluate((el) => el.scrollIntoView({ behavior: 'smooth', block: 'start' }))
      await page.waitForTimeout(900)
      await stage.moveTo(loc)
    } catch (e) { console.warn(`  (introuvable : ${selector})`) }
  },
  async scrollTop() { await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' })); await page.waitForTimeout(800) },
  async closeModal() { await page.keyboard.press('Escape'); await page.locator('[aria-label="Fermer"]').first().click({ timeout: 1500 }).catch(() => {}) },
}

// Écran titre, puis les segments, chacun calé sur sa phrase
await page.goto(`${BASE}/admin`)
await stage.ready()
await page.evaluate((t) => { const d = document.createElement('div'); d.id = 'tuto-title'; d.innerHTML = t; document.body.appendChild(d) },
  `${chapter.title}<small>Bien démarrer · chapitre ${CHAPTERS.indexOf(chapter) + 1} sur ${CHAPTERS.length}</small>`)
await page.waitForTimeout(400)
const start = Date.now()
const marks = []
const TITLE = 2.5
await page.waitForTimeout(TITLE * 1000 - 400)
await page.evaluate(() => { const t = document.getElementById('tuto-title'); if (t) { t.style.opacity = '0'; setTimeout(() => t.remove(), 700) } })
let clock = TITLE
for (const [i, seg] of chapter.segments.entries()) {
  const segStart = Date.now()
  marks.push((segStart - start) / 1000)
  await seg.do(stage)
  const spent = (Date.now() - segStart) / 1000
  const wait = durations[i] - spent
  if (wait > 0) await page.waitForTimeout(wait * 1000)
  else console.warn(`  segment ${i + 1} : ${(-wait).toFixed(1)} s de trop`)
  clock += durations[i]
}
await page.waitForTimeout(1200)
const video = page.video()
await ctx.close()
await browser.close()
const path = await video.path()
// Début de la vidéo : l'instant où l'écran titre apparaît
fs.writeFileSync(`${out}/${key}.json`, JSON.stringify({ video: path, offset: (start - born) / 1000 - 0.4, title: TITLE, marks }, null, 1))
console.log(`${key} filmé : ${path}`)
