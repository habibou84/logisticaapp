// E-commerçants : une photo du produit, l'assistant écrit la fiche
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Une photo. *Une fiche produit.*' },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/ia-produit-photo.png',
      title: 'Prenez le produit *en photo*', sub: 'Ajoutez une précision si vous voulez.',
      zoom: [1, 1.45], focus: [195, 300], ring: { at: 1.4, x: 14, y: 118, w: 362, h: 356 }, tap: { at: 3.2, x: 105, y: 405 },
    },
    {
      type: 'phone', dur: 4.6, app: 'marchand', src: 'assets/ia-produit-fiche.png',
      title: 'L’assistant *écrit la fiche*', sub: 'Nom, description, mots-clés pour Google.',
      zoom: [1, 1.5], focus: [195, 640], ring: { at: 1.5, x: 30, y: 590, w: 330, h: 230 },
      pill: { at: 2.5, text: 'Sac à main en pagne wax', x: 195, y: 540 },
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/ia-produit-fiche-2.png',
      title: 'Même les *couleurs et les tailles*', sub: 'Vous fixez le prix et le stock.',
      zoom: [1, 1.45], focus: [195, 430], ring: { at: 1.4, x: 30, y: 352, w: 330, h: 196 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Votre catalogue *en quelques minutes*', cta: '1 mois de Pro offert' },
  ],
}
