// E-commerçants : l'assistant de Jibiat Marchand en un coup d'œil (vidéo principale)
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Un assistant *dans votre espace marchand*' },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-commande-remplie.png',
      title: 'Un message WhatsApp *devient une commande*',
      zoom: [1, 1.45], focus: [195, 400], ring: { at: 1.2, x: 14, y: 240, w: 362, h: 62 },
    },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-produit-fiche.png',
      title: 'Une photo *devient une fiche produit*',
      zoom: [1, 1.45], focus: [195, 640], ring: { at: 1.2, x: 30, y: 590, w: 330, h: 230 },
    },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-reponse.png',
      title: 'Il prépare *vos réponses aux clients*',
      zoom: [1, 1.5], focus: [195, 600], ring: { at: 1.2, x: 24, y: 488, w: 248, h: 222 },
    },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-reseaux.png',
      title: 'Il écrit *vos publications*',
      zoom: [1, 1.45], focus: [195, 330], ring: { at: 1.2, x: 30, y: 236, w: 330, h: 126 },
    },
    {
      type: 'phone', dur: 3.6, app: 'marchand', src: 'assets/ia-chiffres.png',
      title: 'Il répond sur *vos chiffres*',
      zoom: [1, 1.45], focus: [195, 320], ring: { at: 1.2, x: 30, y: 276, w: 330, h: 150 },
    },
    { type: 'end', dur: 4, brands: ['marchand'], tagline: 'Vous vendez, *il fait le reste*', cta: '1 mois de Pro offert' },
  ],
}
