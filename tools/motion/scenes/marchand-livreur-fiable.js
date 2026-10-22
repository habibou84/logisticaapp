// E-commerçants : trouver une entreprise de livraison fiable et s'y connecter en un clic
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Votre livreur *ne répond plus* ?' },
    {
      type: 'phone', dur: 5, app: 'marchand', src: 'assets/marchand-entreprises-long.png',
      title: 'Choisissez une entreprise *fiable*', sub: 'Réussite et délai réels des 90 derniers jours, calculés par l’application.',
      zoom: [1, 1.7], focus: [195, 690], ring: { at: 1.6, x: 30, y: 650, w: 330, h: 68 },
      pills: [{ at: 2.3, text: '94 % livrés', x: 110, y: 610 }, { at: 2.8, text: '2 h en moyenne', x: 290, y: 760 }],
    },
    {
      type: 'phone', dur: 3.8, app: 'marchand', src: 'assets/marchand-entreprises-long.png',
      title: 'Touchez *Connecter*', sub: 'Pas de clé à copier, pas de technicien.',
      zoom: [1, 1.6], focus: [195, 700], tap: { at: 2.4, x: 195, y: 774 },
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/jibiat-connexion-autoriser.png',
      title: 'Vous autorisez, *c’est connecté*',
      zoom: [1, 1.45], focus: [195, 560], tap: { at: 2.4, x: 195, y: 604 },
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/marchand-commandes.png',
      title: 'Vos commandes partent *en un clic*', sub: 'Et vous suivez chaque colis jusqu’à la porte du client.',
      zoom: [1, 1.6], focus: [195, 560], ring: { at: 1.5, x: 16, y: 456, w: 358, h: 196 }, tap: { at: 2.9, x: 243, y: 624 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand', 'jibiat'], tagline: 'Des livreurs fiables, *en un clic*', cta: 'Créer mon espace marchand gratuitement' },
  ],
}
