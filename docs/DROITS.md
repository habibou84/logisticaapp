# Droits : permissions, rôles, périmètre

Menu **Équipe › Utilisateurs** et **Équipe › Rôles** (droit « Voir l'équipe » ; modifier : « Gérer l'équipe et les
rôles »).

## Permissions par métier
Rangées par domaine, chacune avec ce qu'elle ouvre (écran Rôles) :

| Domaine | Permissions |
|---|---|
| Courses | voir, créer, dispatch (assigner, carte, journée, retours, messages), changer l'état, **annuler une course** |
| Livreurs | gérer les livreurs (fiche, zones, véhicule), planning et automatismes, véhicules, remontées et événements terrain, notation (neutraliser une note, réglages), paie |
| Clients | voir, gérer (clients, leurs comptes, demandes d'ouverture), contrats et tarifs (distribution, intérieur), départements, annuaire, stock, clés d'API |
| Argent | caisse (voir, encaisser et reverser), factures (voir ; calculer, émettre, encaisser ; **annuler une facture émise**), chiffre d'affaires |
| Réglages et équipe | réglages généraux, zones et dépôts, grille des tarifs, WhatsApp, voir l'équipe, gérer l'équipe et les rôles |

- Les réglages de l'entreprise se contrôlent **champ par champ** : les réglages des véhicules demandent « Véhicules »,
  ceux de la notation « Notation », les frais de retour « Grille des tarifs », etc. ; le reste, « Réglages
  généraux ». Un réglage renvoyé sans changement ne demande rien.
- Mise à jour : les rôles intégrés gardent exactement ce qu'ils faisaient (le dispatcher garde planning, véhicules,
  terrain et l'annulation des courses ; le caissier, l'annulation des factures) ; un compte qui avait une permission
  large en plus (ou en moins) reçoit (ou perd) aussi celles qui en sont nées.

## Rôles
- **Intégrés** (administrateur, responsable d'activité, dispatcher, caissier, agent de dépôt, livreur) : fixes, ils
  servent de modèles.
- **Sur mesure** : « + Nouveau rôle », en partant de rien, d'un modèle prêt à l'emploi (comptable, responsable
  flotte, service client, commercial, chef d'équipe d'un dépôt) ou d'une copie d'un rôle intégré. Nom, description,
  droits, périmètre par défaut. Modifier un rôle change tous ses comptes ; un rôle n'est supprimé que si personne ne
  l'a plus.
- **Qui peut faire quoi** : tableau des droits × rôles.
- Garde-fous : personne ne donne un droit qu'il n'a pas lui-même (rôle, droit ajouté à un compte) ; seul un
  administrateur fait un administrateur ; un compte plus puissant que soi ne se modifie pas ; le dernier
  administrateur actif ne peut être ni rétrogradé, ni suspendu, ni supprimé.

## Compte par compte
- **Réglage fin** : droits ajoutés ou retirés au rôle, case par case (« ajoutée », « retirée », « du rôle »).
- **Périmètre** : activités (e-commerce, distribution, MAD), zones (communes, avec leurs quartiers) et dépôts. Le
  compte ne voit que les courses ramassées ou livrées dans ses zones, ou passées par ses dépôts. Celui du compte
  remplace celui de son rôle ; rien de coché : tout.
- **👁 Voir comme** : son menu tel qu'il l'aura, ses droits effectifs et son périmètre.

## Comptes des clients
Fiche du client › **Comptes** (équipe) ou **Moi › Mon équipe** (application client) :

| Rôle | Ce qu'il fait |
|---|---|
| Client (propriétaire) | Tout : courses, caisse, factures, comptes, clés d'API |
| Gestionnaire | Courses, caisse, factures et comptes (sauf le propriétaire) ; voit tous les départements et valide leurs demandes |
| Agent | Crée et suit les courses (de ses départements s'il y en a) |
| Lecteur | Suit les courses, sans rien créer ni annuler |

Le dernier propriétaire actif d'un client reste propriétaire.

### Validation par le chef de service
Réglage des départements (entreprise ou client) **« Validation par le chef de service »**, et case **chef** dans
l'onglet Comptes des départements. La course demandée par un agent d'un département qui a un chef attend son accord
(ou celui d'un gérant) : notification, file « À valider » et bandeau sur l'accueil de l'application client, boutons
**Valider / Refuser** (refus avec motif : course annulée). Tant qu'elle attend, l'agence ne peut ni la valider ni lui
confier un livreur (badge « Attend le chef de service du client »). Validée : validation habituelle (automatique
si l'entreprise l'a choisie). Les demandes du chef ou du gérant partent directement.

## Capacités des livreurs
Fiche du livreur › **Ce qu'il fait** (chaque ligne : comme l'entreprise, oui, non) : encaisse l'argent des colis,
fait la MAD, fait l'intérieur, travaille hors connexion, voit son gain prévu. Un livreur ne reçoit pas les courses
qu'il ne fait pas, à la main (message clair) comme en automatique.
