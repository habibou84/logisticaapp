# Page Courses et tableau de bord de l'équipe

Repris de Jibiat Livraison (page Courses et tableau de bord de novembre 2026), adapté à la distribution, à la MAD,
aux lots, aux contrats et aux factures de Logistica.

## Page Courses
- **Bandeau « réglages à faire »** (administrateurs) : ce qui empêche l'assignation automatique — automatisation en
  pause, planning vide, ramassage ou livraison automatique coupés ou à l'essai, validation manuelle, pas d'heure
  limite, **contrats MAD sans livreur désigné**. Chaque ligne ouvre la page ou le réglage ; « Plus tard » le cache
  pour la journée.
- **Point du jour** : livrées / prévues ; e-commerce (droit caisse) : recette, encaissé (caisse ou livreurs), à
  reverser ; distribution : plis et chèques comptés un à un, accusés manquants, hors délai interne, lots dont
  l'échéance approche ; MAD : livreurs à disposition, clients, courses faites, contrats sans livreur. Bouton
  « Envoyer sur WhatsApp » (montants seulement si l'entreprise le veut et pour qui voit la caisse).
- **Onglets du jour choisi** : Toutes, À valider, À ramasser (ramassages en cours, livreur assigné), Ramassages par
  client, À préparer, Sans livreur, À livrer, Échec, À retourner. Aujourd'hui, s'y ajoutent les courses d'avant
  encore dans leur règle (lot pas échu, MAD), marquées « encore dans son délai ».
- **Files propres à Logistica**, toutes dates, en onglet **seulement s'il y en a** : À approuver (chef de service),
  À décider, En attente, Suivi, Délai interne dépassé, Incidents, Prévues plus tard. Carte rouge : codes à vérifier.

## Courses non traitées (`/admin/courses/non-traitees`)
Jour de livraison dépassé, course pas terminée, et plus dans la règle de son activité (Réglages › Courses,
« Livraisons du jour ») :
- **Jour même** (e-commerce par défaut) : dès le lendemain ;
- **Dans le délai** (distribution par défaut) : après **l'échéance du lot** ; une course sans lot, dès le lendemain ;
- **Sans limite** (MAD par défaut) : jamais.

Regroupées par date, par livreur ou par client (filtre d'activité). Actions groupées : nouvelle date, confier à un
livreur (ramassage ou livraison selon la course), annuler (droit « Annuler une course »).

## Tableau de bord de l'équipe (dispatch)
- **À faire maintenant** : heure limite dépassée, Sans livreur, À valider, Courses non traitées ; puis, s'il y en a :
  À approuver, Délai interne dépassé, Lots à échéance proche, Contrats MAD sans livreur, **Factures à émettre**
  (brouillons à envoyer et clients du mois dernier pas encore calculés) ; pastilles : à retourner, échecs, en
  attente, suivi, à préparer, codes à vérifier. Chaque carte ouvre l'onglet ou la page.
- **Point du jour ou de la période** (Aujourd'hui, Hier, 7 jours, Ce mois, Autre ; 92 jours au plus), filtre
  d'activité.
- **Livreurs du jour** (livrées, plis, colis en main, encaissé, client MAD), écran mural, dernières courses,
  vidéos « Bien démarrer », contrats à suivre (forfaits à 80 %, lots en retard, factures en retard de paiement).

## Réglages (Réglages › Courses et livraison › Tableau de bord et point du jour)
`companies.dashboard_settings` : cartes « À faire maintenant » masquées, alerte des lots (48 h par défaut), montants
dans le point partagé (oui par défaut).

## API (équipe)
- `GET /reports/day?day=` ou `?from=&to=` (`activity` facultatif) — point du jour ou de la période.
- `GET /reports/auto-assign-checks` — réglages à faire (droit Réglages).
- `GET /reports/todo` — lots à échéance, MAD sans livreur, factures à émettre, cartes masquées.
- `GET /orders?queue=untreated` (+ `lead_courier_id`, `without_courier`), `GET /orders/untreated-groups?by=date|courier|merchant`.
- `POST /orders/bulk-reschedule` (`order_ids`, `date`), `POST /orders/bulk-cancel` (`order_ids`, `reason`).
- `GET /orders/counts` : `exceptions` (files toutes dates), `day.failed`, `day.to_return`, filtre `activity`.

L'API publique (Jibiat Marchand) ne change pas.
