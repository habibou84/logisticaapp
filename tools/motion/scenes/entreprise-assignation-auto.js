// Entreprises de livraison : l'assignation automatique par le planning des livreurs
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Qui ramasse quoi ? *Le planning décide.*' },
    {
      type: 'phone', dur: 4.6, app: 'jibiat', src: 'assets/j-planning.png',
      title: 'Une règle par livreur, *avec son remplaçant*', sub: 'Moussa ramasse à Cocody et au Plateau, Serge à Marcory.',
      zoom: [1, 1.5], focus: [195, 640], ring: { at: 1.4, x: 14, y: 538, w: 362, h: 302 },
      pill: { at: 2.4, text: 'Tous les jours', x: 195, y: 500 },
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/j-planning-2.png',
      title: 'Les livraisons du jour *aussi*',
      zoom: [1, 1.5], focus: [195, 260], ring: { at: 1.3, x: 14, y: 71, w: 362, h: 364 },
    },
    {
      type: 'phone', dur: 4.6, app: 'jibiat', src: 'assets/j-course-auto.png',
      title: 'La course part *sans que vous touchiez à rien*', sub: '« Ramassage confié automatiquement à Serge Kouassi. »',
      zoom: [1, 1.55], focus: [195, 330], ring: { at: 1.4, x: 46, y: 282, w: 316, h: 74 },
      pill: { at: 2.5, text: 'Confié par le planning', x: 195, y: 420 },
    },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Livreur injoignable ? *Un autre prend le relais*',
      zoom: [1, 1.5], focus: [195, 520], ring: { at: 1.3, x: 18, y: 456, w: 354, h: 146 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Fini de *tout répartir à la main*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
