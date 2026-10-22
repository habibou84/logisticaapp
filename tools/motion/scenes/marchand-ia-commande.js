// E-commerçants : coller le message WhatsApp du client, l'assistant remplit la commande
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Un client vous écrit *sur WhatsApp* ?' },
    {
      type: 'phone', dur: 4.6, app: 'marchand', src: 'assets/ia-commande-message.png',
      title: 'Collez *son message*', sub: 'Même mal écrit, en plusieurs fois ou en capture d’écran.',
      zoom: [1, 1.5], focus: [195, 230], ring: { at: 1.4, x: 30, y: 72, w: 330, h: 174 }, tap: { at: 3.4, x: 142, y: 374 },
    },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/ia-commande-remplie.png',
      title: 'L’assistant *remplit la commande*', sub: 'Numéro, nom, quartier et article.',
      zoom: [1, 1.5], focus: [195, 420], ring: { at: 1.5, x: 14, y: 240, w: 362, h: 62 },
      pill: { at: 2.4, text: 'Prête en 10 secondes', x: 195, y: 190 },
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/ia-commande-remplie-2.png',
      title: 'Le quartier *et les frais de livraison*',
      zoom: [1, 1.55], focus: [195, 500], ring: { at: 1.5, x: 30, y: 486, w: 330, h: 84 },
      pill: { at: 2.3, text: '1 500 F · livré sous 24 h', x: 195, y: 620 },
    },
    {
      type: 'phone', dur: 4.2, app: 'marchand', src: 'assets/ia-commande-remplie-3.png',
      title: 'Vous vérifiez, *c’est enregistré*', sub: 'Rien ne part sans vous.',
      zoom: [1, 1.45], focus: [195, 620], ring: { at: 1.3, x: 30, y: 534, w: 330, h: 164 }, tap: { at: 2.9, x: 240, y: 746 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Collez. Vérifiez. *C’est commandé.*', cta: '1 mois de Pro offert' },
  ],
}
