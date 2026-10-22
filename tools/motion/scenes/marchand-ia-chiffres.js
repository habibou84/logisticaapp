// E-commerçants : poser une question sur ses chiffres, et les conseils du jour
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Qu’est-ce qui *se vend le mieux* ?' },
    {
      type: 'phone', dur: 5, app: 'marchand', src: 'assets/ia-chiffres.png',
      title: 'Posez la question, *comme à un ami*', sub: 'L’assistant lit vos ventes et votre stock.',
      zoom: [1, 1.5], focus: [195, 320], ring: { at: 1.5, x: 30, y: 276, w: 330, h: 150 },
      pill: { at: 2.6, text: '9 robes · 135 000 F', x: 195, y: 480 },
    },
    {
      type: 'phone', dur: 4.6, app: 'marchand', src: 'assets/vendre-conseils.png',
      title: 'Chaque matin, *vos conseils du jour*', sub: 'Quoi racheter, quoi relancer, quoi livrer.',
      zoom: [1, 1.5], focus: [195, 620], ring: { at: 1.4, x: 14, y: 574, w: 362, h: 176 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Vos chiffres *vous parlent enfin*', cta: '1 mois de Pro offert' },
  ],
}
