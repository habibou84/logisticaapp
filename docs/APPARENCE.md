# Apparence des espaces

**Paramètres › Apparence des espaces** (administrateur de l'entreprise) :

- **Couleur de chaque espace** : e-commerçant, livreur, équipe (administration). On choisit parmi les couleurs
  proposées ou une couleur libre. Une alerte s'affiche si la couleur est trop claire pour du texte blanc.
- **Nom sous l'icône du téléphone** pour les deux applications installables. Sans réglage : le nom de
  l'entreprise pour l'application e-commerçant, « Livreur » pour l'application livreur.
- **Icône des applications** :
  - par défaut, le logo de l'entreprise sur la couleur de l'espace ;
  - on peut aussi envoyer une image carrée (au moins 192 × 192) ;
  - sans logo ni image, les icônes standard sont utilisées.
- **Même apparence pour tous les espaces** : la couleur et l'icône de l'espace e-commerçant s'appliquent partout.
  Les noms des applications restent propres à chacune.
- Un **aperçu** montre la barre du haut, un bouton et l'icône du téléphone de chaque espace.

## Où c'est appliqué

- Pages de connexion des trois espaces, et page d'accueil des e-commerçants.
- Applications e-commerçant et livreur : barre du haut (avec le logo), boutons, onglets, couleur de la barre du
  téléphone.
- Espace équipe : barre latérale et boutons principaux.
- Applications installables : nom, couleur et icônes du manifeste, icône Apple, icône de l'onglet.
- Notifications push : icône de l'application.

## Bon à savoir

- Sur un téléphone où l'application est déjà installée, Android met à jour le nom et l'icône de lui-même, en
  général sous 24 à 48 heures. Sur iPhone, il faut supprimer l'application puis la réinstaller.
- Les applications Android téléchargées (APK) gardent leur icône d'origine. Leurs écrans prennent bien la couleur et
  le logo.
- Les icônes sont générées par le serveur (extension PHP GD) et gardées dans `storage/app/branding`.
