// Entreprises de livraison : l'application des livreurs, même sans réseau
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Une application *pour vos livreurs*' },
    {
      type: 'phone', dur: 4.4, app: 'livreur', src: 'assets/j-livreur.png',
      title: 'Ses missions, *dans l’ordre*', sub: 'Ramasser, livrer, retourner : un toucher à chaque étape.',
      zoom: [1, 1.4], focus: [195, 260], ring: { at: 1.3, x: 14, y: 240, w: 362, h: 160 },
      pill: { at: 2.4, text: 'Marche même sans réseau', x: 195, y: 470 },
    },
    {
      type: 'phone', dur: 4, app: 'livreur', src: 'assets/j-livreur-caisse.png',
      title: 'Ce qu’il doit verser, *ce qu’il a gagné*',
      zoom: [1, 1.45], focus: [195, 420], ring: { at: 1.3, x: 14, y: 520, w: 362, h: 90 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-disponibilites.png',
      title: 'Et vous voyez *qui est libre*', sub: 'Où il se trouve, combien de missions il lui reste.',
      zoom: [1, 1.45], focus: [195, 560], ring: { at: 1.3, x: 14, y: 500, w: 362, h: 250 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Des livreurs *bien équipés*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
