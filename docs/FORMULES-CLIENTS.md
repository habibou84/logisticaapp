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

**Distribution › Tarifs › Distribution à l'unité (Abidjan)** : pour les clients sans contrat, prix d'un pli (0-500 g), d'un
colis (0-5 kg) et d'un chèque, pour chaque délai (24 h, 48 h, 72 h).

**Distribution › Tarifs › Villes de l'intérieur** : pour chaque ville et chaque délai, prix d'un pli, d'un colis et de chaque
kilogramme au-delà de 5 kg. Saisie ligne par ligne, ou import d'un fichier (« Modèle CSV » pour le format) :

| Ville | Délai | Pli | Colis | Kg supplémentaire |
|---|---|---|---|---|
| Bouaké | 24h | 3000 | 4000 | 500 |
| Bouaké | 48h | 2500 | 3500 | 500 |

L'import montre d'abord un aperçu (lignes nouvelles, mises à jour, erreurs), puis enregistre. Relancer le même
fichier met à jour les prix sans créer de doublon. Chaque nouvelle ville reçoit sa zone de livraison, à choisir
sur la course comme une commune.

## 2. Créer le contrat d'un client

**Distribution et MAD › Contrats › « + Nouveau contrat »** : choisir le client, puis la formule (droit « Formules
et tarifs », dans son périmètre). Le même contrat s'ouvre aussi depuis **Clients › « Contrat »**.

- **Distribution sous contrat** : forfait mensuel, **courses incluses par mois**, prix d'une course en plus à
  Abidjan, délai du contrat, prix d'un chèque livré, prix du kilogramme au-delà de 5 kg, et les **zones couvertes
  par le forfait** ;
- **Distribution à l'unité** (sans contrat) : délai par défaut ; prix de « Distribution › Tarifs » ;
- **Mise à disposition (MAD)** : forfait mensuel, courses incluses, prix de chacune au-delà, délai, livreurs attitrés.

Un contrat par activité : en créer un nouveau arrête le précédent **de la même activité** la veille de son
début (distribution et MAD peuvent coexister). Un contrat qui a déjà des courses ne change plus de prix (pour
changer les conditions : « Terminer… », puis un nouveau contrat).

**Zones couvertes par le forfait** : chaque contrat choisit les siennes, communes d'Abidjan et villes de l'intérieur
(leurs quartiers suivent). Un nouveau contrat part des communes marquées « Forfait » (Réglages › Zones) ; raccourcis
« + tout Abidjan », « + tout l'intérieur ». Elles se changent à tout moment (« Changer les zones ») : les courses
déjà créées gardent leur facturation.

