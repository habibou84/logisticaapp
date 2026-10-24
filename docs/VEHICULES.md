# Gestion des véhicules

Menu **Équipe › Véhicules** (parc, fiche, échéances) ; réglages dans **Réglages › Véhicules** ; chiffres dans
**Statistiques › Véhicules**. Tout est au choix de l'administrateur.

## Le parc
- Fiche : type, immatriculation, marque, modèle, année, couleur, châssis, photo, capacité (colis, poids, coffre),
  compteur, prix d'achat, notes.
- Propriétaire : entreprise, véhicule du livreur, location, location-vente. État : en service, en panne,
  à l'entretien, immobilisé, hors service, vendu.
- **Confier à** un livreur (attitré) : il rend le véhicule qu'il avait ; le type et la plaque de sa fiche suivent
  (la livraison automatique s'en sert). Historique des remises (qui avait le véhicule, quand, quel compteur).

## Papiers et échéances
- Assurance, visite technique, vignette, carte grise, carte de transport, autre ; permis de conduire du livreur.
  Date de fin, numéro, coût, photo ou PDF. Un renouvellement garde l'ancien papier dans l'historique.
- Rappels aux seuils choisis (30 et 7 jours par défaut), puis à la date passée, aux responsables et au livreur.
- Option : un véhicule dont un papier obligatoire (assurance, visite…) est dépassé est **bloqué**.
- Page **Échéances** : tout ce qui a une date (papiers, entretien, versements en retard).

## Entretien et carnet
- Plan d'entretien par véhicule (proposé selon le type, modifiable) : tous les … km et/ou … mois.
  Rappel à l'approche (km et jours réglables), puis à l'échéance.
- Carnet : entretien (remet à zéro les lignes du plan faites), réparation, sinistre, amende, carburant, autre ;
  garage, montant, compteur, jours d'immobilisation, factures en photo ; état du véhicule après.
- Panne ou accident signalé (événement terrain) : véhicule « en panne » et ligne au carnet ; remis en service à la
  reprise du livreur (option).

## Côté livreur
- En haut de ses missions : son véhicule du jour et **l'état des lieux** (chaque jour, chaque lundi ou jamais) :
  compteur avec photo, essence, contrôle rapide (points choisis). Un problème prévient l'équipe sans l'empêcher de
  partir. Option : obligatoire avant de se mettre en service ; aussi au retour (kilomètres GPS de la journée).
- Sans véhicule attitré : il prend un véhicule libre pour la journée, rendu au retour (ou le lendemain matin).
- **Mon véhicule** (Profil) : papiers à montrer en cas de contrôle, entretien à venir, pleins du mois,
  avancement de sa location-vente, bouton **Plein** (montant, litres, compteur, reçu).
- Option : sans véhicule en état, pas de missions (sauf livreurs à pied). Capacité du véhicule utilisée par la
  livraison automatique (option).

## Argent et paie
- Carburant : **dotation** par jour travaillé (ajoutée à la paie, dépassement remboursé si voulu),
  **remboursement** des pleins sur reçu, ou **à la charge du livreur**.
- **Location-vente** ou location : montant par jour (7, 6 ou 5 jours sur 7), semaine ou mois, prix total ;
  retenue sur la paie chaque jour ou paiement en caisse ; retard, rappels au livreur puis au responsable ;
  prix total payé : le véhicule passe au nom du livreur.
- Indemnité « véhicule personnel » par jour travaillé (0 : aucune).
- Amendes retenues au livreur qui avait le véhicule (décochable amende par amende) ; part des dégâts d'un sinistre
  retenue au responsable, avec un plafond par mois. Supprimer une ligne du carnet annule sa retenue ou son remboursement.

## Suivi
- Coûts par véhicule (au km et à la course), tableau de bord du parc.
- Statistiques › Véhicules : coût du parc, carburant, entretien, immobilisation ; coût par course de chaque
  véhicule ; distance au compteur comparée au trajet GPS ; consommation comparée à la moyenne du véhicule (alertes).
- Point de la semaine (cloche) : véhicules immobilisés, échéances, location-vente en retard.
- Tâche du matin `vehicles:daily` (6 h 40) : rappels, retenues de location-vente, dotations de la veille.
