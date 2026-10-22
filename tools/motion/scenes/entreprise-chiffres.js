// Entreprises de livraison : les statistiques et le rapport de la semaine
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Votre entreprise, *en vrais chiffres*' },
    {
      type: 'phone', dur: 4.6, app: 'jibiat', src: 'assets/j-stats.png',
      title: 'Courses, réussite, *chiffre d’affaires*', sub: 'Comparés à la période précédente.',
      zoom: [1, 1.45], focus: [195, 450], ring: { at: 1.3, x: 14, y: 316, w: 362, h: 420 },
      pill: { at: 2.4, text: '93 % de colis livrés', x: 195, y: 280 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-stats-2.png',
      title: 'Par livreur, par client, *par zone*',
      zoom: [1, 1.35], focus: [195, 420],
      pill: { at: 2.2, text: 'Rapport chaque lundi sur WhatsApp', x: 195, y: 700 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Pilotez *avec les vrais chiffres*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
