# Formules des clients : distribution et mise à disposition (MAD)

Chaque client a une ou plusieurs **activités**, tenues séparément (courses, factures, chiffre d'affaires, équipes) :

| Activité | Facturation |
|---|---|
| **E-commerce** | Courses une à une : les frais de chaque course sont déduits des reversements du client |
| **Distribution** | Formule (contrat ou à l'unité) : facture du mois, série `DIS-AAAAMM-0001` |
| **MAD** (mise à disposition de livreurs) | Formule MAD : facture du mois, série `MAD-AAAAMM-0001` |

Les activités se cochent sur la fiche du client (**Clients** › « Modifier ») ; une activité qui a déjà des
courses ou une formule ne peut plus être retirée. Les livreurs sont communs à toutes les activités.

Avec une formule, les courses de l'activité ne coûtent rien sur le moment et passent sur la **facture du mois**
de cette activité, payée à part (virement, chèque, Mobile Money, espèces). Un client à la fois en distribution et
en MAD reçoit deux factures.

## 1. Préparer les tarifs (une fois)

**Réglages › Zones** : les communes couvertes par les forfaits portent l'étiquette « Forfait ». Par défaut, ce
sont les dix communes d'Abidjan (Abobo, Adjamé, Attécoubé, Cocody, Koumassi, Marcory, Plateau, Port-Bouët,
Treichville, Yopougon) ; « Mettre au forfait » ou « Retirer du forfait » sur chaque commune pour changer la liste.
Les quartiers suivent leur commune.

**Clients › Formules et tarifs › Distribution à l'unité (Abidjan)** : pour les clients sans contrat, prix d'un pli (0-500 g), d'un
colis (0-5 kg) et d'un chèque, pour chaque délai (24 h, 48 h, 72 h).

**Clients › Formules et tarifs › Villes de l'intérieur** : pour chaque ville et chaque délai, prix d'un pli, d'un colis et de chaque
kilogramme au-delà de 5 kg. Saisie ligne par ligne, ou import d'un fichier (« Modèle CSV » pour le format) :

| Ville | Délai | Pli | Colis | Kg supplémentaire |
|---|---|---|---|---|
| Bouaké | 24h | 3000 | 4000 | 500 |
| Bouaké | 48h | 2500 | 3500 | 500 |

L'import montre d'abord un aperçu (lignes nouvelles, mises à jour, erreurs), puis enregistre. Relancer le même
fichier met à jour les prix sans créer de doublon. Chaque nouvelle ville reçoit sa zone de livraison, à choisir
sur la course comme une commune.

## 2. Donner une formule à un client

**Clients** › « Formule » sur la ligne du client (droit « Formules et tarifs », dans son périmètre) :

- **Distribution sous contrat** : forfait mensuel, nombre de plis et colis inclus, prix de chacun au-delà, délai du
  contrat, prix d'un chèque livré, prix du kilogramme au-delà de 5 kg ;
- **Distribution à l'unité** : délai par défaut (le client peut en choisir un autre à chaque envoi) et prix du
  kilogramme au-delà de 5 kg ; les prix sont ceux de « Distribution à l'unité » ;
- **Mise à disposition (MAD)** : forfait mensuel, nombre de courses incluses, prix de chacune au-delà, délai,
  livreurs mis à disposition.

Une formule par activité : en créer une nouvelle arrête la précédente **de la même activité** la veille de son
début (un contrat de distribution et une MAD peuvent coexister). Ajouter une formule ajoute son activité au client. Une formule qui
a déjà des courses ne change plus de prix (pour changer les conditions : « Terminer… », puis une nouvelle formule).

## 3. Les courses

Un client à plusieurs activités choisit l'**activité** de chaque course (back-office, application du client,
champ `activity` de l'API ; e-commerce par défaut). Sur une course de distribution ou de MAD : **type d'envoi** (pli, colis
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
brouillon**, une par activité (ou à la demande : **Finances › Factures clients** › « Calculer », toutes les activités
ou celle du filtre). Elle reprend les courses de son activité :

1. le forfait du mois ;
2. les plis et colis (ou courses, en MAD) des communes du forfait, et ceux au-delà de la quantité incluse ;
3. les kilogrammes au-delà de 5 kg ;
4. les chèques, les envois vers l'intérieur, la distribution à l'unité et les courses hors forfait.

Courses comptées : celles créées dans le mois, sauf les courses annulées ou refusées. Le forfait est compté en
entier, même pour un contrat commencé en cours de mois (ajoutez une remise si besoin).

Ensuite :

- **Recalculer** : reprend les courses du mois (les lignes ajoutées à la main restent) ;
- **Ajouter une ligne** : remise (prix négatif) ou frais ;
- **Émettre** : numéro de la série de l'activité (`DIS-AAAAMM-0001`, `MAD-AAAAMM-0001` ; préfixes
  `CLIENT_INVOICE_PREFIX_DISTRIBUTION`, `CLIENT_INVOICE_PREFIX_MAD`), date et échéance (15 jours, `CLIENT_INVOICE_DUE_DAYS`) ; la facture ne
  change plus et le client la voit dans son application (Profil › Mes factures) ;
- **Enregistrer un paiement** : partiel ou total ; payée quand il ne reste rien ;
- **Annuler** : seulement sans paiement (elle garde son numéro, marquée annulée).

« Imprimer / PDF » donne la facture avec le détail des courses du mois.

## 5. Chiffre d'affaires par activité

**Finances › Chiffre d'affaires** (droit « Voir le chiffre d'affaires ») : mois par mois, chaque activité à part,
puis le total, en montants **facturés** ou **encaissés**, avec le nombre de courses :

