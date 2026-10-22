// E-commerçants : l'assistant propose la réponse au client, avec les vrais prix, stock et frais
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: '50 messages *sans réponse* ?' },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/ia-reponse-avant.png',
      title: 'Le client *pose sa question*', sub: 'Taille, prix, livraison…',
      zoom: [1, 1.5], focus: [195, 300], ring: { at: 1.3, x: 40, y: 205, w: 262, h: 140 }, tap: { at: 3, x: 128, y: 564 },
    },
    {
      type: 'phone', dur: 5, app: 'marchand', src: 'assets/ia-reponse.png',
      title: 'L’assistant *prépare la réponse*', sub: 'Avec vos vrais prix, votre stock et vos frais de livraison.',
      zoom: [1, 1.55], focus: [195, 600], ring: { at: 1.5, x: 24, y: 488, w: 248, h: 222 },
      pills: [{ at: 2.6, text: 'Taille M disponible', x: 150, y: 450 }, { at: 3.1, text: 'Livraison 1 500 F', x: 250, y: 760 }],
    },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-reponse.png',
      title: 'Vous relisez, *vous envoyez*',
      zoom: [1, 1.5], focus: [250, 600], tap: { at: 2.2, x: 335, y: 425 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Répondez vite, *vendez plus*', cta: '1 mois de Pro offert' },
  ],
}
