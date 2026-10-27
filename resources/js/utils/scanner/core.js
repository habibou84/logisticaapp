/**
 * Traitement d'une photo de document avec OpenCV.js (appelé par le worker du scanner) :
 * - detect : trouve les quatre coins de la feuille ;
 * - render : redresse la feuille, gomme les ombres, rend le fond blanc et l'encre nette (couleur, gris,
 *   noir et blanc), et mesure la qualité (flou, photo sombre, reflet).
 * Les images sont des objets { data: Uint8ClampedArray RGBA, width, height }.
 */

/** Grand côté de l'image d'analyse (détection des bords) */
const DETECT_SIDE = 800

/**
 * Coins de la feuille, en proportions de l'image (0 à 1), dans l'ordre haut-gauche, haut-droite,
 * bas-droite, bas-gauche ; null si aucune feuille n'est trouvée.
 */
export function detect(cv, image) {
  const keep = []
  const track = (m) => (keep.push(m), m)
  try {
    const src = track(cv.matFromImageData(image))
    const scale = Math.min(1, DETECT_SIDE / Math.max(image.width, image.height))
    const small = track(new cv.Mat())
    cv.resize(src, small, new cv.Size(Math.round(image.width * scale), Math.round(image.height * scale)), 0, 0, cv.INTER_AREA)
    const gray = track(new cv.Mat())
    cv.cvtColor(small, gray, cv.COLOR_RGBA2GRAY)
    cv.GaussianBlur(gray, gray, new cv.Size(5, 5), 0)
    const area = gray.rows * gray.cols
    const candidates = []

    // 1. Bords nets (Canny) : la feuille sur un fond qui contraste peu
    const edges = track(new cv.Mat())
    cv.Canny(gray, edges, 30, 90)
    const k3 = track(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3)))
    cv.dilate(edges, edges, k3, new cv.Point(-1, -1), 2)
    candidates.push(...quads(cv, edges, area, track))

    // 2. Feuille plus claire que le fond (seuil d'Otsu) : bords flous ou abîmés
    const mask = track(new cv.Mat())
    cv.threshold(gray, mask, 0, 255, cv.THRESH_BINARY + cv.THRESH_OTSU)
    const k15 = track(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(15, 15)))
    cv.morphologyEx(mask, mask, cv.MORPH_CLOSE, k15)
    cv.morphologyEx(mask, mask, cv.MORPH_OPEN, k15)
    candidates.push(...quads(cv, mask, area, track))

    if (!candidates.length) return null
    candidates.sort((a, b) => b.area - a.area)
    const best = candidates[0]
    return order(best.points).map(([x, y]) => [x / gray.cols, y / gray.rows])
  } finally {
    keep.forEach((m) => m.delete())
  }
}

// Quadrilatères assez grands (15 % à 98 % de l'image) parmi les contours
function quads(cv, binary, area, track) {
  const contours = track(new cv.MatVector())
  const hierarchy = track(new cv.Mat())
  cv.findContours(binary, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE)
  const found = []
  for (let i = 0; i < contours.size(); i++) {
    const c = contours.get(i)
    const a = cv.contourArea(c)
    if (a < area * 0.15 || a > area * 0.98) {
      c.delete()
      continue
    }
    const hull = new cv.Mat()
    cv.convexHull(c, hull)
    const peri = cv.arcLength(hull, true)
    let points = null
    for (const eps of [0.02, 0.03, 0.05, 0.08]) {
      const approx = new cv.Mat()
      cv.approxPolyDP(hull, approx, eps * peri, true)
      if (approx.rows === 4) points = Array.from({ length: 4 }, (_, j) => [approx.data32S[j * 2], approx.data32S[j * 2 + 1]])
      approx.delete()
      if (points) break
    }
    // Coins arrondis ou pliés : points extrêmes de l'enveloppe
    if (!points) points = extremes(hull)
    const qa = polygonArea(points)
    // Le quadrilatère doit couvrir l'essentiel du contour (pas une forme en L)
    if (qa >= area * 0.15 && qa <= area * 0.98 && a / qa > 0.85) found.push({ points, area: qa })
    hull.delete()
    c.delete()
  }
  return found
}

function extremes(hull) {
  const pts = Array.from({ length: hull.rows }, (_, j) => [hull.data32S[j * 2], hull.data32S[j * 2 + 1]])
  const by = (f, max) => pts.reduce((best, p) => ((max ? f(p) > f(best) : f(p) < f(best)) ? p : best))
  return [by(([x, y]) => x + y, false), by(([x, y]) => x - y, true), by(([x, y]) => x + y, true), by(([x, y]) => x - y, false)]
}

