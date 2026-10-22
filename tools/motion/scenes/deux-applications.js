// Vidéo phare : Jibiat Marchand (l'espace marchand) et Jibiat (l'entreprise de livraison) reliés
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Vous vendez.\nIls livrent.\n*Tout* est relié.' },
    {
      type: 'phone', dur: 4.6, app: 'marchand', src: 'assets/marchand-commandes.png',
      title: 'La commande arrive dans _Jibiat_ _Marchand_', sub: 'WhatsApp, Facebook, TikTok, boutique en ligne : tout au même endroit.',
      zoom: [1, 1.6], focus: [195, 560], ring: { at: 1.7, x: 16, y: 456, w: 358, h: 196 }, tap: { at: 3.3, x: 243, y: 624 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/jibiat-admin-courses.png',
      title: "Elle part chez l'entreprise de livraison *en un clic*", sub: 'Aucune saisie en double.',
      zoom: [1, 1.55], focus: [195, 420], ring: { at: 1.5, x: 16, y: 324, w: 358, h: 192 },
      pill: { at: 2.2, text: 'Nouvelle course reçue', x: 195, y: 300 },
    },
    {
      type: 'phone', dur: 3.8, app: 'livreur', src: 'assets/jibiat-livreur-missions.png',
      title: 'Le livreur la reçoit *sur son téléphone*',
      zoom: [1, 1.7], focus: [195, 284], ring: { at: 1.4, x: 16, y: 232, w: 358, h: 104 },
      pill: { at: 2.0, text: '3 colis à ramasser', x: 195, y: 372 },
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/marchand-accueil.png',
      title: 'Statuts et argent reviennent *tout seuls*', sub: 'Livré, encaissé, reversé : vous le voyez sans appeler personne.',
      zoom: [1, 1.6], focus: [110, 425], ring: { at: 1.5, x: 16, y: 362, w: 173, h: 127 },
      pills: [{ at: 2.1, text: '✓ Livrée', x: 280, y: 330 }, { at: 2.6, text: '+ 45 000 F', x: 280, y: 520 }],
    },
    { type: 'end', dur: 3.8, brands: ['marchand', 'jibiat'], tagline: 'Vendez. Livrez. *Encaissez.*', cta: 'Essai gratuit' },
  ],
}