- course vers une zone couverte : comptée dans les courses incluses ; au-delà, **au prix de sa destination**
  (prix du contrat à Abidjan, tarif de la ville à l'intérieur) ;
- course vers une zone non couverte : facturée à part dès la première (tarif de la ville à l'intérieur, tarif
  ordinaire ailleurs).

La page **Contrats** montre, pour chaque contrat en cours : les courses du mois sur les courses incluses
(« 34 / 50 »), les courses en plus et la **facture estimée à ce jour**. Filtres : en cours, à venir, terminés.

### Exemple : 50 000 F par mois pour 50 courses

Contrat : forfait 50 000 F, 50 courses incluses, 800 F la course en plus à Abidjan, zones couvertes : les communes
d'Abidjan et Bouaké ; grille de l'intérieur : Bouaké 4 000 F le colis en 48 h.

Dans le mois, le client envoie 48 courses à Abidjan et 5 à Bouaké (53 courses) : les **50 premières** (dans
l'ordre de création) sont dans le forfait, les 3 suivantes sont facturées **au prix de leur destination** :

| Ligne de la facture | Quantité | Prix | Montant |
|---|---|---|---|
| Forfait Distribution sous contrat en 48 h | 1 | 50 000 F | 50 000 F |
| Courses à Abidjan et à l'intérieur : 53 (50 inclus) | 53 | | inclus |
| Courses au-delà du forfait · Abidjan | 1 | 800 F | 800 F |
| Courses au-delà du forfait · Colis pour Bouaké en 48 h | 2 | 4 000 F | 8 000 F |
| **Total** | | | **58 800 F** |

Si Bouaké n'est pas dans les zones du contrat, les 5 courses vers Bouaké sont facturées 4 000 F chacune, dès la
première, et ne comptent pas dans les 50.

### Prix négociés d'un client

**Distribution et MAD › Tarifs** : la **grille commune** vaut pour tous les clients. Pour un client aux prix
négociés : « + Prix négociés pour un client » (ou « Prix négociés » sur la ligne de son contrat), puis saisir ou
importer **ses** prix (villes de l'intérieur, distribution à l'unité). Ils remplacent la grille commune pour ce
client, ville par ville et délai par délai ; ce qui n'est pas saisi suit la grille commune (rappelée en gris).
Le sélecteur « Grille » liste les clients qui ont des prix négociés.

## 3. Les courses

Un client à plusieurs activités choisit l'**activité** de chaque course (back-office, application du client,
champ `activity` de l'API ; e-commerce par défaut). Sur une course de distribution ou de MAD : **type d'envoi** (pli, colis
ou chèque), **poids** (facultatif) et, en distribution à l'unité, **délai**. Un pli de plus de 500 g compte comme
un colis. Rien n'est encaissé sur la course ; elle indique comment elle sera facturée :

| Destination | Facturation |
|---|---|
| Commune d'Abidjan couverte par le contrat | Comptée dans le forfait du mois ; au-delà des courses incluses, prix « en plus » du contrat |
| Ville de l'intérieur couverte par le contrat | Comptée dans le forfait ; au-delà, tarif de la ville (pli ou colis, délai du contrat) |
| Ville de l'intérieur non couverte | Tarif de la ville, dès la première course |
| Chèque | Prix du chèque, toujours hors forfait |
| Abidjan, distribution à l'unité | Prix du délai et du type d'envoi |
| Autre commune non couverte (Bingerville…) | Tarif ordinaire de la course |

Tarif de la ville et prix à l'unité : les prix négociés du client s'il en a, sinon la grille commune.

Au-delà de 5 kg, chaque kilogramme commencé est facturé en plus (prix du contrat à Abidjan, de la ville à l'intérieur).

**MAD** : les courses du client vont à ses livreurs attitrés (ramassage, puis livraison par le même livreur),
même sans assignation automatique. Livreur absent ou indisponible : la course reste à l'équipe.

## 3 bis. Suppléments

Demandes exceptionnelles d'un client sous contrat (livraison urgente un dimanche, mise sous pli, course hors du
commun…), ajoutées **à part** sur sa facture du mois :

- **Distribution et MAD › Contrats › « Suppléments »** sur la ligne du contrat, ou **« ➕ Supplément »** sur la fiche
  d'une course (le supplément est alors lié à la course) ;
- date, libellé, quantité et prix unitaire ; droit « Gérer les factures clients » ou « Formules et tarifs » ;
- la facture du mois de la date du supplément le reprend dans des lignes « Supplément · … » ; la facture estimée
  du contrat le compte déjà ;
- le client voit ses suppléments du mois dans son application (Factures, et sur la carte du forfait) ;
- une fois la facture du mois émise, ses suppléments ne changent plus et le mois est fermé (dater le supplément
  du mois suivant).

## 3 ter. Délai interne de distribution

Délai que l'entreprise se donne (le client ne le voit pas), compté **à partir de la création de la course** :

- **Réglages › Courses et livraison › Délai interne de distribution** : un délai en heures par activité
  (distribution, MAD, e-commerce ; vide = aucun) ;
- **contrat** : un délai propre (« Délai interne… » sur le contrat, ou à la création) qui l'emporte sur celui de l'activité ;
- à **80 %** du délai, l'équipe reçoit « ⚠️ Risque de retard » ; une fois le délai dépassé, « ⏰ En retard » (une seule
  fois chacune ; administrateurs, dispatch et responsables de l'activité) ; vérification toutes les 5 minutes
  (`orders:internal-delays`) ;
- liste des courses : file **« ⏰ Délai interne dépassé »** et badges ; fiche course : échéance interne.

## 4. La facture du mois

Le 1er de chaque mois à 01:30, la facture du mois écoulé de chaque client sous formule est **calculée en
brouillon**, une par activité (ou à la demande : **Distribution et MAD › Factures** › « Calculer », toutes les activités
ou celle du filtre). Elle reprend les courses de son activité :

1. le forfait du mois ;
2. les courses comptées dans le forfait, puis celles au-delà des courses incluses, au prix de leur destination ;
3. les kilogrammes au-delà de 5 kg ;
4. les chèques, les envois vers l'intérieur facturés à part, la distribution à l'unité et les courses hors forfait.

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
- **Onglet Factures** à la place d'« Argent » pour un client sans e-commerce ; chaque facture montre comment la payer,
  avec un bouton « Copier » pour l'IBAN et les numéros (imprimé aussi sur la facture).

### Moyens de paiement des factures

**Réglages › Entreprise › Paiement des factures clients** : l'entreprise ajoute librement ses moyens de paiement, autant
qu'elle veut, et coche ceux **proposés aux clients** (l'ordre de la liste est celui de la facture) :

| Moyen | Informations |
|---|---|
| Compte bancaire | Banque, titulaire, IBAN (mis en forme par groupes de quatre), BIC |
| Mobile Money | Opérateur (Orange Money, MTN MoMo, Moov Money, Wave ou autre), numéro, au nom de |
| Chèque | À l'ordre de |
| Espèces | Où et quand |
| Autre | Nom (ex. carte bancaire) et précisions |

Une note libre peut compléter (ex. « envoyez la preuve de paiement sur WhatsApp »).
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
