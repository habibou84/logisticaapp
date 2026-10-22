// « Une course de A à Z » — 16:9 : l'e-commerçant, l'agence, le livreur, la caisse, puis l'e-commerçant payé
// Avant : base de démonstration (php tools/videos/demo-data.php) puis php tools/videos/prepare.php az
exports.spec = {
  name: 'jibiat-une-course-de-a-a-z',
  format: 'horizontal',
  intro: [['title', 'Une course de A à Z'], ['subtitle', "De la commande au paiement de l'e-commerçant, tout se suit dans Jibiat"]],
  steps: { create: 'Étape 1', validate: 'Étape 2', assign: 'Étape 3', pickup: 'Étape 4', deliver: 'Étape 5', remit: 'Étape 6', payout: 'Étape 7' },
  captions: {
    create: "L'e-commerçant crée la course sur son téléphone",
    created: "C'est envoyé : l'agence la reçoit aussitôt",
    validate: "L'agence voit la course arriver et la valide",
    assign: 'Elle choisit le livreur, en un clic',
    pickup: 'Koffi accepte et récupère le colis',
    deliver: "Il livre et encaisse l'argent du client",
    remit: "Le soir, Koffi verse l'argent à la caisse",
    payout: "L'e-commerçant est payé, relevé à l'appui",
    paid: 'Il voit son paiement, course par course',
  },
};

const RECIPIENT = 'Mariam Touré';

exports.record = async (rec) => {
  // 1. L'e-commerçant crée la course
  let s = await rec.segment('marchand', { device: 'phone', account: 'merchant' });
  let page = s.page;
  s.mark('create'); await s.wait(800);
  await s.tap('a[href="/marchand/courses/nouvelle"]');
  await s.wait(700);
  await s.type('#phone', '07' + String(Math.floor(10000000 + Math.random() * 89999999)), 60);
  await s.type('#name', RECIPIENT, 60);
  await s.wait(300);
  await s.tap('button:has-text("Continuer")');
  await s.wait(600);
  await page.locator('#zone-search').pressSequentially('Marc', { delay: 90 });
  await s.wait(300);
  await s.tap('button:has-text("Marcory")');
  await s.type('#address', 'Zone 4, rue du Canal', 60);
  await s.wait(300);
  await s.tap('button:has-text("Continuer")');
  await s.wait(600);
  await s.tap('#amount');
  await page.fill('#amount', '');
  await page.locator('#amount').pressSequentially('18000', { delay: 90 });
  await s.tap('#fee-payer >> text=Le client');
  await s.type('#desc', 'Sac à main', 60);
  await s.scrollTo('.m-card', 900);
  await s.tap('button:has-text("Continuer")');
  await s.wait(1500);
  await s.tap('button:has-text("Confirmer la course")');
  await page.waitForSelector('text=Course enregistrée');
  s.mark('created'); await s.wait(2600);
  await s.stop();

  // 2. L'agence valide et assigne
  s = await rec.segment('agence', { device: 'desktop', account: 'admin', path: '/admin/courses', address: 'livraison-express-ci.jibiat.com/admin/courses' });
  page = s.page;
  s.mark('validate'); await s.wait(1400);
  await s.tap(page.locator('tbody tr', { hasText: RECIPIENT }).first());
  await page.waitForURL('**/admin/courses/*');
  await s.wait(1600);
  await s.tap('button:has-text("Valider")');
  await page.waitForSelector('text=Validée');
  await s.wait(1000);

  s.mark('assign');
  const selects = page.locator('select:has(option:text("Choisir un livreur"))');
  for (const i of [0, 1]) {
    const select = selects.nth(i);
    await s.tap(select);
    const koffi = (await select.locator('option').allTextContents()).find((o) => o.includes('Koffi'));
    await select.selectOption({ label: koffi });
    await s.wait(400);
    await s.tap(select.locator('xpath=following-sibling::button'));
    await page.waitForLoadState('networkidle');
    await s.wait(900);
  }
  await s.wait(1200);
  await s.stop();

  // 3. Le livreur ramasse, livre et encaisse
  s = await rec.segment('livreur', { device: 'phone', account: 'courier' });
  page = s.page;
  s.mark('pickup'); await s.wait(1000);
  await s.tap(page.locator('a[href^="/livreur/missions/"]').first());
  await s.wait(1200);
  await s.tap('button:has-text("Accepter")');
  await s.wait(800);
  await s.tap('button:has-text("Je pars chercher")');
  await s.wait(1000);
  await s.tap('button:has-text("récupéré")');
  await page.waitForURL('**/livreur');
  await s.wait(900);

  s.mark('deliver');
  await s.tap('button:has-text("Livrer")');
  await s.wait(700);
  await s.tap(page.locator('a[href^="/livreur/missions/"]', { hasText: RECIPIENT }).first());
  await s.wait(1000);
  await s.tap('button:has-text("Accepter")');
  await s.wait(700);
  await s.tap('button:has-text("Je pars livrer")');
  await s.wait(1000);
  await s.tap('button:has-text("Colis livré")');
  await s.wait(1300);
  await s.tap('button:has-text("Confirmer la livraison")');
  await s.wait(2200);
  await s.stop();

  // 4. La caisse reçoit l'argent et paie l'e-commerçant
  s = await rec.segment('caisse', { device: 'desktop', account: 'admin', path: '/admin/caisse', address: 'livraison-express-ci.jibiat.com/admin/caisse' });
  page = s.page;
  s.mark('remit'); await s.wait(1000);
  await s.tap(page.locator('tr', { hasText: 'Koffi' }).locator('button:has-text("Recevoir le versement")'));
  await s.wait(1600);
  await s.tap('button:has-text("Valider le versement")');
  await s.wait(1600);

  s.mark('payout');
  await s.tap('button:has-text("Soldes marchands")');
  await page.waitForSelector('tr:has-text("Boutique Chic")');
  await s.wait(900);
  await s.tap(page.locator('tr', { hasText: 'Boutique Chic' }).locator('button:has-text("Préparer le reversement")'));
  await page.waitForURL('**/caisse/reversements/*');
  await s.wait(1400);
  await s.scrollTo('#pay-method', 900);
  await page.selectOption('#pay-method', 'wave');
  await s.type('#pay-ref', 'WV-90417', 70);
  await s.tap('form button:has-text("Marquer comme payé")');
  await s.wait(2000);
  await s.stop();

  // 5. L'e-commerçant voit son paiement
  s = await rec.segment('paiement', { device: 'phone', account: 'merchant', path: '/marchand/paiements' });
  page = s.page;
  s.mark('paid'); await s.wait(1000);
  await s.tap('button:has-text("Mes paiements")');
  await s.wait(1200);
  await s.tap(page.locator('a[href^="/marchand/paiements/"]').first());
  await s.wait(1800);
  await page.evaluate(() => window.scrollBy({ top: 360, behavior: 'smooth' }));
  await s.wait(2200);
  await s.stop();
};
