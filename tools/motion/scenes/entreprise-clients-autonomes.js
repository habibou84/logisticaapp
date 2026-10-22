// Entreprises de livraison : les e-commerçants clients saisissent eux-mêmes leurs courses
window.SCENE = {
  brand: 'jibiat',
  shots: [
    { type: 'hook', dur: 2.8, text: 'Et si vos clients *saisissaient eux-mêmes* ?' },
    {
      type: 'phone', dur: 4.6, app: 'client', src: 'assets/j-ecommercant-message.png',
      title: 'Ils collent un message, *la course est prête*', sub: 'Depuis leur téléphone, dans leur espace client.',
      zoom: [1, 1.5], focus: [195, 210], ring: { at: 1.3, x: 28, y: 113, w: 334, h: 134 }, tap: { at: 3.2, x: 195, y: 275 },
    },
    {
      type: 'phone', dur: 4, app: 'client', src: 'assets/j-ecommercant-courses.png',
      title: 'Ils suivent *leurs colis tout seuls*', sub: 'Et décident eux-mêmes en cas d’échec.',
      zoom: [1, 1.35], focus: [195, 420],
    },
    {
      type: 'phone', dur: 4, app: 'jibiat', src: 'assets/j-import.png',
      title: 'Un fichier Excel ? *Cent courses d’un coup*',
      zoom: [1, 1.45], focus: [195, 250], ring: { at: 1.3, x: 14, y: 130, w: 362, h: 200 },
    },
    {
      type: 'phone', dur: 4.2, app: 'jibiat', src: 'assets/j-integrations.png',
      title: 'Leur boutique en ligne *envoie les courses*', sub: 'Jibiat Marchand ou leur propre site, par l’API.',
      zoom: [1, 1.45], focus: [195, 480], ring: { at: 1.3, x: 14, y: 352, w: 362, h: 230 },
    },
    { type: 'end', dur: 3.8, brands: ['jibiat'], tagline: 'Zéro ressaisie *pour votre équipe*', cta: 'Essai gratuit · jibiat.com' },
  ],
}
