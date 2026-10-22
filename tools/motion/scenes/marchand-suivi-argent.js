// E-commerçants clients d'une entreprise Jibiat : le colis et l'argent suivis en direct
window.SCENE = {
  brand: 'marchand',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Où est mon colis ?\nOù est *mon argent* ?' },
    {
      type: 'phone', dur: 4.4, app: 'ecommercant', src: 'assets/jibiat-marchand-courses.png',
      title: 'Chaque colis, *en direct*', sub: 'Ramassé, en route, livré : sans appeler le livreur.',
      zoom: [1, 1.6], focus: [195, 520], ring: { at: 1.5, x: 16, y: 546, w: 358, h: 96 },
      pill: { at: 2.2, text: '✓ Livrée à 12:58', x: 195, y: 470 },
    },
    {
      type: 'phone', dur: 4.6, app: 'ecommercant', src: 'assets/jibiat-marchand-argent.png',
      title: 'Votre argent, *au franc près*', sub: 'Ce qu’on vous doit, ce qui est encore chez les livreurs, ce qui est payé.',
      zoom: [1, 1.6], focus: [195, 200], ring: { at: 1.5, x: 16, y: 72, w: 358, h: 244 },
      pill: { at: 2.2, text: '154 000 F à recevoir', x: 195, y: 360 },
    },
    {
      type: 'phone', dur: 4, app: 'ecommercant', src: 'assets/jibiat-marchand-argent.png',
      title: 'Payé par *Wave* ou Orange Money', sub: 'Chaque reversement a son relevé, course par course.',
      zoom: [1, 1.7], focus: [195, 450], ring: { at: 1.4, x: 16, y: 392, w: 358, h: 100 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Vos colis suivis, *votre argent aussi*', cta: 'Demandez l’application à votre livreur' },
  ],
}
