// E-commerçants : la boutique en ligne qui vend même la nuit, de l'achat du client à la commande chez le marchand
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Votre boutique vend *même quand vous dormez*' },
    {
      type: 'phone', dur: 3.6, app: 'boutique', src: 'assets/bs-accueil.png',
      title: 'Votre boutique, *à votre image*', sub: 'Votre nom, vos photos, vos produits.',
      zoom: [1, 1.3], focus: [195, 260], ring: { at: 1.2, x: 0, y: 94, w: 390, h: 218 },
    },
    {
      type: 'phone', dur: 3.4, app: 'boutique', src: 'assets/bs-nouveautes.png',
      title: 'Le client *choisit*', sub: 'Jour et nuit, depuis son téléphone.',
      zoom: [1, 1.25], focus: [195, 300], tap: { at: 2.2, x: 100, y: 210 },
    },
    {
      type: 'phone', dur: 3.6, app: 'boutique', src: 'assets/bs-produit.png',
      title: 'Photo, taille, couleur, *prix clair*',
      zoom: [1, 1.3], focus: [195, 520], ring: { at: 1.3, x: 12, y: 560, w: 366, h: 240 },
    },
    {
      type: 'phone', dur: 3.2, app: 'boutique', src: 'assets/bs-ajout.png',
      title: '*Ajouté au panier*',
      zoom: [1, 1.3], focus: [195, 470], tap: { at: 0.9, x: 195, y: 466 }, ring: { at: 1.6, x: 16, y: 716, w: 358, h: 52 },
    },
    {
      type: 'phone', dur: 3.2, app: 'boutique', src: 'assets/bs-panier.png',
      title: 'Il commande *en un toucher*',
      zoom: [1, 1.3], focus: [195, 470], tap: { at: 1.8, x: 195, y: 630 }, ring: { at: 1, x: 16, y: 600, w: 358, h: 60 },
    },
    {
      type: 'phone', dur: 3.6, app: 'boutique', src: 'assets/bs-commande.png',
      title: 'Son nom, son quartier, *c’est tout*', sub: 'Pas de compte à créer.',
      zoom: [1, 1.25], focus: [195, 330], ring: { at: 1.2, x: 12, y: 150, w: 366, h: 330 },
    },
    {
      type: 'phone', dur: 3.6, app: 'boutique', src: 'assets/bs-recap.png',
      title: 'Livraison calculée, *paiement à la livraison*',
      zoom: [1, 1.3], focus: [195, 400], ring: { at: 1, x: 24, y: 368, w: 342, h: 40 }, tap: { at: 2.5, x: 195, y: 540 },
      pill: { at: 1.6, text: '16 500 F', x: 300, y: 250 },
    },
    {
      type: 'phone', dur: 3.2, app: 'boutique', src: 'assets/bs-merci.png',
      title: 'Commande reçue, *même à 3 h du matin*',
      zoom: [1, 1.35], focus: [195, 300],
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/bs-gestion.png',
      title: 'Le matin, *elle vous attend*', sub: 'Un toucher pour confirmer, et la livraison part.',
      zoom: [1, 1.4], focus: [195, 540], ring: { at: 1.2, x: 16, y: 456, w: 358, h: 188 }, tap: { at: 2.8, x: 240, y: 586 },
      pill: { at: 2, text: 'Nouvelle commande', x: 195, y: 420 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Elle vend *même quand vous dormez*', cta: '1 mois de Pro offert' },
  ],
}
