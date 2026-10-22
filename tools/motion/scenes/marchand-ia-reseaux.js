// E-commerçants : textes de publication écrits par l'assistant, et ce que rapporte chaque réseau
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.6, text: 'Pas d’idée *pour votre publication* ?' },
    {
      type: 'phone', dur: 5, app: 'marchand', src: 'assets/ia-reseaux.png',
      title: 'Trois textes *prêts à publier*', sub: 'Au bon prix, avec les hashtags. En français simple ou en nouchi.',
      zoom: [1, 1.5], focus: [195, 330], ring: { at: 1.5, x: 30, y: 236, w: 330, h: 126 },
      pill: { at: 2.6, text: '#wax #abidjan', x: 260, y: 470 },
    },
    {
      type: 'phone', dur: 4.4, app: 'marchand', src: 'assets/ia-reseaux.png',
      title: 'Facebook, Instagram, TikTok, *en un clic*', sub: 'Maintenant ou programmé, avec un visuel au prix du jour.',
      zoom: [1, 1.35], focus: [195, 420],
    },
    {
      type: 'phone', dur: 4.6, app: 'marchand', src: 'assets/vendre-reseaux.png',
      title: 'Et vous voyez *ce que rapporte chaque réseau*',
      zoom: [1, 1.5], focus: [195, 300], ring: { at: 1.4, x: 30, y: 148, w: 330, h: 338 },
      pill: { at: 2.4, text: '64 500 F venus de Facebook', x: 195, y: 560 },
    },
    { type: 'end', dur: 3.8, brands: ['marchand'], tagline: 'Publiez mieux, *sachez ce qui vend*', cta: '1 mois de Pro offert' },
  ],
}
