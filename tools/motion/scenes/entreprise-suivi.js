// Entreprises de livraison : chaque colis suivi en direct, jusqu'au destinataire
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Où est le colis ? *Tout le monde le sait.*' },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-course-route.png',
      title: 'Qui ramasse, qui livre, *où il en est*',
      zoom: [1, 1.45], focus: [195, 290], ring: { at: 1.3, x: 14, y: 148, w: 362, h: 244 },
      pill: { at: 2.3, text: 'En chemin', x: 300, y: 120 },
    },
    {
      type: 'phone', dur: 4.4, app: 'jibiat', src: 'assets/j-course-route-2.png',
      title: 'Chaque étape *notée toute seule*', sub: 'Heure, livreur, photo et code de livraison.',
      zoom: [1, 1.4], focus: [195, 360], ring: { at: 1.3, x: 14, y: 60, w: 362, h: 600 },
    },
    {
      type: 'phone', dur: 4.2, app: 'suivi', src: 'assets/j-suivi.png',
      title: 'Le destinataire suit *son colis*', sub: 'Son lien de suivi arrive sur WhatsApp, sans application.',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.3, x: 14, y: 210, w: 362, h: 380 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Fini les appels *« il est où ? »*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
