// « Les commandes par WhatsApp » — 9:16, conversation avec le bot (simulateur de l'administration)
exports.spec = {
  name: 'jibiat-commandes-par-whatsapp',
  format: 'vertical',
  intro: [['title', 'Les commandes par WhatsApp'], ['subtitle', "Vos e-commerçants créent leurs courses en écrivant à l'entreprise"]],
  steps: { forward: 'Étape 1', recap: 'Étape 2', confirm: 'Étape 3' },
  captions: {
    hello: "L'e-commerçant écrit au WhatsApp de l'entreprise",
    menu: 'Le bot lui répond aussitôt',
    forward: 'Il colle le message de sa cliente, tel quel',
    recap: 'Le bot comprend et calcule le tarif',
    confirm: "Un toucher : la course est créée et arrive à l'agence",
    point: 'Il peut aussi demander son point du jour',
  },
};

// Le simulateur affiché comme une conversation WhatsApp plein écran
const CSS = `
  .card.flex.flex-col{position:fixed!important;inset:0!important;height:100vh!important;z-index:60;border-radius:0!important;border:0!important}
  .card.flex.flex-col .text-xs.opacity-80{display:none}
`;

exports.record = async (rec) => {
  const s = await rec.segment('whatsapp', {
    device: 'phone',
    account: 'admin',
    path: '/admin/whatsapp/simulateur',
    setup: async (page) => {
      const options = await page.locator('#sender option').allTextContents();
      await page.selectOption('#sender', { index: options.findIndex((o) => o.includes('Boutique Chic')) });
      await page.addStyleTag({ content: CSS });
    },
  });
  const page = s.page;
  const send = async () => {
    await s.tap('form button:has-text("Envoyer")');
    await page.waitForFunction(() => !document.body.innerText.includes('Le bot écrit'));
  };

  s.mark('hello'); await s.wait(800);
  await s.type('textarea', 'Bonjour', 120);
  await s.wait(400);
  await send();
  await s.wait(600);

  s.mark('menu'); await s.wait(1800);
  await s.tap('button:has-text("Nouvelle course")');
  await page.waitForFunction(() => !document.body.innerText.includes('Le bot écrit'));
  await s.wait(2600);

  s.mark('forward');
  await s.tap('textarea');
  await page.fill('textarea', "Bonsoir, c'est pour Awa à Yopougon Siporex face à la pharmacie, son numéro 07 08 09 10 11, total 12 500 F livraison à la charge du client");
  await s.wait(1600);
  await send();

  s.mark('recap'); await s.wait(4200);

  s.mark('confirm');
  await s.tap(page.locator('button:has-text("Confirmer")').last());
  await page.waitForFunction(() => !document.body.innerText.includes('Le bot écrit'));
  await s.wait(3600);

  s.mark('point');
  await s.type('textarea', 'Point', 120);
  await send();
  await s.wait(3800);
  await s.stop();
};