- e-commerce : frais de livraison, de retour et de stockage retenus aux clients (encaissés quand le reversement est payé) ;
- distribution, MAD : factures émises du mois, paiements reçus dans le mois.

API : `GET /api/v1/revenue?from=2026-01&to=2026-12`.

## 6. Équipes et permissions

**Équipe › Utilisateurs** › « Modifier » :

- **Périmètre** (activités) : un compte limité à la distribution ne voit que les clients, courses, factures, formules,
  statistiques et notifications de la distribution (MAD, e-commerce de même). Aucune case : toutes les activités.
  L'administrateur voit toujours tout ; la caisse et les reversements ne concernent que l'e-commerce.
- **Rôle « Responsable d'activité »** : clients, courses et dispatch, factures, formules et chiffre d'affaires de son périmètre.
- **Réglage fin** : chaque permission peut être **ajoutée** au rôle ou **retirée** pour ce seul compte (une permission
  retirée l'emporte toujours). Nouvelles permissions : `invoices.view`, `invoices.manage` (factures clients),
  `rates.manage` (formules et tarifs), `revenue.view` (chiffre d'affaires).

## 7. L'application du client (distribution, MAD)

L'application s'adapte aux activités du client :

- **Plusieurs activités** : un sélecteur en haut de l'accueil, des courses et des statistiques (Tout, E-commerce,
  Distribution, MAD) ; la nouvelle course demande « Pour quelle activité ? ».
- **Nouvelle course en distribution** : type d'envoi (pli, colis, chèque), poids facultatif, délai (imposé par le
  contrat, ou au choix en distribution à l'unité). **Rien à encaisser** en distribution et en MAD : ni montant, ni
  « qui paie la livraison » (refusé aussi par l'API et l'import). Le bas de l'écran indique la facturation de la course
  (« compris dans le forfait », prix à l'unité) et le forfait du mois (« 9 / 10 utilisés »).
- **Accueil** : facture à payer (rouge si en retard), une carte par formule (envois utilisés sur le forfait, envois en plus,
  facture estimée à ce jour) et, en MAD, **ses livreurs attitrés** (nom, téléphone, état : disponible, en course,
  indisponible, absent ; jamais leur position).
- **Onglet Factures** à la place d'« Argent » pour un client sans e-commerce ; chaque facture montre comment la payer
  (**Réglages › Entreprise › Moyens de paiement des factures clients**, imprimés aussi sur la facture).
- **Statistiques** : envois par type, destination (forfait, à l'unité, intérieur) et délai.
- **Import Excel** : colonnes facultatives « Activité », « Type d'envoi », « Poids », « Délai ».

API de l'application : `GET /api/v1/merchant/space` (activités, formules du mois, factures à payer, livreurs MAD) ;
`activity` accepté par `/orders`, `/reports/summary` et `/merchant/stats`.

### Notifications

Cloche de l'application toujours ; notification sur le téléphone et WhatsApp (numéro de l'entreprise connecté dans
**WhatsApp**) selon les réglages du client (**Profil › Notifications**), comme pour les autres messages :

| Événement | Quand |
|---|---|
| Facture du mois émise | À l'émission (numéro, montant, échéance) |
| Rappel de facture | Trois jours avant l'échéance, puis le lendemain de l'échéance et chaque semaine tant qu'elle n'est pas payée (`billing:remind`, 09:10) |
| Forfait bientôt épuisé | À 80 % puis à 100 % du forfait du mois (une fois par seuil et par mois) |

Modèles WhatsApp à faire approuver par Meta : `facture_emise`, `facture_rappel`, `facture_en_retard`, `forfait_consomme`
(texte dans **WhatsApp › Modèles de messages**).
