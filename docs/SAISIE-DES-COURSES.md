# Saisie des courses

## Formulaire de l'équipe (Courses › Nouvelle course)

L'essentiel est toujours visible :
- e-commerçant ;
- téléphone et nom du client ;
- commune ou quartier (recherche à la frappe) ;
- adresse et repère ;
- date (« Au plus vite », « Demain » ou une autre date) et créneau ;
- montant à encaisser ;
- qui paie la livraison (toujours à choisir) ;
- instruction pour le livreur.

Le reste est dans **Plus d'options** :
- second numéro ;
- express et fragile ;
- contenu du colis ;
- référence ;
- articles du stock ;
- lieu de ramassage s'il diffère de l'adresse habituelle.

- **Enregistrer et nouvelle** : enregistre la course, puis vide le formulaire en gardant l'e-commerçant, pour
  enchaîner les saisies.
- **Ctrl + Entrée** : enregistre la course.
- **Alerte de doublon** : si une course existe déjà pour ce numéro chez cet e-commerçant dans les dernières 24 heures,
  un message l'indique avec un lien vers elle.

## Coller un message

Le bouton **📋 Coller un message** existe dans le formulaire de l'équipe et dans l'application e-commerçant. On y
colle le message du client ou de l'e-commerçant (WhatsApp, SMS), puis on clique sur **Analyser et remplir** :

- téléphones, nom, commune, adresse et repère, montant, payeur de la livraison, contenu et instruction sont remplis ;
- les champs remplis sont marqués **« à vérifier »** jusqu'à ce qu'on les modifie ;
- si plusieurs communes sont possibles, elles sont proposées en un clic.

C'est le même moteur que le robot WhatsApp : des règles, complétées par Claude si `ANTHROPIC_API_KEY` est renseignée
dans le `.env`. Sans cette clé, seules les règles sont utilisées, et l'analyse est moins précise sur les noms et les
adresses.
