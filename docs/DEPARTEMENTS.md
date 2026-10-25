# Départements des clients

Pour les clients de distribution et de MAD qui envoient ou reçoivent pour plusieurs départements (une banque :
Finance, Marketing, Gestion…). Réglages de l'entreprise dans **Réglages › Départements** ; chaque client peut les
changer sur **Clients › Départements › Réglages du client** (vide : comme l'entreprise). Le gérant du client gère
aussi ses départements dans son application (**Profil › Mes départements**), si l'administrateur le permet.

## Le département
- Nom, code court (FIN), responsable et téléphone, emplacement (bâtiment, étage, bureau).
- **Autre site** : sa propre adresse ; le ramassage se fait chez lui et le courrier interne lui est remis là.
- Centre de coûts, quota d'envois du mois, département **principal** (paie le forfait quand rien n'est à répartir,
  les lots à prix global et les suppléments).
- Un département qui a des courses se désactive au lieu de se supprimer (gardé sur les courses et les factures).

## Les comptes
- Chaque compte « Employé du client » est rattaché à un ou plusieurs départements (onglet **Comptes**).
- Option : un employé ne voit et ne commande que pour ses départements (courses, alertes, factures) ; le gérant voit
  tout ; un employé sans département voit tout.

## Sur la course
- Département qui envoie : obligatoire ou non ; choisi d'office si le compte n'en a qu'un.
- « Demandé par » : jamais, facultatif ou obligatoire. La référence de la course sert de référence interne.
- **Courrier interne** (option) : vers un autre département du client ; adresse, responsable et téléphone remplis
  tout seuls.
- Filtre par département dans « Mes courses » ; département affiché sur chaque course.

## Qui a fait quoi
- Au ramassage, le livreur voit le département et son emplacement, et note **qui lui remet** (nom, fonction) et,
  selon le réglage, sa **signature**. Ramassage groupé : une seule saisie pour tous les colis.
- À la remise : nom, signature et photo du cachet selon la preuve de remise (Réglages › Courses), plus la
  **fonction** de la personne qui reçoit (réglage).
- Frise « Qui a fait quoi » sur la course : demandée par, remise par, livrée par, reçue par.
- Alertes du client : un employé limité à ses départements ne reçoit que celles de leurs courses ; les messages
  disent qui a remis et qui a reçu.
- **Bordereau du jour** par département : ce qu'il a remis au livreur, ou reçu en courrier interne ; signé sur
  place dans l'application (nom, fonction, signature) ou imprimé.

## Quotas
- Quota d'envois du mois par département (plis comptés un à un s'ils sont facturés ainsi).
- Avertissement à 80 % et 100 % au gérant et aux comptes du département ; option : courses refusées au-delà
  (l'agence peut toujours passer outre). Le quota se voit en commandant.

## Facture du mois
- **Ventilée** (par défaut) : une facture, une section par département avec son sous-total et son centre de coûts.
- **Une facture par département** : chacune son numéro ; un employé ne voit que celles de ses départements.
- **Sans détail** : la facture habituelle.
- Forfait réparti selon les envois de chaque département, ou payé par le département principal.
- Export Excel (CSV) : chaque course avec son département et son centre de coûts, puis le total de chaque
  département.
- **Statistiques** (onglet de la page Départements) : courses, envois, reçues, livrées, échecs, délai moyen et coût
  du mois de chaque département.

## Nombre de plis ou de chèques
- Sur une course de distribution, quand « Pli » ou « Chèque » est choisi : le nombre remis ensemble.
- Chaque pli compte dans le forfait ou le lot et se facture, ou la remise compte une fois : au choix
  (Réglages › Courses, ou par contrat).
