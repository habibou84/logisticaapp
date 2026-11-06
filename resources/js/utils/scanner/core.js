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
 * Feuille trouvée sur la photo, ou null :
 * - corners : coins en proportions de l'image (0 à 1), dans l'ordre haut-gauche, haut-droite, bas-droite, bas-gauche ;
 * - confident : bords nets et forme de feuille bien fermée ; sinon le livreur vérifie le cadrage ;
 * - coverage : part de la photo occupée par la feuille (petite : prise de trop loin).
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
    const k3 = track(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3)))

    // 1. Bords nets (Canny) : sur la photo telle quelle, contraste égalisé (photo sombre ou terne), et bords de
    //    couleur réunis à ceux de la lumière (bandeau bleu sur une table brune : même gris, couleurs opposées)
    const equalized = track(new cv.Mat())
    cv.equalizeHist(gray, equalized)
    const all = track(cv.Mat.zeros(gray.rows, gray.cols, cv.CV_8U))
    const rgb = track(new cv.Mat())
    cv.cvtColor(small, rgb, cv.COLOR_RGBA2RGB)
    const lab = track(new cv.Mat())
    cv.cvtColor(rgb, lab, cv.COLOR_RGB2Lab)
    const planes = track(new cv.MatVector())
    cv.split(lab, planes)
    const sources = [[gray, 30, 90], [equalized, 40, 120]]
    for (const i of [1, 2]) {
      const c = track(planes.get(i))
      cv.GaussianBlur(c, c, new cv.Size(5, 5), 0)
      cv.normalize(c, c, 0, 255, cv.NORM_MINMAX)
      sources.push([c, 20, 60, true])
    }
    for (const [img, low, high, colorOnly] of sources) {
      const edges = track(new cv.Mat())
      cv.Canny(img, edges, low, high)
      cv.bitwise_or(all, edges, all)
      if (colorOnly) continue
      cv.dilate(edges, edges, k3, new cv.Point(-1, -1), 2)
      candidates.push(...quads(cv, edges, area, track).map((q) => ({ ...q, edges: true })))
    }
    cv.dilate(all, all, k3, new cv.Point(-1, -1), 2)
    candidates.push(...quads(cv, all, area, track).map((q) => ({ ...q, edges: true })))

    // 2. Feuille plus claire que le fond (seuil d'Otsu) : bords flous ou abîmés ; moins sûr (un bandeau
    //    foncé au bord de la feuille peut être pris pour le fond)
    const mask = track(new cv.Mat())
    cv.threshold(gray, mask, 0, 255, cv.THRESH_BINARY + cv.THRESH_OTSU)
    const k15 = track(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(15, 15)))
    cv.morphologyEx(mask, mask, cv.MORPH_CLOSE, k15)
    cv.morphologyEx(mask, mask, cv.MORPH_OPEN, k15)
    candidates.push(...quads(cv, mask, area, track).map((q) => ({ ...q, edges: false })))

    if (!candidates.length) return null
    // La plus grande forme de feuille ; à défaut, le plus grand quadrilatère (à vérifier par le livreur)
    // Cadre collé aux bords de la photo (bord de l'image, rayures de la table) : seulement s'il n'y a rien d'autre
    const edge = (x, y) => x < gray.cols * 0.02 || x > gray.cols * 0.98 || y < gray.rows * 0.02 || y > gray.rows * 0.98
    candidates.forEach((c) => {
      c.points = order(c.points)
      c.shape = plausible(c.points) && c.points.filter(([x, y]) => edge(x, y)).length < 3
    })
    // Forme de feuille d'abord, puis contour bien fermé (pas une forme qui déborde sur la table), puis la plus grande
    const closed = (c) => c.fill > 0.92
    candidates.sort((a, b) => (b.shape - a.shape) || (closed(b) - closed(a)) || (b.area - a.area))
    const best = candidates[0]
    // Bord de feuille qui n'a pas la couleur de la table juste au-delà (bandeau foncé au bord du document) :
    // la feuille est agrandie de ce côté, et le livreur vérifie le cadrage
    const grown = grow(cv, lab, best.points)
    return {
      corners: grown.points.map(([x, y]) => [x / gray.cols, y / gray.rows]),
      confident: best.shape && best.edges && best.fill > 0.92 && !grown.changed,
      coverage: round(polygonArea(grown.points) / area),
    }
  } finally {
    keep.forEach((m) => m.delete())
  }
}

/**
 * Agrandit la feuille côté par côté tant que la bande juste au-delà du bord ne ressemble pas au fond (couleur
 * des bords de la photo, presque toujours la table) : un bandeau foncé en haut d'une facture, invisible en gris
 * sur une table sombre, reste dans le document.
 */
