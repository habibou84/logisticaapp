/**
 * Scanner de documents (resources/js/utils/scanner/core.js) : photos de document fabriquées avec OpenCV
 * (accusé à bandeau foncé posé sur une table, en perspective), puis vérifiées :
 * - feuille trouvée, en-tête compris, et cadrage sûr sur une photo nette ;
 * - photo floue refusée, photo nette acceptée ;
 * - bandeau foncé gardé foncé (pas pris pour une ombre), en couleur et en noir et blanc ;
 * - photo sombre : feuille entière, cadrage à vérifier par le livreur ;
 * - document pris de loin : trouvé, part de la photo petite.
 * Lancement : npm run test:scanner
 */
import { createRequire } from 'module'
import assert from 'assert/strict'
import { detect, render, BLUR_BLOCK } from '../../resources/js/utils/scanner/core.js'

const require = createRequire(import.meta.url)
let cv = require('@techstark/opencv-js')
if (cv instanceof Promise) cv = await cv
else if (!cv.Mat) await new Promise((resolve) => { cv.onRuntimeInitialized = resolve })

const W = 1400
const H = 1980
const BAND = 150

/** Accusé de réception : bandeau bleu foncé, lignes de texte de plusieurs tailles, cachet */
function sheet() {
  const s = new cv.Mat(H, W, cv.CV_8UC4, new cv.Scalar(242, 240, 235, 255))
  cv.rectangle(s, new cv.Point(0, 0), new cv.Point(W, BAND), new cv.Scalar(20, 40, 95, 255), -1)
  cv.putText(s, 'ACCUSE DE RECEPTION', new cv.Point(60, 100), cv.FONT_HERSHEY_SIMPLEX, 2, new cv.Scalar(255, 255, 255, 255), 4)
  let y = 260
  for (const size of [1.1, 0.9, 0.7, 0.5]) {
    for (let i = 0; i < 4; i++) {
      cv.putText(s, `Recu le colis LG-${40000 + i * 137} en bon etat, Cocody Angre 7e tranche`, new cv.Point(60, y), cv.FONT_HERSHEY_SIMPLEX, size, new cv.Scalar(25, 25, 25, 255), size > 0.6 ? 2 : 1)
      y += Math.round(size * 55)
    }
    y += 30
  }
  cv.circle(s, new cv.Point(300, 1600), 160, new cv.Scalar(40, 70, 160, 255), 8)
  return s
}

/** Photo de 3000 × 4000 : table rayée, feuille en perspective, puis flou et lumière au choix */
function photo({ blur = 0, light = 1, size = 0.62 } = {}) {
  const bg = new cv.Mat(4000, 3000, cv.CV_8UC4, new cv.Scalar(120, 90, 60, 255))
  for (let x = -4000; x < 3000; x += 40) cv.line(bg, new cv.Point(x, 0), new cv.Point(x + 900, 4000), new cv.Scalar(108, 82, 52, 255), 16)
  const h = 4000 * size
  const w = h * W / H
  const ox = (3000 - w) / 2
  const oy = (4000 - h) / 2
  const from = cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, W, 0, W, H, 0, H])
  const to = cv.matFromArray(4, 1, cv.CV_32FC2, [ox + 60, oy, ox + w - 40, oy + 30, ox + w + 50, oy + h, ox - 30, oy + h - 20])
  const m = cv.getPerspectiveTransform(from, to)
  const s = sheet()
  const warped = new cv.Mat()
  cv.warpPerspective(s, warped, m, bg.size(), cv.INTER_CUBIC, cv.BORDER_CONSTANT, new cv.Scalar(0, 0, 0, 0))
  // Feuille collée sur la table (canal alpha)
  const mask = new cv.Mat()
  const planes = new cv.MatVector()
  cv.split(warped, planes)
  cv.threshold(planes.get(3), mask, 0, 255, cv.THRESH_BINARY)
  warped.copyTo(bg, mask)
  if (light !== 1) bg.convertTo(bg, -1, light, 0)
  if (blur) cv.GaussianBlur(bg, bg, new cv.Size(0, 0), blur)
  const image = { data: new Uint8ClampedArray(bg.data), width: bg.cols, height: bg.rows }
  ;[from, to, m, s, warped, mask, planes, bg].forEach((x) => x.delete())
  return image
}

