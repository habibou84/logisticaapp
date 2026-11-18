# Atelier : pannes et mécaniciens

Les pannes signalées par les livreurs sont confiées à un mécanicien, qui les traite depuis son application
(`/mecanicien`, installable sur le téléphone). L'équipe suit tout dans **Équipe › Atelier** (`/admin/atelier`).

## Rôles
- **Mécanicien** : application `/mecanicien` seulement. Compte créé dans Utilisateurs (rôle « Mécanicien »),
  connexion par téléphone et mot de passe ou lien d'invitation, comme un livreur.
- **Responsable du parc** : véhicules, événements terrain et atelier (droit « Atelier (pannes et mécaniciens) »).
  Le dispatcher a aussi l'atelier.
- **Administrateur** : en plus, accorde ou refuse les achats de pièces sur place (droit « Accorder les achats de
  pièces sur place », qu'on peut donner à un rôle sur mesure).

## Parcours d'une panne
1. Le livreur signale une **panne** ou un **accident** (événement terrain). Si l'entreprise a au moins un mécanicien,
   une intervention est créée, avec le véhicule du livreur et sa position.
2. **Attribution automatique** : proposée au mécanicien **en service** le plus proche (dernière position connue),
   puis au moins chargé ; pas à un mécanicien qui a déjà une proposition en attente ou une panne urgente en cours.
   Alerte plein écran avec sonnerie (et notification push). Sans réponse en **5 minutes** ou s'il refuse : au suivant.
   Personne en service ou tout le monde a refusé : l'équipe de l'atelier est prévenue (une fois) et confie la panne à
   la main. Un mécanicien qui se met en service reçoit les pannes en attente ; il peut aussi en prendre une lui-même.
3. **Acceptée** : l'événement terrain est pris en charge par le mécanicien ; le livreur voit qui vient et peut
   l'appeler. Ils échangent dans le fil du signalement.
4. **Je pars**, **Je suis arrivé** (heure et position) : l'heure du clic est gardée même sans réseau, envoyée au
   retour du réseau.
5. **Diagnostic** : type de panne (moteur, électrique, pneu, freins…), pièce touchée, photos avant, décision (réparé
   sur place, remorqué au garage, véhicule de remplacement). Remorquage ou remplacement : l'équipe est prévenue.
6. **Achat sur place** (pièce manquante, magasin fermé) : demandé par le mécanicien, accordé avec un montant maximum
   ou refusé par l'administrateur. À la clôture, le mécanicien indique le prix payé et photographie la facture.
7. **Clôture** : pièces (montant saisi en attendant le magasin de pièces), cause, compteur, photos après, à prévoir.
   Le coût entre au **carnet du véhicule** (la ligne « Réparation » posée au signalement est complétée). Si le livreur
   peut repartir : l'événement est réglé, le véhicule et le livreur reviennent en service. Sinon l'équipe organise
   la suite.

Le livreur qui reprend (« C'est réglé ») avant l'arrivée du mécanicien annule l'intervention ; le mécanicien est
prévenu. L'équipe peut aussi créer une intervention (problème vu au dépôt) et l'annuler.

## Heures et délais
Chaque étape garde son heure : reçue, acceptée, départ, arrivée, début, fin. L'écran Atelier donne les délais moyens
du jour : réponse (reçue → acceptée), arrivée (acceptée → sur place), immobilisation (reçue → fin).

## Technique
- Tables `mechanics` (en service, position), `repairs` (intervention), `repair_files` (photos), `repair_purchases`
  (achats sur place) ; réglages dans `companies.workshop_settings` (`WorkshopSettings` : attribution automatique,
  délai pour accepter, types d'événements, types de panne).
- `RepairDispatcher` (attribution, relances), `Repairs` (étapes, achats, clôture) ; commande `repairs:dispatch`
  chaque minute (propositions expirées, pannes sans mécanicien).
- API : `/api/v1/mechanic/*` (rôle mécanicien), `/api/v1/repairs*` et `/api/v1/repair-purchases*` (équipe).
- Lots suivants : magasin de pièces (moyens généraux), heures supplémentaires, évaluation et primes, retenues livreur.