function grow(cv, lab, points) {
  const w = lab.cols
  const h = lab.rows
  const px = (x, y) => {
    const i = (Math.min(h - 1, Math.max(0, Math.round(y))) * w + Math.min(w - 1, Math.max(0, Math.round(x)))) * 3
    return [lab.data[i], lab.data[i + 1], lab.data[i + 2]]
  }
  // Fond : pixels du pourtour de la photo
  const ring = []
  const m = Math.max(2, Math.round(Math.min(w, h) * 0.02))
  for (let x = 0; x < w; x += 4) ring.push(px(x, m), px(x, h - 1 - m))
  for (let y = 0; y < h; y += 4) ring.push(px(m, y), px(w - 1 - m, y))
  const median = [0, 1, 2].map((k) => ring.map((p) => p[k]).sort((a, b) => a - b)[Math.floor(ring.length / 2)])
  const spread = ring.map((p) => Math.hypot(p[0] - median[0], p[1] - median[1], p[2] - median[2])).sort((a, b) => a - b)[Math.floor(ring.length * 0.8)]
  const limit = Math.max(14, spread * 1.5)

  const pts = points.map((p) => [...p])
  const cx = pts.reduce((s, p) => s + p[0], 0) / 4
  const cy = pts.reduce((s, p) => s + p[1], 0) / 4
  let changed = false
  for (let i = 0; i < 4; i++) {
    const a = pts[i]
    const b = pts[(i + 1) % 4]
    const len = Math.hypot(b[0] - a[0], b[1] - a[1])
    let nx = -(b[1] - a[1]) / len
    let ny = (b[0] - a[0]) / len
    // Normale tournée vers l'extérieur de la feuille
    if (nx * ((a[0] + b[0]) / 2 - cx) + ny * ((a[1] + b[1]) / 2 - cy) < 0) [nx, ny] = [-nx, -ny]
    let step = 0
    const maxStep = Math.round(len * 0.25)
    for (let t = 2; t <= maxStep; t++) {
      let unlike = 0
      const n = 30
      for (let k = 0; k < n; k++) {
        const f = 0.15 + 0.7 * (k / (n - 1))
        const p = px(a[0] + (b[0] - a[0]) * f + nx * t, a[1] + (b[1] - a[1]) * f + ny * t)
        if (Math.hypot(p[0] - median[0], p[1] - median[1], p[2] - median[2]) > limit) unlike++
      }
      // La bande ressemble au fond : le bord est atteint
      if (unlike < n * 0.7) break
      step = t
    }
    if (step >= Math.max(4, len * 0.02)) {
      changed = true
      for (const j of [i, (i + 1) % 4]) {
        pts[j][0] = Math.min(w - 1, Math.max(0, pts[j][0] + nx * step))
        pts[j][1] = Math.min(h - 1, Math.max(0, pts[j][1] + ny * step))
      }
    }
  }
  return { points: pts, changed }
}

/** Forme d'une feuille vue en photo : côtés opposés de longueurs proches, angles pas trop fermés */
function plausible(p) {
  const d = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1])
  const ratio = (a, b) => Math.min(a, b) / Math.max(a, b)
  if (ratio(d(p[0], p[1]), d(p[3], p[2])) < 0.7 || ratio(d(p[0], p[3]), d(p[1], p[2])) < 0.7) return false
  for (let i = 0; i < 4; i++) {
    const a = p[(i + 3) % 4]
    const b = p[i]
    const c = p[(i + 1) % 4]
    const cos = ((a[0] - b[0]) * (c[0] - b[0]) + (a[1] - b[1]) * (c[1] - b[1])) / (d(a, b) * d(c, b))
    if (Math.abs(cos) > 0.45) return false
  }
  return true
}

