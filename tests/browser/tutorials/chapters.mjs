// Guide vidéo « Bien démarrer » (administrateur) : un chapitre par étape essentielle de la mise en route.
// Chaque segment : ce que dit la voix off, puis ce qui se passe à l'écran pendant qu'elle le dit.
// Le même fichier sert à générer la voix (segments séparés par une pause) et à filmer l'application.

export const CHAPTERS = [
  {
    key: 'company',
    title: 'Votre entreprise',
    segments: [
      { say: 'Bienvenue ! Commençons par votre entreprise. Ouvrez Réglages, puis Entreprise et marque.', do: async (s) => { await s.go('/admin/reglages'); await s.hl('text=Entreprise et marque'); await s.go('/admin/reglages/entreprise') } },
      { say: 'Indiquez le nom, le téléphone et l\'adresse de votre entreprise. Ils apparaissent sur les factures, les messages et la page de suivi des colis.', do: async (s) => { await s.hl('label:has-text("Nom") + input, input >> nth=0'); await s.hl('text=Téléphone') } },
      { say: 'Ajoutez ensuite les moyens de paiement de vos factures : compte bancaire, Mobile Money, chèque ou espèces. Ils seront imprimés sur chaque facture client.', do: async (s) => { await s.scrollTo('h2:has-text("Paiement des factures clients")'); await s.hl('text=+ Mobile Money') } },
      { say: 'Envoyez votre logo, puis choisissez la couleur et l\'icône de chaque espace : client, livreur et équipe.', do: async (s) => { await s.scrollTo('h2:has-text("Logo")'); await s.scrollTo('h2:has-text("Apparence des espaces")'); await s.hl('h3:has-text("Espace client")') } },
      { say: 'Enregistrez. Votre entreprise est prête, passons aux zones desservies.', do: async (s) => { await s.hl('text=Enregistrer l\'apparence') } },
    ],
  },
  {
    key: 'zones',
    title: 'Les zones desservies',
    segments: [
      { say: 'Les zones, ce sont les communes et les quartiers où vous livrez. Ouvrez le menu Zones.', do: async (s) => { await s.go('/admin/zones') } },
      { say: 'Pour ajouter une zone, tapez son nom, choisissez sa commune si c\'est un quartier, puis cliquez sur Ajouter.', do: async (s) => { await s.type('main input[placeholder*="Angré"]', 'Riviera Faya', 60); await s.hl('text=Dans la commune'); await s.hl('button:has-text("Ajouter")') } },
      { say: 'Le bouton Mettre au forfait indique les communes comprises dans le forfait de vos clients de distribution.', do: async (s) => { await s.hl('text=Mettre au forfait >> nth=0') } },
      { say: 'Une zone d\'expédition sert aux villes de l\'intérieur : le livreur dépose le colis à la gare et note les frais.', do: async (s) => { await s.hl('text=Zone d\'expédition') } },
      { say: 'Vous pouvez modifier, désactiver ou supprimer une zone à tout moment. Place maintenant aux tarifs.', do: async (s) => { await s.hl('text=Désactiver >> nth=0') } },
    ],
  },
  {
    key: 'pricing',
    title: 'Les tarifs',
    segments: [
      { say: 'Le prix de chaque course se calcule à partir de votre grille de tarifs. Ouvrez Tarifs.', do: async (s) => { await s.go('/admin/tarifs') } },
      { say: 'Pour chaque trajet, d\'une commune de ramassage à une commune de livraison, indiquez le prix. Les trajets sans prix sont signalés.', do: async (s) => { await s.hl('table >> nth=0') } },
      { say: 'Ajoutez les suppléments : express, fragile, ou selon le poids, en francs ou en pourcentage du tarif.', do: async (s) => { await s.scrollTo('h2:has-text("Suppléments")'); await s.hl('text=+ Supplément') } },
      { say: 'Un client a négocié ses prix ? Créez-lui une grille négociée, elle remplace la grille commune pour lui seul.', do: async (s) => { await s.scrollTop(); await s.hl('text=+ Grille négociée') } },
      { say: 'Enfin, réglez les frais d\'un colis retourné, puis enregistrez les tarifs.', do: async (s) => { await s.scrollTo('h2:has-text("Retours")'); await s.hl('text=Enregistrer les tarifs') } },
    ],
  },
  {
    key: 'order_rules',
    title: 'Les règles des courses',
    segments: [
      { say: 'Réglons maintenant le fonctionnement des courses. Ouvrez Réglages, puis Courses et livraison.', do: async (s) => { await s.go('/admin/reglages/courses') } },
      { say: 'Choisissez la validation des nouvelles courses : manuelle par le dispatch, automatique, ou automatique avec des règles.', do: async (s) => { await s.hl('text=Validation des nouvelles courses') } },
      { say: 'Fixez l\'heure limite du jour et le nombre de tentatives de livraison. Après l\'heure limite, une course passe au lendemain.', do: async (s) => { await await s.hl('text=Heure limite du jour') } },
      { say: 'Pour les livreurs, vous pouvez exiger le code de livraison du destinataire, et autoriser le mode hors connexion.', do: async (s) => { await s.scrollTo('text=Exiger le code de livraison') } },
      { say: 'Le ramassage et la livraison automatiques confient les courses au bon livreur. Commencez par le mode à l\'essai pour voir ce qu\'ils feraient.', do: async (s) => { await s.scrollTo('text=Ramassage automatique'); await s.hl('text=À l\'essai >> nth=0') } },
      { say: 'Pour la distribution et la mise à disposition, choisissez la preuve de remise : nom, signature ou photo. Enregistrez quand tout est prêt.', do: async (s) => { await s.scrollTo('h2:has-text("Distribution et MAD")'); await s.hl('label:has-text("Signée sur le téléphone")') } },
    ],
  },
  {
    key: 'couriers',
    title: 'Les livreurs',
    segments: [
      { say: 'Ajoutons vos livreurs. Ouvrez le menu Livreurs.', do: async (s) => { await s.go('/admin/livreurs') } },
      { say: 'Chaque livreur a un compte avec son numéro de téléphone et un mot de passe. Il se connecte à l\'application livreur sur son téléphone.', do: async (s) => { await s.hl('main .card >> nth=0') } },
      { say: 'Indiquez ses zones : la livraison automatique ne choisit que des livreurs qui couvrent la zone.', do: async (s) => { await s.hl('text=Modifier >> nth=0') } },
      { say: 'Dans Véhicules, enregistrez la moto de chaque livreur, ses papiers et son entretien. L\'application vous prévient avant chaque échéance.', do: async (s) => { await s.go('/admin/vehicules') } },
      { say: 'Le livreur fait l\'état des lieux de son véhicule avant de partir. Vos livreurs sont prêts, réglons maintenant leur paie.', do: async (s) => { await s.hl('main h1') } },
    ],
  },
  {
    key: 'pay',
    title: 'La paie des livreurs',
    segments: [
      { say: 'La paie se calcule toute seule à partir d\'un plan de paie. Ouvrez Paie des livreurs.', do: async (s) => { await s.go('/admin/paie-livreurs') } },
      { say: 'Choisissez la période de paie et, si besoin, un salaire de base, ainsi qu\'un montant par jour de mise à disposition.', do: async (s) => { await s.scrollTo('text=Salaire et période de paie') } },
      { say: 'Ajoutez les règles : un montant par ramassage, par livraison réussie, par tentative ratée ou par retour. Il peut dépendre de la zone ou des frais.', do: async (s) => { await s.scrollTo('h2:has-text("Livraison réussie")'); await s.hl('text=+ Ajouter une règle >> nth=0') } },
      { say: 'Les primes d\'objectifs récompensent les meilleurs livreurs, par exemple au-delà de cent livraisons dans le mois.', do: async (s) => { await s.scrollTo('text=Primes d\'objectifs') } },
      { say: 'Avant d\'enregistrer, essayez le simulateur : il montre ce que gagne le livreur pour une course.', do: async (s) => { await s.scrollTo('text=Simulateur'); await s.hl('button:has-text("Calculer")') } },
    ],
  },
  {
    key: 'merchants',
    title: 'Les clients',
    segments: [
      { say: 'Passons à vos clients. Ouvrez le menu Clients.', do: async (s) => { await s.go('/admin/marchands') } },
      { say: 'Cliquez sur Nouveau : nom, contact, téléphone, et lieu de ramassage. Le client reçoit un compte pour suivre ses courses dans son application.', do: async (s) => { await s.click('text=+ Nouveau'); await s.wait(1200); await s.closeModal() } },
      { say: 'Un client e-commerce paie course par course, sur l\'argent encaissé. Un client de distribution ou de mise à disposition paie une facture chaque mois.', do: async (s) => { await s.hl('main table') } },
      { say: 'Pour lui, ouvrez Contrats : forfait du mois, envois compris, prix au-delà, ou lot de colis pour une distribution ponctuelle.', do: async (s) => { await s.go('/admin/contrats'); await s.hl('text=+ Nouveau contrat') } },
      { say: 'Une banque ou une administration a plusieurs services ? Créez ses départements : chaque course dit qui l\'envoie et qui la reçoit.', do: async (s) => { await s.go('/admin/marchands'); await s.hl('text=Départements >> nth=0') } },
    ],
  },
  {
    key: 'first_delivery',
    title: 'Une course d\'essai',
    segments: [
      { say: 'Tout est prêt : faisons une course d\'essai du début à la fin. Cliquez sur Nouvelle course.', do: async (s) => { await s.go('/admin/courses/nouvelle') } },
      { say: 'Choisissez le client, puis cherchez le destinataire dans l\'annuaire, ou tapez son numéro et sa commune.', do: async (s) => { await s.select('#merchant', 'Ivoire Assurances'); await s.wait(800); await s.type('label:has-text("Chercher dans l\'annuaire") + input, input[placeholder*="sigle"]', 'nouvelle afrique', 90); await s.wait(1500); await s.pickFirst() } },
      { say: 'La fiche se remplit toute seule. Le prix s\'affiche en bas avant d\'enregistrer.', do: async (s) => { await s.scrollTo('text=Quand livrer'); await s.click('text=Aujourd\'hui >> nth=0') } },
      { say: 'La course apparaît ensuite dans Courses. Confiez-la à un livreur, ou laissez l\'assignation automatique le faire.', do: async (s) => { await s.go('/admin/courses') } },
      { say: 'Le livreur la voit dans son application : il ramasse, puis livre, avec la signature et la position de l\'adresse. Vous suivez chaque étape en direct.', do: async (s) => { await s.go('/admin/carte') } },
      { say: 'Revenez enfin à la Mise en route pour effacer la course d\'essai. Votre application est prête. Bonnes livraisons !', do: async (s) => { await s.go('/admin/mise-en-route') } },
    ],
  },
]

export const narration = (chapter) => chapter.segments.map((s) => s.say).join(' <break time="1.6s" /> ')
