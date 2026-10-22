// « Vos statistiques » — 16:9, administration sur ordinateur
// Avant : base de démonstration (php tools/videos/demo-data.php)
exports.spec = {
  name: 'jibiat-vos-statistiques',
  format: 'horizontal',
  intro: [['title', 'Vos statistiques'], ['subtitle', 'Pilotez votre entreprise de livraison avec des chiffres fiables']],
  captions: {
    overview: "Votre activité du mois en un coup d'œil",
    chart: 'Courses créées et livrées, jour après jour',
    couriers: 'Qui livre le plus, et le mieux ?',
    merchants: 'Vos e-commerçants les plus actifs',
    zones: 'Les communes où vous livrez le plus',
    finances: 'Chiffre d\'affaires, dépenses et marge',
  },
};

exports.record = async (rec) => {
  const s = await rec.segment('statistiques', { device: 'desktop', account: 'admin', path: '/admin/statistiques', address: 'livraison-express-ci.jibiat.com/admin/statistiques' });
  const page = s.page;
  const tab = async (key) => {
    await s.tap(`button[data-tab="${key}"]`);
    await page.waitForLoadState('networkidle');
    await s.wait(900);
    await page.mouse.move(640, 480, { steps: 25 });
  };

  s.mark('overview');
  await s.wait(800);
  await page.mouse.move(420, 230, { steps: 30 });
  await s.wait(600);
  await page.mouse.move(900, 230, { steps: 50 });
  await s.wait(2200);

  s.mark('chart');
  await page.mouse.wheel(0, 330);
  await s.wait(1200);
  await page.mouse.move(820, 520, { steps: 40 });
  await s.wait(2000);
  await page.mouse.wheel(0, -330);
  await s.wait(600);

  s.mark('couriers');
  await tab('couriers');
  await s.wait(2400);

  s.mark('merchants');
  await tab('merchants');
  await s.wait(2400);

  s.mark('zones');
  await tab('zones');
  await s.wait(2800);

  s.mark('finances');
  await tab('finances');
  await s.wait(1200);
  await page.mouse.wheel(0, 300);
  await s.wait(2400);
  await s.stop();
};
