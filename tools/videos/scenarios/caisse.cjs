// « Le point de caisse en 2 minutes » — 16:9, administration sur ordinateur
// Avant : base de démonstration (php tools/videos/demo-data.php)
exports.spec = {
  name: 'jibiat-point-de-caisse',
  format: 'horizontal',
  intro: [['title', 'Le point de caisse en 2 minutes'], ['subtitle', "Fini les cahiers et les calculs du soir : l'argent de chaque course est suivi"]],
  steps: { open: 'Étape 1', check: 'Étape 2', validate: 'Étape 3', payout: 'Étape 4', paid: 'Étape 5' },
  captions: {
    overview: "Ce que chaque livreur a encaissé, en temps réel",
    open: 'Le livreur passe à la caisse',
    check: 'Chaque encaissement est détaillé, course par course',
    validate: 'On saisit le montant reçu et on valide',
    merchants: 'Les soldes des e-commerçants sont à jour',
    payout: 'Un clic : le relevé de reversement est prêt',
    paid: "Payé par Wave : l'e-commerçant est prévenu",
  },
};

exports.record = async (rec) => {
  const s = await rec.segment('caisse', { device: 'desktop', account: 'admin', path: '/admin/caisse', address: 'livraison-express-ci.jibiat.com/admin/caisse' });
  const page = s.page;

  s.mark('overview');
  await s.wait(1200);
  await page.mouse.move(470, 300, { steps: 30 });
  await page.mouse.move(470, 420, { steps: 40 });
  await s.wait(1200);

  s.mark('open');
  await s.tap(page.locator('tr', { hasText: 'Yacouba' }).locator('button:has-text("Recevoir le versement")'));
  await s.wait(1800);

  s.mark('check');
  const list = page.locator('input[type="checkbox"]').first();
  const box = await list.boundingBox();
  await page.mouse.move(box.x + 300, box.y + 60, { steps: 25 });
  for (let i = 0; i < 4; i++) { await page.mouse.wheel(0, 70); await s.wait(350); }
  await s.wait(1000);

  s.mark('validate');
  const amount = page.locator('#received');
  await s.tap(amount);
  await amount.fill('');
  const expected = await page.locator('text=Montant attendu').textContent();
  await amount.pressSequentially(expected.replace(/\D/g, ''), { delay: 120 });
  await s.wait(700);
  await s.tap('button:has-text("Valider le versement")');
  await s.wait(2200);

  s.mark('merchants');
  await s.tap('button:has-text("Soldes marchands")');
  await page.waitForSelector('tr:has-text("Mode Akwaba")');
  await s.wait(2600);

  s.mark('payout');
  await s.tap(page.locator('tr', { hasText: 'Mode Akwaba' }).locator('button:has-text("Préparer le reversement")'));
  await page.waitForURL('**/caisse/reversements/*');
  await s.wait(1500);
  await page.mouse.move(900, 600, { steps: 20 });
  await page.mouse.wheel(0, 220);
  await s.wait(1800);

  s.mark('paid');
  await s.scrollTo('#pay-method', 1200);
  await s.tap('#pay-method');
  await page.selectOption('#pay-method', 'wave');
  await s.wait(500);
  await s.type('#pay-ref', 'WV-58213', 90);
  await s.wait(500);
  await s.tap('form button:has-text("Marquer comme payé")');
  await s.wait(3000);
  await s.stop();
};
