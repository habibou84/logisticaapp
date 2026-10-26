# Étiquettes

Une étiquette 10 × 15 cm par course, avec le QR code du suivi public (scanné au ramassage, au dépôt et à la remise) :
- **e-commerce** : montant à encaisser ;
- **distribution** : accusé de réception (nom, prénoms, fonction, date, signature, cachet) quand le réglage
  « Accusé de réception sur l'étiquette » est actif ;
- **MAD** : activité et envoi, sans montant.

Logo de l'entreprise en tête s'il est réglé (Réglages › Entreprise et marque).

## Formats
Page d'impression `/etiquettes?ids=…` (et `/etiquette/{id}`) : **rouleau 10 × 15 cm** (imprimante thermique),
**A6**, ou **A4 à quatre étiquettes** (à découper). Le format choisi reste mémorisé sur l'appareil. Jusqu'à
200 étiquettes d'un coup.

## Application client (e-commerce, distribution, MAD)
Comptes qui créent des courses (propriétaire, gestionnaire, agent) :
- fiche de la course : **🖨️ Imprimer l'étiquette** ;
- après la création d'une course : **🖨️ Étiquette** ;
- après un import : **Imprimer les N étiquettes** ;
- Mes courses › **🏷️ Étiquettes** : cocher les courses, **Tout cocher** ou **À ramasser**, puis **Imprimer**.

Les courses annulées ou refusées ne s'impriment pas depuis la liste.

## Équipe
- fiche de la course : **🖨️ Étiquette** ;
- liste des courses, sélection : **🏷️ Étiquettes** (à côté de la feuille de route) ;
- ramassages par client : **🏷️ Étiquettes** des colis choisis ;
- Contrats › lot de distribution : **🏷️ Étiquettes du lot** ;
- import de courses : **Imprimer les N étiquettes**.

## Réglages (Réglages › Courses et livraison › Étiquettes)
- **Les clients impriment leurs étiquettes** : oui (s'ils le souhaitent), **obligatoire avant le ramassage**, ou
  non (l'agence imprime ; les boutons disparaissent de l'application client).
- **Format proposé** : rouleau 10 × 15, A6 ou A4 à quatre ; chacun peut en choisir un autre, gardé sur l'appareil.
- **Imprimé sur l'étiquette** : montant à encaisser, téléphone du destinataire, repère, description du colis, logo.
- Chaque client peut avoir son propre choix (Clients › Modifier › Étiquettes : qui imprime, format).

## Étiquette imprimée
Chaque impression est notée sur la course (date, nombre de fois) :
- fiche de la course (équipe) : « 🖨️ Étiquette ✓ » quand elle a été imprimée ;
- application client : « Réimprimer l'étiquette », « ✓ imprimée » en mode Étiquettes, choix **Pas imprimées** ;
- impression obligatoire : rappel « N étiquettes à imprimer » dans « À faire » de l'accueil du client ;
- livreur, au ramassage : « Étiquette pas encore imprimée : vérifiez que le colis est bien identifié ».
- liste des courses (API) : filtre `label=unprinted`.

## API publique (Jibiat Marchand)
Chaque course a un champ **`label_url`** : l'étiquette à imprimer sans connexion (lien signé, valable 7 jours,
renouvelé à chaque lecture de la course). L'impression depuis ce lien est aussi notée. Champ ajouté au contrat
(version 1.1 de `public/docs/openapi.yaml`) : rien ne change pour les intégrations existantes.
