// Entreprises de livraison : les points de caisse des livreurs en deux minutes
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Vos livreurs ont encaissé *combien* ?' },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-caisse.png',
      title: 'L’argent chez chaque livreur, *au franc près*', sub: 'Calculé à chaque livraison, sans cahier.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 383, w: 362, h: 226 },
      pill: { at: 2.3, text: '542 500 F à verser', x: 195, y: 660 },
    },
    {
      type: 'phone', dur: 4.8, app: 'jibiat', src: 'assets/j-caisse-versement.png',
      title: 'Il verse, vous cochez, *c’est validé*', sub: 'Frais et avances du livreur déjà déduits.',
      zoom: [1, 1.45], focus: [195, 470], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 248 },
      tap: { at: 3.4, x: 195, y: 700 },
    },
    {
      type: 'phone', dur: 4, app: 'livreur', src: 'assets/j-livreur-caisse.png',
      title: 'Le livreur voit *ce qu’il doit*', sub: 'Et ce qu’il a gagné aujourd’hui.',
      zoom: [1, 1.45], focus: [195, 200], ring: { at: 1.3, x: 14, y: 70, w: 362, h: 108 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Le point de caisse *en deux minutes*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
