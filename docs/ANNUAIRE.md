# Annuaire des destinations

Menu **Distribution et MAD › Annuaire** ; réglages dans **Réglages › Annuaire**. L'annuaire est partagé par tous
les clients : entreprises et administrations où l'on livre, proposées pendant la saisie d'une course.

## Import de l'ancienne base
- **Importer** : export SQL de l'ancienne base (table `base_clients`) ou CSV (nom, ville, commune, adresse,
  contact, secteur, whatsapp, email). **Essayer** montre le rapport sans rien enregistrer, puis **Importer**.
- Remise en ordre : sigle séparé du nom (« ITWA · Imperial Tobacco West Africa »), ville (« ABJDJAN » → Abidjan),
  commune rattachée aux zones (« YOP », « 2PLATEAUX », « Z4 », fautes de frappe), quartier gardé.
- Numéros : seuls ceux au format ivoirien à 10 chiffres sont repris. Les autres (anciens numéros à 8 chiffres,
  0 oublié, numéros illisibles) restent **tels quels, « à vérifier »**. Une correction est proposée sur la fiche
  (règle de 2021 : 07 Orange, 05 MTN, 01 Moov, 27 fixe) mais jamais appliquée seule.
- Doublons (même nom, même commune) regroupés ; un second import ne crée pas de doublon.

## Suggestions pendant la saisie
- Back-office et application client (réglage) : « 📇 Chercher dans l'annuaire » par nom, sigle, quartier, adresse
  ou numéro. Sigle exact et nom d'abord, puis les destinations déjà livrées pour ce client, puis les plus livrées.
- La destination choisie remplit le nom, le numéro, la commune, l'adresse et la position.
- Numéro « à vérifier » : jamais repris ; le bon numéro est demandé. **Saisi une fois, il corrige l'annuaire**
  (le numéro d'origine reste dans l'historique de la fiche).

## Enrichissement par les courses
- Destination nouvelle saisie sur une course : ajoutée à l'annuaire, ajoutée « à valider » par l'équipe, ou pas
  (réglage). Même numéro, ou même nom dans la même commune : reliée à la destination existante, sans doublon.
- Nouveau numéro, adresse ou commune qui manquait : ajoutés à la fiche (réglage).

## Position GPS par le livreur
- Sur la mission : « Je suis sur place : enregistrer », et à la livraison la case « 📍 Enregistrer ma position
  comme celle de cette adresse » (proposée, cochée si l'adresse n'a pas encore de position, ou obligatoire).
- Précision demandée (± 60 m par défaut) ; près de la position connue, la plus précise l'emporte ; loin (150 m par
  défaut), la nouvelle position est gardée et l'équipe tranche (cloche, filtre « Écart de position »), sauf réglage.
- Courses suivantes : la position confirmée est donnée au livreur, et le bouton Itinéraire mène à la porte.
- Course e-commerce : la position va au carnet de destinataires du client.

## L'écran Annuaire
- Filtres : numéros à vérifier, sans position, position confirmée, écart de position, à valider, sans commune.
- Fiche : corriger, « Utiliser » une correction proposée, valider, historique des positions (garder celle-ci),
  regrouper un doublon, supprimer.
