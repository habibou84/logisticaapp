# Ramassages groupés par client

Un e-commerçant a souvent plusieurs colis à remettre à la même adresse. Les ramassages se gèrent
donc par **client et adresse de ramassage** ; les livraisons restent une mission par colis.

## 1. Dispatch : « 🏪 Ramassages par client »

Courses → onglet **🏪 Ramassages par client** (réservé aux personnes qui assignent).

- Les colis **validés**, sans ramasseur, à aller chercher chez le client (hors entrepôt) sont regroupés
  par client et adresse, classés par **zone de ramassage** puis par nombre de colis.
- Ouvrir un lot affiche ses colis : **décocher** un colis le retire du lot (il reste à assigner).
- Choisir le livreur puis **Assigner les N** : tous les colis cochés lui sont confiés d'un coup.

API : `GET /api/v1/orders/pickup-groups`, puis `POST /api/v1/orders/bulk-assign` avec `type: pickup`.

## 2. Colis tardif : ajouté au livreur déjà prévu

Quand un colis est validé (à la main ou par la validation automatique) alors qu'un livreur a déjà un
ramassage **assigné ou en route** chez ce client, à la même adresse, et pas encore fait :

- le colis lui est assigné automatiquement ;
- s'il avait déjà accepté ce ramassage, le nouveau colis est accepté lui aussi ;
- une note interne est ajoutée à la course (« Ajouté automatiquement au ramassage… »).

Une fois le ramassage fait (colis récupérés), un nouveau colis attend une nouvelle assignation.

## 3. Livreur : une mission par boutique

Dans l'onglet **Ramasser**, les colis d'une même boutique forment une seule carte
« Boutique X : N colis ». L'écran du lot permet de :

- **Accepter** tous les colis d'un geste (pour en refuser un, ouvrir son détail) ;
- **🛵 Je pars** : tous les colis passent « Ramassage en cours » ;
- **✓ J'ai tout récupéré** : les colis cochés passent « Récupéré ». Un colis **décoché** (non remis
  par la boutique) demande un motif et retourne à « Validée » pour un prochain ramassage.

Ces actions fonctionnent aussi en mode hors connexion (voir `MODE-HORS-CONNEXION.md`).
