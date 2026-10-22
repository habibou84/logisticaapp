// Enregistrement des vidéos de démonstration : un « segment » = un appareil (téléphone ou ordinateur)
// connecté avec un compte, filmé image par image (CDP screencast) avec le minutage des étapes.
const fs = require('fs');
const path = require('path');

function playwright() {
  try {
    return require('playwright');
  } catch {
    return require(path.join(process.env.PLAYWRIGHT_MODULES || '', 'playwright'));
  }
}

const HOST = process.env.VIDEO_HOST || 'http://livraison-express-ci.jibiat.com:8124';
const CHROMIUM = process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium';

// Comptes de démonstration (database/seeders/DemoSeeder.php, mot de passe « password »)
const ACCOUNTS = {
  admin: { login: '/admin/connexion', phone: '0700000001', home: '**/admin' },
  courier: { login: '/livreur/connexion', phone: '0700000004', home: '**/livreur' },
  merchant: { login: '/', phone: '0500000001', home: '**/marchand' },
};

const DEVICES = {
  // Téléphone : 390 × 844 filmé en 780 × 1688
  phone: { viewport: { width: 390, height: 844 }, scale: 2, isMobile: true },
  // Ordinateur : 1280 × 720 filmé en 1920 × 1080
  desktop: { viewport: { width: 1280, height: 720 }, scale: 1.5, isMobile: false },
};

// Point orange à chaque toucher ; sur ordinateur, flèche de souris visible
function overlays(device) {
  const desktop = device === 'desktop';
  return `(() => {
    const desktop = ${desktop};
    const ready = (fn) => document.body ? fn() : document.addEventListener('DOMContentLoaded', fn);
    document.addEventListener('pointerdown', (e) => {
      const d = document.createElement('div');
      const r = desktop ? 18 : 22;
      d.style.cssText = 'position:fixed;left:' + (e.clientX - r) + 'px;top:' + (e.clientY - r) + 'px;width:' + 2 * r + 'px;height:' + 2 * r + 'px;border-radius:50%;background:rgba(249,111,2,.35);border:3px solid rgba(249,111,2,.9);z-index:2147483646;pointer-events:none;transition:transform .45s ease-out,opacity .45s ease-out;';
      document.body.appendChild(d);
      requestAnimationFrame(() => { d.style.transform = 'scale(1.6)'; d.style.opacity = '0'; });
      setTimeout(() => d.remove(), 600);
    }, true);
    if (!desktop) return;
    ready(() => {
      const c = document.createElement('div');
      c.id = '__cursor';
      c.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24"><path d="M4 2l16 10.5-7.2 1.4 4.3 7.6-3 1.6-4.3-7.7L4 20z" fill="#111" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
      c.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;transform:translate(' + (window.__cx || 640) + 'px,' + (window.__cy || 500) + 'px);';
      document.body.appendChild(c);
      document.addEventListener('mousemove', (e) => {
        window.__cx = e.clientX; window.__cy = e.clientY;
        c.style.transform = 'translate(' + (e.clientX - 3) + 'px,' + (e.clientY - 2) + 'px)';
      }, true);
    });
  })();`;
}

class Segment {
  constructor(dir, page, cdp, device) {
    this.dir = dir;
    this.page = page;
    this.cdp = cdp;
    this.device = device;
    this.frames = [];
    this.marks = [];
    this.mouse = { x: 640, y: 500 };
  }

  async start() {
    const framesDir = path.join(this.dir, 'frames');
    fs.rmSync(framesDir, { recursive: true, force: true });
    fs.mkdirSync(framesDir, { recursive: true });
    let t0 = null;
    this.cdp.on('Page.screencastFrame', ({ data, metadata, sessionId }) => {
      const ts = metadata.timestamp * 1000;
      if (t0 === null) t0 = ts;
      const file = path.join(framesDir, `f${String(this.frames.length).padStart(5, '0')}.jpg`);
      fs.writeFileSync(file, Buffer.from(data, 'base64'));
      this.frames.push({ file, t: ts - t0 });
      this.cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
    });
    const { viewport, scale } = DEVICES[this.device];
    await this.cdp.send('Page.startScreencast', {
      format: 'jpeg', quality: 92, everyNthFrame: 1,
      maxWidth: Math.round(viewport.width * scale), maxHeight: Math.round(viewport.height * scale),
    });
    this.startedAt = Date.now();
  }

  mark(id) {
    this.marks.push({ id, t: Date.now() - this.startedAt });
  }

  wait(ms) {
    return this.page.waitForTimeout(ms);
  }

  // Toucher (téléphone) ou clic avec déplacement visible de la souris (ordinateur)
  async tap(selector, options = {}) {
    const target = typeof selector === 'string' ? this.page.locator(selector).first() : selector;
    await target.scrollIntoViewIfNeeded();
    if (this.device === 'desktop') {
      const box = await target.boundingBox();
      const x = box.x + box.width / 2;
      const y = box.y + box.height / 2;
      await this.page.mouse.move(x, y, { steps: options.steps || 25 });
      this.mouse = { x, y };
      await this.wait(250);
    }
    await target.click();
  }

  // Saisie lettre par lettre
  async type(selector, text, delay = 85) {
    await this.tap(selector);
    await this.page.locator(selector).first().pressSequentially(text, { delay });
  }

  // Défilement doux d'un élément vers le centre de l'écran
  async scrollTo(selector, ms = 1400) {
    await this.page.evaluate((sel) => document.querySelector(sel)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), selector);
    await this.wait(ms);
  }

  async stop() {
    this.mark('end');
    await this.cdp.send('Page.stopScreencast');
    await this.wait(200);
    fs.writeFileSync(path.join(this.dir, 'timeline.json'), JSON.stringify({ device: this.device, frames: this.frames, marks: this.marks }));
    console.log(`  ${path.basename(this.dir)} : ${this.frames.length} images, ${(this.marks.at(-1).t / 1000).toFixed(1)} s`);
    await this.browser.close();
  }
}

/**
 * Ouvre un appareil connecté avec un compte, sur la page voulue, puis lance la caméra.
 * options : { device: 'phone'|'desktop', account: 'admin'|'courier'|'merchant', path, setup(page), geolocation }
 */
async function segment(outDir, id, options) {
  const { device, account, setup } = options;
  const { viewport, scale, isMobile } = DEVICES[device];
  const browser = await playwright().chromium.launch({
    executablePath: CHROMIUM,
    args: ['--host-resolver-rules=MAP *.jibiat.com 127.0.0.1', `--force-device-scale-factor=${scale}`],
  });
  const context = await browser.newContext({
    viewport, deviceScaleFactor: scale, isMobile, locale: 'fr-FR', timezoneId: 'Africa/Abidjan',
    geolocation: { latitude: 5.3598, longitude: -3.9875 }, permissions: ['geolocation'],
  });
  await context.addInitScript(overlays(device));
  const page = await context.newPage();

  // Connexion hors caméra
  const acc = ACCOUNTS[account];
  await page.goto(HOST + acc.login);
  await page.fill('input[type="tel"], input[autocomplete="username"]', acc.phone);
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL(acc.home);
  if (options.path) {
    await page.goto(HOST + options.path);
  }
  await page.waitForLoadState('networkidle');
  if (setup) await setup(page);
  if (device === 'desktop') await page.mouse.move(640, 500);
  await page.waitForTimeout(1200);

  const dir = path.join(outDir, id);
  const seg = new Segment(dir, page, await context.newCDPSession(page), device);
  seg.browser = browser;
  seg.host = HOST;
  await seg.start();
  return seg;
}

module.exports = { segment, HOST };