function polygonArea(p) {
  let s = 0
  for (let i = 0; i < p.length; i++) {
    const [x1, y1] = p[i]
    const [x2, y2] = p[(i + 1) % p.length]
    s += x1 * y2 - x2 * y1
  }
  return Math.abs(s) / 2
}

/** Ordre haut-gauche, haut-droite, bas-droite, bas-gauche */
export function order(points) {
  const cx = points.reduce((s, p) => s + p[0], 0) / 4
  const cy = points.reduce((s, p) => s + p[1], 0) / 4
  const sorted = [...points].sort((a, b) => Math.atan2(a[1] - cy, a[0] - cx) - Math.atan2(b[1] - cy, b[0] - cx))
  // atan2 croissant : haut-gauche (angle le plus négatif proche de -135°) en premier
  let start = 0
  sorted.forEach((p, i) => {
    if (p[0] + p[1] < sorted[start][0] + sorted[start][1]) start = i
  })
  return [0, 1, 2, 3].map((i) => sorted[(start + i) % 4])
}

/**
 * Feuille redressée et nettoyée.
 * @param {[number, number][]|null} corners coins en proportions (null : toute la photo)
 * @param {'color'|'gray'|'bw'} mode
 * @param {number} maxSide grand côté de l'image rendue
 * @returns {{ image: { data, width, height }, quality: { blur: number, brightness: number, glare: number, warnings: string[] } }}
 */
export function render(cv, image, corners, mode = 'color', maxSide = 2000) {
  const keep = []
  const track = (m) => (keep.push(m), m)
  try {
    const rgba = track(cv.matFromImageData(image))
    const src = track(new cv.Mat())
    cv.cvtColor(rgba, src, cv.COLOR_RGBA2RGB)
    const pts = (corners || [[0, 0], [1, 0], [1, 1], [0, 1]]).map(([x, y]) => [x * image.width, y * image.height])
    const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1])
    let w = Math.max(dist(pts[0], pts[1]), dist(pts[3], pts[2]))
    let h = Math.max(dist(pts[0], pts[3]), dist(pts[1], pts[2]))
    const scale = Math.min(1, maxSide / Math.max(w, h))
    w = Math.max(16, Math.round(w * scale))
    h = Math.max(16, Math.round(h * scale))

    const from = track(cv.matFromArray(4, 1, cv.CV_32FC2, pts.flat()))
    const to = track(cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, w, 0, w, h, 0, h]))
    const m = track(cv.getPerspectiveTransform(from, to))
    const warped = track(new cv.Mat())
    cv.warpPerspective(src, warped, m, new cv.Size(w, h), cv.INTER_CUBIC, cv.BORDER_REPLICATE)
    // Bords rognés d'un rien : pas de liseré du fond quand le coin est trouvé à quelques pixels près
    const margin = corners ? Math.max(2, Math.round(Math.min(w, h) * 0.008)) : 0
    const flat = track(warped.roi(new cv.Rect(margin, margin, w - 2 * margin, h - 2 * margin)).clone())
    w -= 2 * margin
    h -= 2 * margin

    const gray = track(new cv.Mat())
    cv.cvtColor(flat, gray, cv.COLOR_RGB2GRAY)
    const quality = measure(cv, gray, track)
    // Document pris de trop loin : trop peu de pixels pour lire les petits caractères
    if (Math.max(w, h) < Math.min(1200, maxSide * 0.6)) quality.warnings.push('small')

    let out
    if (mode === 'color') {
      const channels = track(new cv.MatVector())
      cv.split(flat, channels)
      const merged = track(new cv.MatVector())
      for (let i = 0; i < 3; i++) {
        const ch = channels.get(i)
        const norm = whiten(cv, ch, track)
        merged.push_back(norm)
        ch.delete()
      }
      out = track(new cv.Mat())
      cv.merge(merged, out)
      levels(cv, out, track)
      sharpen(cv, out, track)
      cv.cvtColor(out, out, cv.COLOR_RGB2RGBA)
    } else {
      const norm = whiten(cv, gray, track)
      if (mode === 'bw') {
        const block = Math.max(15, (Math.round(Math.max(w, h) / 60) | 1))
        cv.adaptiveThreshold(norm, norm, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C, cv.THRESH_BINARY, block, 15)
      } else {
        levels(cv, norm, track)
        sharpen(cv, norm, track)
      }
      out = track(new cv.Mat())
      cv.cvtColor(norm, out, cv.COLOR_GRAY2RGBA)
    }
    return { image: { data: new Uint8ClampedArray(out.data), width: w, height: h }, quality }
  } finally {
    keep.forEach((x) => x.delete())
  }
}

/**
 * Fond de la feuille estimé (l'encre retirée par dilatation, puis lissé) et image divisée par ce fond :
 * le papier devient blanc uniforme, les ombres et l'éclairage inégal disparaissent.
 */
