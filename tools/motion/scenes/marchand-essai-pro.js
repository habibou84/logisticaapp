// E-commerçants : un mois d'essai gratuit de l'offre Pro
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: '*1 mois* de Pro offert' },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/vendre-abonnement.png',
      title: 'Toute l’offre Pro, *gratuite un mois*', sub: 'Sans carte bancaire, sans engagement.',
      zoom: [1, 1.5], focus: [195, 220], ring: { at: 1.4, x: 14, y: 78, w: 362, h: 322 },
      pill: { at: 2.4, text: 'Essai gratuit 30 jours', x: 195, y: 470 },
    },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/vendre-abonnement-2.png',
      title: 'L’assistant, WhatsApp, *les statistiques*', sub: '300 actions de l’assistant, votre nom de domaine, le paiement en ligne.',
      zoom: [1, 1.45], focus: [195, 240], ring: { at: 1.4, x: 30, y: 60, w: 330, h: 348 },
    },
    {
      type: 'phone', dur: 4, app: 'marchand', src: 'assets/ia-commande-remplie.png',
      title: 'Vos commandes *en 10 secondes*',
      zoom: [1, 1.4], focus: [195, 400],
    },
    { type: 'end', dur: 4, brands: ['marchand'], tagline: 'Essayez Pro *gratuitement*', cta: 'jibiat-marchand.com' },
  ],
}
