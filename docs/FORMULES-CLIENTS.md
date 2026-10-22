# Formules des clients : distribution et mise à disposition (MAD)

Par défaut, un e-commerçant paie ses courses une à une : les frais de chaque course sont déduits de ses
reversements. Un client peut aussi avoir une **formule** : ses courses ne coûtent plus rien sur le moment et
passent sur sa **facture du mois**, payée à part (virement, chèque, Mobile Money, espèces).

## 1. Préparer les tarifs (une fois)

**Réglages › Zones** : les communes couvertes par les forfaits portent l'étiquette « Forfait ». Par défaut, ce
sont les dix communes d'Abidjan (Abobo, Adjamé, Attécoubé, Cocody, Koumassi, Marcory, Plateau, Port-Bouët,
Treichville, Yopougon) ; « Mettre au forfait » ou « Retirer du forfait » sur chaque commune pour changer la liste.
Les quartiers suivent leur commune.

**Tarifs › Distribution à l'unité (Abidjan)** : pour les clients sans contrat, prix d'un pli (0-500 g), d'un
colis (0-5 kg) et d'un chèque, pour chaque délai (24 h, 48 h, 72 h).

**Tarifs › Villes de l'intérieur** : pour chaque ville et chaque délai, prix d'un pli, d'un colis et de chaque
kilogramme au-delà de 5 kg. Saisie ligne par ligne, ou import d'un fichier (« Modèle CSV » pour le format) :

| Ville | Délai | Pli | Colis | Kg supplémentaire |
|---|---|---|---|---|
| Bouaké | 24h | 3000 | 4000 | 500 |
| Bouaké | 48h | 2500 | 3500 | 500 |

L'import montre d'abord un aperçu (lignes nouvelles, mises à jour, erreurs), puis enregistre. Relancer le même
fichier met à jour les prix sans créer de doublon. Chaque nouvelle ville reçoit sa zone de livraison, à choisir
sur la course comme une commune.

## 2. Donner une formule à un client

**Clients › E-commerçants** › « Formule » sur la ligne du client :

- **Distribution sous contrat** : forfait mensuel, nombre de plis et colis inclus, prix de chacun au-delà, délai du
  contrat, prix d'un chèque livré, prix du kilogramme au-delà de 5 kg ;
- **Distribution à l'unité** : délai par défaut (le client peut en choisir un autre à chaque envoi) et prix du
  kilogramme au-delà de 5 kg ; les prix sont ceux de « Distribution à l'unité » ;
- **Mise à disposition (MAD)** : forfait mensuel, nombre de courses incluses, prix de chacune au-delà, délai,
  livreurs mis à disposition.

Une seule formule à la fois : en créer une nouvelle arrête la précédente la veille de son début. Une formule qui
a déjà des courses ne change plus de prix (pour changer les conditions : « Terminer… », puis une nouvelle formule).

## 3. Les courses

Sur la course d'un client sous formule (back-office, application du client, API) : **type d'envoi** (pli, colis
ou chèque), **poids** (facultatif) et, en distribution à l'unité, **délai**. Un pli de plus de 500 g compte comme
un colis. Rien n'est retenu sur la course ; elle indique comment elle sera facturée :

| Destination | Facturation |
|---|---|
| Commune du forfait | Comptée dans le forfait du mois (+ kilogrammes au-delà de 5 kg) |
| Ville de l'intérieur | Tarif de la ville pour le délai : pli ou colis, + kilogrammes au-delà de 5 kg |
| Chèque | Prix du chèque, toujours hors forfait |
| Abidjan, distribution à l'unité | Prix du délai et du type d'envoi |
| Autre commune (Bingerville…) | Tarif ordinaire de la course |

**MAD** : les courses du client vont à ses livreurs attitrés (ramassage, puis livraison par le même livreur),
même sans assignation automatique. Livreur absent ou indisponible : la course reste à l'équipe.

## 4. La facture du mois

Le 1er de chaque mois à 01:30, la facture du mois écoulé de chaque client sous formule est **calculée en
brouillon** (ou à la demande : **Finances › Factures clients** › « Calculer »). Elle reprend :

1. le forfait du mois ;
2. les plis et colis (ou courses, en MAD) des communes du forfait, et ceux au-delà de la quantité incluse ;
3. les kilogrammes au-delà de 5 kg ;
4. les chèques, les envois vers l'intérieur, la distribution à l'unité et les courses hors forfait.

Courses comptées : celles créées dans le mois, sauf les courses annulées ou refusées. Le forfait est compté en
entier, même pour un contrat commencé en cours de mois (ajoutez une remise si besoin).

Ensuite :

- **Recalculer** : reprend les courses du mois (les lignes ajoutées à la main restent) ;
- **Ajouter une ligne** : remise (prix négatif) ou frais ;
- **Émettre** : numéro `FAC-AAAAMM-0001`, date et échéance (15 jours, `CLIENT_INVOICE_DUE_DAYS`) ; la facture ne
  change plus et le client la voit dans son application (Profil › Mes factures) ;
- **Enregistrer un paiement** : partiel ou total ; payée quand il ne reste rien ;
- **Annuler** : seulement sans paiement (elle garde son numéro, marquée annulée).

« Imprimer / PDF » donne la facture avec le détail des courses du mois.
