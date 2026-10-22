/**
 * Filme une scène image par image et la monte en MP4 (H.264, 30 images/s).
 *   node tools/motion/render.cjs <scène> [v|f|w ...] [--audio=fichier.mp3]
 * v : 1080 × 1920 (TikTok, Reels, Stories) · f : 1080 × 1350 (fil Facebook) · w : 1920 × 1080 (sites)
 * Sortie : tools/motion/out/<scène>-<format>.mp4 (et une affiche .jpg prise au milieu)
 */
const path = require('path')
const fs = require('fs')
const { spawn } = require('child_process')
const { chromium } = require(process.env.PLAYWRIGHT_MODULES ? path.join(process.env.PLAYWRIGHT_MODULES, 'playwright') : 'playwright')

const FPS = 30
const SIZES = { v: [1080, 1920], f: [1080, 1350], w: [1920, 1080] }
const args = process.argv.slice(2)
const scene = args.find((a) => !a.startsWith('--') && !SIZES[a])
const formats = args.filter((a) => SIZES[a])
const audio = (args.find((a) => a.startsWith('--audio=')) || '').slice(8) || null
if (!scene) {
  console.error('Usage : node tools/motion/render.cjs <scène> [v|f|w ...] [--audio=fichier]')
  process.exit(1)
}

const OUT = path.join(__dirname, 'out')
fs.mkdirSync(OUT, { recursive: true })

;(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' })
  for (const f of formats.length ? formats : ['v', 'f', 'w']) {
    const [w, h] = SIZES[f]
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 })
    page.on('pageerror', (e) => console.error('Erreur de la scène :', e.message))
    await page.goto(`file://${path.join(__dirname, 'player.html')}?scene=${encodeURIComponent(scene)}&f=${f}`)
    await page.waitForFunction(() => window.MOTION_READY)
    await page.evaluate(() => window.MOTION_READY)
    const duration = await page.evaluate(() => window.DURATION)
    const frames = Math.round(duration * FPS)
    // Capture directe par Chrome (plus rapide que page.screenshot)
    const cdp = await page.context().newCDPSession(page)
    const file = path.join(OUT, `${scene}-${f}.mp4`)

    const input = ['-y', '-f', 'image2pipe', '-framerate', String(FPS), '-i', '-']
    const sound = audio ? ['-i', audio] : ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000']
    const ffmpeg = spawn('ffmpeg', [...input, ...sound,
      '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p',
      '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', file], { stdio: ['pipe', 'ignore', 'inherit'] })

    for (let i = 0; i < frames; i++) {
      await page.evaluate((t) => window.render(t), i / FPS)
      const { data } = await cdp.send('Page.captureScreenshot', { format: 'jpeg', quality: 90, optimizeForSpeed: true })
      const jpg = Buffer.from(data, 'base64')
      if (!ffmpeg.stdin.write(jpg)) await new Promise((ok) => ffmpeg.stdin.once('drain', ok))
      if (i === Math.round(frames * 0.42)) fs.writeFileSync(path.join(OUT, `${scene}-${f}.jpg`), jpg)
    }
    ffmpeg.stdin.end()
    await new Promise((ok) => ffmpeg.on('close', ok))
    console.log(`✓ ${path.relative(process.cwd(), file)} (${duration.toFixed(1)} s)`)
    await page.close()
  }
  await browser.close()
})()
