// Entreprises de livraison : l'annuaire Jibiat leur amène des e-commerçants déjà en ligne
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Vos prochains clients sont *déjà sur Jibiat Marchand*' },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-reglages-annuaire.png',
      title: 'Cochez *« Figurer dans l’annuaire »*', sub: 'Compris dans toutes les formules.',
      zoom: [1, 1.5], focus: [195, 600], ring: { at: 1.3, x: 28, y: 598, w: 334, h: 90 }, tap: { at: 2.8, x: 42, y: 612 },
    },
    {
      type: 'phone', dur: 4.6, app: 'annuaire', src: 'assets/j-annuaire.png',
      title: 'Les e-commerçants *vous trouvent*', sub: 'Avec vos vrais résultats. Ceux de Jibiat Marchand vendent déjà en ligne.',
      zoom: [1, 1.45], focus: [195, 200], ring: { at: 1.4, x: 24, y: 40, w: 342, h: 330 },
      pills: [{ at: 2.4, text: '94 % réussis', x: 110, y: 420 }, { at: 2.9, text: '2 h en moyenne', x: 280, y: 470 }],
    },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-demandes.png',
      title: 'Ils vous contactent *directement*', sub: 'Un toucher pour accepter.',
      zoom: [1, 1.5], focus: [195, 430], ring: { at: 1.3, x: 30, y: 360, w: 330, h: 178 }, tap: { at: 3, x: 174, y: 503 },
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/j-integrations.png',
      title: 'Leurs colis *arrivent tout seuls*', sub: 'Leurs commandes deviennent vos courses, sans ressaisie.',
      zoom: [1, 1.45], focus: [195, 480], ring: { at: 1.3, x: 14, y: 352, w: 362, h: 230 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Des clients qui vous trouvent, *sans démarcher*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
