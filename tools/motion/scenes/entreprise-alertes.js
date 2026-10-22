// Entreprises de livraison : les alertes qui préviennent avant que ça coûte
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Les problèmes, *avant qu’ils coûtent*' },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Ramassages en retard, *avant l’heure limite*', sub: 'Il est encore temps d’appeler le livreur.',
      zoom: [1, 1.5], focus: [195, 380], ring: { at: 1.3, x: 18, y: 268, w: 354, h: 188 },
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/j-alertes.png',
      title: 'Livreur injoignable : *vous le savez tout de suite*',
      zoom: [1, 1.5], focus: [195, 520], ring: { at: 1.3, x: 18, y: 456, w: 354, h: 146 },
    },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-courses.png',
      title: 'Les échecs, *à décider d’un toucher*', sub: 'Relivrer, retourner au marchand ou remettre en stock.',
      zoom: [1, 1.5], focus: [195, 450], ring: { at: 1.3, x: 14, y: 322, w: 362, h: 262 }, tap: { at: 2.9, x: 59, y: 515 },
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/j-colis-livreurs.png',
      title: 'Les colis qui traînent *chez un livreur*', sub: 'Signalés en rouge au-delà de 24 heures.',
      zoom: [1, 1.4], focus: [195, 420], ring: { at: 1.3, x: 14, y: 168, w: 362, h: 180 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Rien ne vous *échappe*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
