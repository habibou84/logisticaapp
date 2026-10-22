// « L'e-commerçant crée sa course » — 9:16, application e-commerçant
exports.spec = {
  name: 'jibiat-ecommercant-cree-sa-course',
  format: 'vertical',
  intro: [['title', 'Créer une course en 30 secondes'], ['subtitle', "L'application e-commerçant de Jibiat"]],
  steps: { client: 'Étape 1', zone: 'Étape 2', montant: 'Étape 3', recap: 'Étape 4' },
  captions: {
    home: "Depuis l'accueil, l'e-commerçant touche « + »",
    client: 'Le numéro et le nom du client',
    zone: "La commune et l'adresse de livraison",
    montant: 'Le montant à encaisser et qui paie la livraison',
    recap: 'Le tarif est calculé : il vérifie et confirme',
    done: "C'est enregistré ! Le client a son code de livraison",
  },
};

exports.record = async (rec) => {
  const s = await rec.segment('marchand', { device: 'phone', account: 'merchant' });
  const page = s.page;

  s.mark('home'); await s.wait(2200);
  await s.tap('a[href="/marchand/courses/nouvelle"]');
  await s.wait(900);

  s.mark('client');
  // Numéro différent à chaque prise : pas de suggestion d'un ancien destinataire
  await s.type('#phone', '07' + String(Math.floor(10000000 + Math.random() * 89999999)));
  await s.wait(300);
  await s.type('#name', 'Awa Koné');
  await s.wait(700);
  await s.tap('button:has-text("Continuer")');
  await s.wait(800);

  s.mark('zone');
  await page.locator('#zone-search').pressSequentially('Yop', { delay: 110 });
  await s.wait(500);
  await s.tap('button:has-text("Yopougon")');
  await s.wait(400);
  await s.type('#address', 'Siporex, près du marché');
  await s.wait(700);
  await s.tap('button:has-text("Continuer")');
  await s.wait(800);

  s.mark('montant');
  await s.tap('#amount');
  await page.fill('#amount', '');
  await page.locator('#amount').pressSequentially('15000', { delay: 120 });
  await s.wait(400);
  await s.tap('#fee-payer >> text=Le client');
  await s.wait(500);
  await s.type('#desc', '2 robes');
  await s.wait(400);
  await s.scrollTo('.m-card', 1800);
  await s.tap('button:has-text("Continuer")');
  await s.wait(900);

  s.mark('recap'); await s.wait(2400);
  await s.tap('button:has-text("Confirmer la course")');
  await page.waitForSelector('text=Course enregistrée');
  await s.wait(300);
  s.mark('done'); await s.wait(3200);
  await s.stop();
};
