/**
 * Moteur des vidéos en motion design : une scène est une suite de plans (accroche, téléphone, fin), décrite dans
 * scenes/<nom>.js. render(t) dessine l'image à l'instant t (en secondes) : render.cjs la filme image par image,
 * le même rendu sort donc à l'identique dans les trois formats.
 */
;(function () {
  const FORMATS = { v: [1080, 1920], f: [1080, 1350], w: [1920, 1080] }
  const SCREEN_W = 390
  const SCREEN_H = 844
  const IN = 0.5
  const OUT = 0.35

  const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x))
  const lerp = (a, b, k) => a + (b - a) * k
  const ease = {
    out: (k) => 1 - Math.pow(1 - k, 3),
    inOut: (k) => (k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2),
    back: (k) => { const c = 1.6; return 1 + (c + 1) * Math.pow(k - 1, 3) + c * Math.pow(k - 1, 2) },
  }
  const prog = (t, a, b) => clamp((t - a) / (b - a))

  const BRANDS = {
    jibiat: { name: 'Jibiat', url: 'jibiat.com', color: '#f96f02', deep: '#b93f00', logo: 'assets/logo-jibiat.png', halo: ['#f96f02', '#1d4ed8'] },
    marchand: { name: 'Jibiat Marchand', url: 'jibiat-marchand.com', color: '#039a45', deep: '#01653a', logo: 'assets/logo-marchand.png', tile: '#ffffff', halo: ['#0fbb4f', '#0e7490'] },
  }
  // Application montrée sur le téléphone : étiquette au-dessus de l'écran
  const APPS = {
    marchand: { label: 'Jibiat Marchand', note: "l'espace marchand", brand: 'marchand' },
    jibiat: { label: 'Jibiat', note: "l'entreprise de livraison", brand: 'jibiat' },
    livreur: { label: 'Jibiat', note: 'le livreur', brand: 'jibiat' },
    ecommercant: { label: 'Jibiat', note: "l'e-commerçant", brand: 'jibiat' },
    client: { label: 'Jibiat', note: 'votre client e-commerçant', brand: 'jibiat' },
    suivi: { label: 'Jibiat', note: 'le suivi du destinataire', brand: 'jibiat' },
    annuaire: { label: 'jibiat.com', note: "l'annuaire", brand: 'jibiat' },
    boutique: { label: 'Votre boutique en ligne', note: 'vue par votre client', brand: 'marchand' },
  }

  function el(tag, cls, parent, html) {
    const node = document.createElement(tag)
    if (cls) node.className = cls
    if (html !== undefined) node.innerHTML = html
    if (parent) parent.appendChild(node)
    return node
  }

  // « Votre *boutique* vend » : *mots* en couleur de la marque, _mots_ en couleur de l'autre marque (sur plusieurs mots)
  function words(parent, text, brand) {
    const other = brand === 'jibiat' ? 'marchand' : 'jibiat'
    let mode = null
    const spans = []
    // Typographie française : ? ! : ; collés au mot précédent par une espace insécable
    const typo = text.replace(/ ([?!:;»])/g, '\u202f$1').replace(/« /g, '«\u202f')
    for (const token of typo.split(/([ \t]+|\n)/)) {
      if (token === '') continue
      if (token === '\n') { el('br', null, parent); continue }
      if (/^[ \t]+$/.test(token)) { parent.appendChild(document.createTextNode(' ')); continue }
      let w = token
      let cls = mode
      if (w.startsWith('*')) { mode = cls = 'brand'; w = w.slice(1) } else if (w.startsWith('_')) { mode = cls = 'other'; w = w.slice(1) }
      const m = w.match(/^(.*?)([*_])([\u202f.,!?:;…»]*)$/)
      if (m) { w = m[1] + m[3]; mode = null }
      const hl = cls === 'brand' ? ` hl-${brand}` : cls === 'other' ? ` hl-${other}` : ''
      spans.push(el('span', `w${hl}`, parent, w))
    }
    return spans
  }

  function revealWords(spans, k, stagger = 0.09, span = 0.45) {
    spans.forEach((s, i) => {
      const p = ease.out(clamp((k - i * stagger) / span))
      s.style.opacity = p
      s.style.transform = `translateY(${(1 - p) * 0.45}em)`
    })
  }

  // Position du téléphone selon le format
  function phoneRect(fmt) {
    if (fmt === 'v') return { w: 620, left: (1080 - 620) / 2, top: 640 }
    if (fmt === 'f') return { w: 450, left: (1080 - 450) / 2, top: 400 }
    return { w: 420, left: 1240, top: 90 }
  }
  // Où la zone filmée de près se place sur l'image
  function focusTarget(fmt) {
    if (fmt === 'v') return [540, 1260]
    if (fmt === 'f') return [540, 880]
    return [1450, 560]
  }

  const Motion = {
    clamp, lerp, ease, prog,

    start(scene, fmt, play) {
      document.documentElement.classList.add(`fmt-${fmt}`, `brand-${scene.brand}`)
      const [W, H] = FORMATS[fmt]
      const stage = document.getElementById('stage')
      const brand = BRANDS[scene.brand]

      // Fond animé
      const halos = brand.halo.map((c) => {
        const h = el('div', 'halo', stage)
        h.style.background = `radial-gradient(circle, ${c} 0%, ${c}88 25%, ${c}00 68%)`
        h.style.width = h.style.height = `${Math.max(W, H) * 0.7}px`
        return h
      })

      let at = 0
      const shots = scene.shots.map((shot) => {
        const s = { ...shot, start: at, end: at + shot.dur, root: el('div', 'layer', stage) }
        at = s.end
        s.root.style.opacity = 0
        if (shot.type === 'hook') {
          const box = el('div', 'hook', s.root)
          const inner = el('div', null, box)
          s.words = words(inner, shot.text, scene.brand)
        } else if (shot.type === 'phone') {
          s.scrim = el('div', 'scrim', s.root)
          if (shot.title) s.words = words(el('div', 'headline', s.root), shot.title, scene.brand)
          if (shot.sub) { s.sub = el('div', 'sub', s.root, shot.sub) }
          const r = phoneRect(fmt)
          s.phone = el('div', 'phone', s.root)
          Object.assign(s.phone.style, { left: `${r.left}px`, top: `${r.top}px`, width: `${r.w}px`, height: `${(r.w * 0.948) * (SCREEN_H / SCREEN_W) + r.w * 0.052}px` })
          el('div', 'notch', s.phone)
          const screen = el('div', 'screen', s.phone)
          s.img = el('img', null, screen)
          s.img.src = shot.src
          s.rect = r
          s.pad = r.w * 0.026
          s.unit = (r.w - 2 * s.pad) / SCREEN_W
          if (shot.app) {
            const app = APPS[shot.app]
            const b = BRANDS[app.brand]
            s.tag = el('div', 'app-tag', s.root)
            const logo = el('span', 'logo', s.tag)
            logo.style.background = b.tile ?? b.color
            if (b.logo) el('img', null, logo).src = b.logo
            else logo.textContent = b.letter
            el('span', null, s.tag, `${app.label} <span style="font-weight:600;opacity:.75">· ${app.note}</span>`)
          }
          if (shot.ring) s.ringEl = el('div', 'focus-ring', s.root)
          if (shot.tap) s.tapEl = el('div', 'tap', s.root)
          s.pills = (shot.pills ?? (shot.pill ? [shot.pill] : [])).map((p) => {
            const node = el('div', 'pill', s.root)
            el('span', 'dot', node)
            el('span', null, node, p.text)
            return { ...p, node }
          })
        } else if (shot.type === 'end') {
          const end = el('div', 'end', s.root)
          const logos = el('div', 'logos', end)
          ;(shot.brands ?? [scene.brand]).forEach((name, i) => {
            if (i > 0) el('div', 'plus', logos, '+')
            const b = BRANDS[name]
            const block = el('div', 'brand-block', logos)
            const logo = el('div', 'brand-logo', block)
            logo.style.background = b.tile ?? b.color
            if (b.logo) el('img', null, logo).src = b.logo
            else logo.textContent = b.letter
            el('div', 'brand-name', block, b.name)
            el('div', 'brand-url', block, b.url)
          })
          s.logos = logos
          s.words = words(el('div', 'tagline', end), shot.tagline, scene.brand)
          if (shot.cta) s.cta = el('div', 'cta', end, shot.cta)
        }
        return s
      })

      const duration = at

      // Point de l'écran (coordonnées de la capture) → position sur l'image, téléphone zoomé compris
      function screenToStage(s, x, y, z, tx, ty, ox, oy) {
        const ex = s.pad + x * s.unit
        const ey = s.pad + y * s.unit
        return [s.rect.left + ox + (ex - ox) * z + tx, s.rect.top + oy + (ey - oy) * z + ty]
      }

      function renderPhone(s, lt) {
        const kIn = ease.out(prog(lt, 0, IN))
        const kOut = ease.inOut(prog(lt, s.dur - OUT, s.dur))
        // Défilement de la capture, puis approche de la zone à montrer
        const scroll = s.scroll ?? [0, 0]
        const scrollAt = s.scrollAt ?? [0.3, Math.max(0.6, s.dur * 0.45)]
        const sy = lerp(scroll[0], scroll[1], ease.inOut(prog(lt, scrollAt[0], scrollAt[1])))
        s.img.style.transform = `translateY(${-sy * s.unit}px)`

        const zoom = s.zoom ?? [1, 1]
        const zoomAt = s.zoomAt ?? [Math.min(0.9, s.dur * 0.3), Math.min(s.dur - 0.4, s.dur * 0.3 + 1.6)]
        const kz = ease.inOut(prog(lt, zoomAt[0], zoomAt[1]))
        const z = lerp(zoom[0], zoom[1], kz)
        const focus = s.focus ?? [SCREEN_W / 2, SCREEN_H / 2]
        const ox = s.pad + focus[0] * s.unit
        const oy = s.pad + focus[1] * s.unit
        const [txT, tyT] = focusTarget(fmt)
        const tx = (txT - (s.rect.left + ox)) * kz * (zoom[1] > 1 ? 1 : 0)
        const ty = (tyT - (s.rect.top + oy)) * kz * (zoom[1] > 1 ? 1 : 0)
        const enter = (1 - kIn) * 260
        s.phone.style.transformOrigin = `${ox}px ${oy}px`
        s.phone.style.transform = `translate(${tx}px, ${ty + enter}px) scale(${z * (0.94 + 0.06 * kIn) * (1 + 0.04 * kOut)})`
        s.phone.style.opacity = kIn * (1 - kOut)

        if (s.words) revealWords(s.words, lt - 0.05)
        s.scrim.style.opacity = kz * (1 - kOut)
        if (s.sub) {
          const k = ease.out(prog(lt, 0.45, 0.95))
          s.sub.style.opacity = k * (1 - kOut)
          s.sub.style.transform = `translateY(${(1 - k) * 24}px)`
        }
        const headline = s.root.querySelector('.headline')
        if (headline) headline.style.opacity = 1 - kOut

        if (s.tag) {
          const k = ease.out(prog(lt, 0.25, 0.75)) * (1 - kOut) * (1 - kz)
          const [x, y] = [s.rect.left + s.rect.w / 2, s.rect.top - (fmt === 'v' ? 56 : 48)]
          s.tag.style.opacity = k
          s.tag.style.left = `${x}px`
          s.tag.style.top = `${y}px`
          s.tag.style.transform = `translate(-50%, ${(1 - k) * 20}px)`
        }

        const scale = z * s.unit
        if (s.ringEl) {
          const r = s.ring
          const k = ease.out(prog(lt, r.at, r.at + 0.4)) * (1 - kOut)
          const [x, y] = screenToStage(s, r.x, r.y - sy, z, tx, ty + enter, ox, oy)
          Object.assign(s.ringEl.style, { left: `${x - 10}px`, top: `${y - 10}px`, width: `${r.w * scale + 20}px`, height: `${r.h * scale + 20}px`, opacity: k })
        }
        if (s.tapEl) {
          const tp = s.tap
          const k = prog(lt, tp.at, tp.at + 0.7)
          const [x, y] = screenToStage(s, tp.x, tp.y - sy, z, tx, ty + enter, ox, oy)
          const size = 40 + 150 * ease.out(k)
          Object.assign(s.tapEl.style, { left: `${x - size / 2}px`, top: `${y - size / 2}px`, width: `${size}px`, height: `${size}px`, opacity: k > 0 && k < 1 ? 1 - k * 0.8 : 0 })
        }
        s.pills.forEach((p) => {
          const k = ease.back(prog(lt, p.at, p.at + 0.5))
          const fade = 1 - kOut
          let x
          let y
          if (p.pos) [x, y] = p.pos[fmt] ?? p.pos.v
          else [x, y] = screenToStage(s, p.x, p.y - sy, z, tx, ty + enter, ox, oy)
          const half = p.node.offsetWidth / 2 + 30
          p.node.style.left = `${clamp(x, half, W - half)}px`
          p.node.style.top = `${clamp(y, 80, H - 80)}px`
          p.node.style.opacity = clamp(k) * fade
          p.node.style.transform = `translate(-50%, -50%) scale(${0.6 + 0.4 * k})`
        })
      }

      function render(t) {
        // Fond : halos qui dérivent
        halos.forEach((h, i) => {
          const a = t * 0.35 + i * 2.4
          h.style.transform = `translate(${W * (0.15 + 0.5 * i) + Math.cos(a) * W * 0.12 - W * 0.35}px, ${H * (0.1 + 0.55 * i) + Math.sin(a * 0.8) * H * 0.08 - H * 0.2}px)`
        })
        for (const s of shots) {
          const active = t >= s.start && t < s.end + (s === shots.at(-1) ? 1 : 0)
          s.root.style.display = active ? 'block' : 'none'
          if (!active) continue
          const lt = t - s.start
          s.root.style.opacity = 1
          if (s.type === 'hook') {
            revealWords(s.words, lt, 0.12, 0.5)
            const kOut = ease.inOut(prog(lt, s.dur - OUT, s.dur))
            s.root.style.opacity = 1 - kOut
            s.root.style.transform = `scale(${1 + 0.05 * prog(lt, 0, s.dur)})`
          } else if (s.type === 'phone') {
            renderPhone(s, lt)
          } else if (s.type === 'end') {
            const k = ease.out(prog(lt, 0, 0.6))
            s.root.style.background = `radial-gradient(circle at 50% 35%, ${brand.color}, ${brand.deep} 75%)`
            s.root.style.opacity = k
            s.logos.style.transform = `scale(${0.85 + 0.15 * ease.back(prog(lt, 0.1, 0.7))})`
            revealWords(s.words, lt - 0.4)
            if (s.cta) {
              const kc = ease.back(prog(lt, 1.1, 1.6))
              s.cta.style.opacity = clamp(kc)
              s.cta.style.transform = `scale(${0.7 + 0.3 * kc})`
            }
          }
        }
      }

      window.render = render
      window.DURATION = duration
      const images = [...document.images]
      window.MOTION_READY = Promise.all(images.map((img) => (img.complete ? null : new Promise((ok) => { img.onload = img.onerror = ok })))).then(() => document.fonts.ready)
      render(0)

      if (play) {
        const t0 = performance.now()
        const loop = () => { render(((performance.now() - t0) / 1000) % duration); requestAnimationFrame(loop) }
        requestAnimationFrame(loop)
      }
    },
  }

  window.Motion = Motion
})()
