// Entreprises de livraison : trouver des clients, puis la productivité de Jibiat (vidéo principale de jibiat.com)
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Trouvez des clients. *Livrez plus.*' },
    {
      type: 'phone', dur: 4, app: 'annuaire', src: 'assets/j-annuaire.png',
      title: 'Les marchands de Jibiat Marchand *vous trouvent*', sub: 'Ils cherchent un livreur et voient vos vrais résultats.',
      zoom: [1, 1.45], focus: [195, 200], ring: { at: 1.3, x: 24, y: 40, w: 342, h: 330 },
    },
    {
      type: 'phone', dur: 3.8, app: 'jibiat', src: 'assets/j-demandes.png',
      title: 'Leurs colis *arrivent chez vous*', sub: 'Un toucher pour accepter un nouveau client.',
      zoom: [1, 1.5], focus: [195, 430], ring: { at: 1.2, x: 30, y: 360, w: 330, h: 178 }, tap: { at: 2.6, x: 174, y: 503 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-planning.png',
      title: 'Les courses partent *seules au bon livreur*', sub: 'Le planning confie chaque ramassage et chaque livraison.',
      zoom: [1, 1.45], focus: [195, 640], ring: { at: 1.3, x: 14, y: 538, w: 362, h: 302 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Un problème ? *Vous êtes prévenu*', sub: 'Ramassage en retard, livreur injoignable, colis en échec.',
      zoom: [1, 1.5], focus: [195, 380], ring: { at: 1.3, x: 18, y: 268, w: 354, h: 188 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-caisse-versement.png',
      title: 'Le point de caisse *en deux minutes*', sub: 'Chaque encaissement coché, le montant attendu calculé.',
      zoom: [1, 1.45], focus: [195, 470], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 248 },
      pill: { at: 2.3, text: '542 500 F attendus', x: 195, y: 640 },
    },
    {
      type: 'phone', dur: 4, app: 'suivi', src: 'assets/j-suivi.png',
      title: 'Chaque colis *suivi en direct*', sub: 'Vos clients et leurs destinataires n’appellent plus.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 210, w: 362, h: 380 },
    },
    { type: 'end', dur: 4, brands: ['jibiat'], tagline: 'Plus de clients. *Plus de colis.*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