// Quadrilatères assez grands (5 % à 98 % de l'image) parmi les contours
function quads(cv, binary, area, track) {
  const contours = track(new cv.MatVector())
  const hierarchy = track(new cv.Mat())
  cv.findContours(binary, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE)
  const found = []
  for (let i = 0; i < contours.size(); i++) {
    const c = contours.get(i)
    const a = cv.contourArea(c)
    if (a < area * 0.05 || a > area * 0.98) {
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
    if (qa >= area * 0.05 && qa <= area * 0.98 && a / qa > 0.85) found.push({ points, area: qa, fill: a / qa })
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
    const quality = measure(cv, gray, track, maxSide)
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
        // Seuil local (encre fine, ombres) ; un aplat foncé, uniforme, resterait blanc avec lui seul
        const local = track(new cv.Mat())
        cv.adaptiveThreshold(norm, local, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C, cv.THRESH_BINARY, block, 15)
        const solid = track(new cv.Mat())
        cv.threshold(norm, solid, 110, 255, cv.THRESH_BINARY)
        cv.bitwise_and(local, solid, norm)
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
 * Les aplats foncés plus larges que l'encre (bandeau d'en-tête, logo plein, tampon rempli) ne sont pas pris
 * pour de l'ombre : là où le fond estimé est bien plus sombre que le papier autour, le papier autour sert de fond.
 */
function whiten(cv, channel, track) {
  const small = track(new cv.Mat())
  const f = 4
  cv.resize(channel, small, new cv.Size(Math.max(8, Math.round(channel.cols / f)), Math.max(8, Math.round(channel.rows / f))), 0, 0, cv.INTER_AREA)
  const side = Math.max(small.cols, small.rows)
  const near = track(new cv.Mat())
  cv.dilate(small, near, track(cv.getStructuringElement(cv.MORPH_ELLIPSE, new cv.Size(Math.max(5, Math.round(side / 40)), Math.max(5, Math.round(side / 40))))))
  cv.medianBlur(near, near, 21)
  const far = track(new cv.Mat())
  const big = Math.max(15, Math.round(side / 5))
  cv.dilate(small, far, track(cv.getStructuringElement(cv.MORPH_ELLIPSE, new cv.Size(big, big))))
  cv.medianBlur(far, far, 21)
  const n = near.data
  const fa = far.data
  for (let i = 0; i < n.length; i++) {
    if (n[i] < fa[i] * DARK_AREA) n[i] = fa[i]
  }
  cv.GaussianBlur(near, near, new cv.Size(5, 5), 0)
  const bg = track(new cv.Mat())
  cv.resize(near, bg, new cv.Size(channel.cols, channel.rows), 0, 0, cv.INTER_LINEAR)
  const out = track(new cv.Mat())
  cv.divide(channel, bg, out, 255)
  return out
}

/** Fond estimé sous cette part du papier autour : aplat foncé du document, pas une ombre */
const DARK_AREA = 0.6

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
 * - flou : largeur des bords des lettres (en pixels d'une page de 2 000 points), déduite de la pente la plus
 *   forte des bords rapportée au contraste de l'encre ; au-delà de 1,9, les petits caractères ne se lisent plus ;
 * - luminosité du papier (0 à 255) ;
 * - reflet : part de la feuille brûlée par le flash ou une lampe.
 */
export function measure(cv, gray, track, refSide = 2000) {
  const warnings = []

  // Flou, sur la feuille blanchie (contraste égal partout) et débarrassée du grain (photo sombre)
  const flat = whiten(cv, gray, track)
  cv.medianBlur(flat, flat, 3)
  const gx = track(new cv.Mat())
  const gy = track(new cv.Mat())
  cv.Sobel(flat, gx, cv.CV_32F, 1, 0, 3)
  cv.Sobel(flat, gy, cv.CV_32F, 0, 1, 3)
  const mag = track(new cv.Mat())
  cv.magnitude(gx, gy, mag)
  const m = mag.data32F
  const step = Math.max(1, Math.floor(m.length / 400000))
  const sample = []
  for (let i = 0; i < m.length; i += step) sample.push(m[i])
  sample.sort((a, b) => b - a)
  const slope = sample[Math.floor(sample.length * 0.002)] / 8
  const f = flat.data
  const hist = new Uint32Array(256)
  for (let i = 0; i < f.length; i += step) hist[f[i]]++
  let acc = 0
  let ink = 0
  const total = Math.ceil(f.length / step)
  while (ink < 255 && acc + hist[ink] < total * 0.01) acc += hist[ink++]
  const contrast = 255 - ink
  // Page presque vide (rien d'écrit) : le flou ne se juge pas
  const blur = contrast < 60 || slope <= 0 ? 0 : (contrast / (slope * Math.sqrt(2 * Math.PI))) * (Math.min(2000, refSide) / Math.max(gray.cols, gray.rows))
  if (blur >= BLUR_BLOCK) warnings.push('blur')
  else if (blur >= BLUR_SOFT) warnings.push('soft')

  // Lumière et reflet, sur la photo d'origine réduite
  const g = track(new cv.Mat())
  const width = 1000
  cv.resize(gray, g, new cv.Size(width, Math.max(16, Math.round(gray.rows * width / gray.cols))), 0, 0, cv.INTER_AREA)
  const d = g.data
  const h = new Uint32Array(256)
  for (let i = 0; i < d.length; i++) h[d[i]]++
  // Papier : valeur sous laquelle se trouvent 80 % des pixels (le fond domine une feuille)
  acc = 0
  let paper = 0
  while (paper < 255 && acc < d.length * 0.8) acc += h[paper++]
  let burnt = 0
  for (let v = 252; v < 256; v++) burnt += h[v]
  const glare = burnt / d.length

  if (paper < 60) warnings.push('dark')
  if (glare > 0.04 && glare < 0.6) warnings.push('glare')
  return { blur: round(blur), brightness: paper, glare: round(glare), warnings }
}

/** Largeur des bords au-delà de laquelle la photo est refusée (floue), ou signalée (un peu floue) */
export const BLUR_BLOCK = 1.9
export const BLUR_SOFT = 1.5

const round = (v) => Math.round(v * 1000) / 1000