function resized(image, side) {
  const scale = Math.min(1, side / Math.max(image.width, image.height))
  const src = cv.matFromImageData(image)
  const out = new cv.Mat()
  cv.resize(src, out, new cv.Size(Math.round(image.width * scale), Math.round(image.height * scale)), 0, 0, cv.INTER_AREA)
  const r = { data: new Uint8ClampedArray(out.data), width: out.cols, height: out.rows }
  src.delete()
  out.delete()
  return r
}

/** Comme DocumentScanner : feuille trouvée sur une petite image, découpée dans la photo à pleine résolution */
function scan(image, mode = 'color', maxSide = 2000) {
  const found = detect(cv, resized(image, 1000))
  assert.ok(found, 'feuille trouvée')
  const p = found.corners.map(([x, y]) => [x * image.width, y * image.height])
  const d = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1])
  const page = Math.max(d(p[0], p[1]), d(p[3], p[2]), d(p[0], p[3]), d(p[1], p[2]))
  const side = Math.round(Math.max(image.width, image.height) * Math.min(1, (maxSide * 1.05) / page))
  return { found, ...render(cv, resized(image, side), found.corners, mode, maxSide) }
}

/** Gris moyen d'une zone du document rendu (proportions) */
function mean(image, x0, y0, x1, y1) {
  let sum = 0
  let n = 0
  for (let y = Math.round(y0 * image.height); y < Math.round(y1 * image.height); y += 2) {
    for (let x = Math.round(x0 * image.width); x < Math.round(x1 * image.width); x += 2) {
      const i = (y * image.width + x) * 4
      sum += (image.data[i] + image.data[i + 1] + image.data[i + 2]) / 3
      n++
    }
  }
  return sum / n
}

const results = []
function check(name, run) {
  try {
    run()
    results.push(`✓ ${name}`)
  } catch (e) {
    results.push(`✗ ${name} : ${e.message}`)
  }
}

const sharp = photo()
check('photo nette : feuille entière, cadrage sûr, pleine résolution, acceptée', () => {
  const { found, image, quality } = scan(sharp)
  assert.ok(found.confident, 'cadrage sûr')
  assert.ok(found.coverage > 0.3 && found.coverage < 0.45, `part de la photo ${found.coverage}`)
  assert.ok(image.height >= 1900, `hauteur ${image.height}`)
  assert.deepEqual(quality.warnings, [])
  // Bandeau d'en-tête gardé foncé, papier blanc
  const band = mean(image, 0.2, 0.01, 0.8, (BAND / H) * 0.8)
  assert.ok(band < 110, `bandeau ${band.toFixed(0)}`)
  assert.ok(mean(image, 0.1, 0.9, 0.9, 0.97) > 225, 'papier blanc')
})
check('noir et blanc : bandeau foncé gardé noir', () => {
  const { image } = scan(sharp, 'bw')
  const band = mean(image, 0.2, 0.01, 0.8, (BAND / H) * 0.8)
  assert.ok(band < 90, `bandeau ${band.toFixed(0)}`)
})
check('photo floue : refusée', () => {
  const { quality } = scan(photo({ blur: 3.5 }))
  assert.ok(quality.blur >= BLUR_BLOCK, `flou ${quality.blur}`)
  assert.ok(quality.warnings.includes('blur'))
})
check('photo à peine floue : acceptée', () => {
  const { quality } = scan(photo({ blur: 0.8 }))
  assert.ok(!quality.warnings.includes('blur'), `flou ${quality.blur}`)
})
check('photo sombre : feuille entière (en-tête compris)', () => {
  const { found, image } = scan(photo({ light: 0.4 }))
  assert.ok(found.coverage > 0.3, `part de la photo ${found.coverage}`)
  assert.ok(image.height / image.width > 1.25 && image.height / image.width < 1.45, `proportions ${image.width}x${image.height}`)
})
check('document pris de loin : trouvé, petite part de la photo', () => {
  const { found } = scan(photo({ size: 0.36 }))
  assert.ok(found.coverage < 0.2, `part de la photo ${found.coverage}`)
})

console.log(results.join('\n'))
if (results.some((r) => r.startsWith('✗'))) process.exit(1)