function whiten(cv, channel, track) {
  const small = track(new cv.Mat())
  const f = 4
  cv.resize(channel, small, new cv.Size(Math.max(8, Math.round(channel.cols / f)), Math.max(8, Math.round(channel.rows / f))), 0, 0, cv.INTER_AREA)
  const k = Math.max(5, Math.round(Math.max(small.cols, small.rows) / 40))
  const kernel = track(cv.getStructuringElement(cv.MORPH_ELLIPSE, new cv.Size(k, k)))
  cv.dilate(small, small, kernel)
  cv.medianBlur(small, small, 21)
  const bg = track(new cv.Mat())
  cv.resize(small, bg, new cv.Size(channel.cols, channel.rows), 0, 0, cv.INTER_LINEAR)
  const out = track(new cv.Mat())
  cv.divide(channel, bg, out, 255)
  return out
}

/** Niveaux : le presque blanc devient blanc, l'encre la plus sombre devient noire */
function levels(cv, img, track) {
  const g = img.channels() === 1 ? img : track(new cv.Mat())
  if (g !== img) cv.cvtColor(img, g, cv.COLOR_RGB2GRAY)
  const hist = new Uint32Array(256)
  const d = g.data
  for (let i = 0; i < d.length; i += 7) hist[d[i]]++
  const total = hist.reduce((s, v) => s + v, 0)
  let acc = 0
  let lo = 0
  while (lo < 200 && acc + hist[lo] < total * 0.005) acc += hist[lo++]
  lo = Math.min(lo, 90)
  const hi = 235
  const lut = new Uint8Array(256)
  for (let v = 0; v < 256; v++) {
    const t = Math.min(1, Math.max(0, (v - lo) / (hi - lo)))
    // Légère courbe : l'encre fonce sans boucher les traits fins
    lut[v] = Math.round(255 * Math.pow(t, 1.15))
  }
  const data = img.data
  for (let i = 0; i < data.length; i++) data[i] = lut[data[i]]
}

/** Netteté : masque flou léger */
function sharpen(cv, img, track) {
  const blur = track(new cv.Mat())
  cv.GaussianBlur(img, blur, new cv.Size(0, 0), 1.2)
  cv.addWeighted(img, 1.6, blur, -0.6, 0, img)
}

/**
 * Qualité de la photo :
 * - flou : rapport entre la netteté de l'image et celle de l'image légèrement floutée (une photo déjà floue
 *   change peu quand on la floute encore) ;
 * - luminosité du papier (0 à 255) ;
 * - reflet : part de la feuille brûlée par le flash ou une lampe.
 */
export function measure(cv, gray, track) {
  const g = track(new cv.Mat())
  const width = 1000
  cv.resize(gray, g, new cv.Size(width, Math.max(16, Math.round(gray.rows * width / gray.cols))), 0, 0, cv.INTER_AREA)
  const lapVar = (m) => {
    const lap = new cv.Mat()
    cv.Laplacian(m, lap, cv.CV_32F)
    const mean = new cv.Mat()
    const std = new cv.Mat()
    cv.meanStdDev(lap, mean, std)
    const v = std.doubleAt(0, 0) ** 2
    lap.delete(); mean.delete(); std.delete()
    return v
  }
  const sharp = lapVar(g)
  const soft = track(new cv.Mat())
  cv.GaussianBlur(g, soft, new cv.Size(0, 0), 1.5)
  const reblur = lapVar(soft)
  // Contraste de la page : presque vide (rien d'écrit), le flou ne se juge pas
  const mean = track(new cv.Mat())
  const std = track(new cv.Mat())
  cv.meanStdDev(g, mean, std)
  const contrast = std.doubleAt(0, 0)
  const blur = contrast < 6 ? 0 : 1 - Math.min(1, (sharp / Math.max(reblur, 0.01) - 1) / 9)

  const d = g.data
  const hist = new Uint32Array(256)
  for (let i = 0; i < d.length; i++) hist[d[i]]++
  // Papier : valeur sous laquelle se trouvent 80 % des pixels (le fond domine une feuille)
  let acc = 0
  let paper = 0
  while (paper < 255 && acc < d.length * 0.8) acc += hist[paper++]
  let burnt = 0
  for (let v = 252; v < 256; v++) burnt += hist[v]
  const glare = burnt / d.length

  const warnings = []
  if (blur > 0.72) warnings.push('blur')
  if (paper < 60) warnings.push('dark')
  if (glare > 0.04 && glare < 0.6) warnings.push('glare')
  return { blur: round(blur), brightness: paper, glare: round(glare), warnings }
}

const round = (v) => Math.round(v * 1000) / 1000
