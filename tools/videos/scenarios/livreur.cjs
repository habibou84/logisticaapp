// « La journée du livreur » — 9:16, application livreur
// Avant : php tools/videos/prepare.php courier
exports.spec = {
  name: 'jibiat-journee-du-livreur',
  format: 'vertical',
  intro: [['title', 'La journée du livreur'], ['subtitle', "L'application livreur de Jibiat"]],
  steps: { accept: 'Étape 1', pickup: 'Étape 2', deliver: 'Étape 3', collect: 'Étape 4' },
  captions: {
    missions: 'Le matin, Koffi trouve ses missions du jour',
    accept: 'Il accepte le ramassage chez la boutique',
    pickup: 'Sur place, il confirme : colis récupéré',
    deliver: 'Il part livrer : le client est prévenu sur WhatsApp',
    collect: "Colis livré : il indique l'argent encaissé",
    cash: 'Sa caisse et ses gains se mettent à jour, sans calcul',
  },
};

exports.record = async (rec) => {
  const s = await rec.segment('livreur', { device: 'phone', account: 'courier' });
  const page = s.page;

  s.mark('missions'); await s.wait(2600);

  s.mark('accept');
  await s.tap(page.locator('a[href^="/livreur/missions/"]').first());
  await s.wait(1600);
  await s.tap('button:has-text("Accepter")');
  await s.wait(1200);
  await s.tap('button:has-text("Je pars chercher")');
  await s.wait(1500);

  s.mark('pickup'); await s.wait(800);
  await s.tap('button:has-text("récupéré")');
  await page.waitForURL('**/livreur');
  await s.wait(1400);

  s.mark('deliver');
  await s.tap('button:has-text("Livrer")');
  await s.wait(1000);
  await s.tap(page.locator('a[href^="/livreur/missions/"]', { hasText: 'Awa Koné' }).first());
  await s.wait(1500);
  await s.tap('button:has-text("Accepter")');
  await s.wait(1000);
  await s.tap('button:has-text("Je pars livrer")');
  await s.wait(1800);

  s.mark('collect');
  await s.tap('button:has-text("Colis livré")');
  await s.wait(1500);
  await s.tap('button:has-text("Espèces")');
  await s.wait(1000);
  await s.tap('button:has-text("Confirmer la livraison")');
  await s.wait(2200);

  s.mark('cash');
  await s.tap('a[href="/livreur/caisse"]');
  await s.wait(3800);
  await s.stop();
};
