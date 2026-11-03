# Statistiques avancées

Menu **Statistiques** de l'équipe. Les montants sont réservés aux comptes qui voient la caisse.

## Organisation de la page

Cinq rubriques, du « où en est-on ? » au « pourquoi ? », puis l'analyse et le partage. Chaque vue dit en une phrase
ce qu'elle montre ; la période et l'activité ne s'affichent que si elles s'y appliquent. En haut à droite :
**Exporter en Excel** (toute la période) et **Rapport du mois** (à imprimer ou enregistrer en PDF).

| Rubrique | Vues | Question à laquelle elle répond |
|---|---|---|
| 🎯 Pilotage | Vue d'ensemble · Objectifs et tendances | Où en est-on, et va-t-on atteindre nos objectifs ? |
| 🚚 Opérations | Livraisons · Délais et échecs · Livreurs · Zones · Terrain · Véhicules | Comment se passent les livraisons, et où ça coince ? |
| 🏪 Clients | Activité des clients · Fidélité et paiements | Qui envoie, qui baisse, qui paie en retard ? |
| 💰 Argent | Rentabilité · Trésorerie | Que gagne-t-on (marge) et où est l'argent aujourd'hui ? |
| 🔀 Analyse et partage | Analyse croisée · Partage et alertes | Creuser une question, être prévenu, partager les chiffres. |

Une seule source par chiffre : le compte de résultat (revenus, coûts, frais fixes, marge) est dans **Rentabilité** ;
**Trésorerie** ne montre que l'argent en circulation. Les seuils d'objectifs et de rentabilité se règlent dans
**Objectifs et tendances** ; les alertes, e-mails, Google Sheets et rapports clients dans **Partage et alertes**.
Les adresses gardent l'onglet (`?onglet=quality`, `?onglet=profitability`…), utilisées par les alertes.

## Étape 1 — Rentabilité et objectifs

### Onglet « 💹 Rentabilité » (droit caisse)
- **Revenus** : frais e-commerce inscrits au compte des clients (livraison, retour, stockage) ; distribution et MAD :
  montant facturé des courses livrées, forfaits mensuels au prorata des jours, prix des lots commencés sur la période.
- **Coûts directs** : paie des livreurs liée aux courses ; paie sans course (fixe, primes, journées MAD, indemnités) ;
  véhicules payés par l'entreprise (carburant, entretien, réparations, papiers, moins la part du livreur) ; frais de
  course à la charge de l'entreprise ; colis perdus.
- **Frais fixes** : saisis dans l'onglet (loyer, salaires de l'équipe, téléphone…), chaque mois ou une fois,
  comptés au prorata des jours de la période.
- **Répartition (estimation)** : la paie sans course et le véhicule d'un livreur se répartissent sur ses missions de
  la période ; un livreur mis à disposition compte entièrement sur ses contrats MAD ; les frais fixes se répartissent
  au nombre de courses livrées.
- Vues : **par client** (rentable, à renégocier sous le seuil de marge, à perte), **par zone** (avec simulation d'un
  nouveau tarif moyen), **par livreur** (livraisons par jour, paie, véhicule, coût par mission, marge), **contrats et
  forfaits** (utilisation du forfait par mois : à revoir à la hausse, bien dimensionné, sous-utilisé ; MAD : prix du
  contrat contre coût des livreurs), **frais fixes**. Chaque tableau s'exporte.

### Onglet « 🎯 Objectifs »
- Objectifs du mois (courses livrées, chiffre d'affaires, taux de réussite, marge nette), fixés par un administrateur ;
  réalisé, pourcentage, projection de fin de mois au rythme actuel (forfaits et frais fixes comptés pour le mois
  entier, pas multipliés).
- Comparaison du mois (à ce jour et estimé) avec le même mois l'an dernier et la moyenne des 3 mois précédents.
- 13 mois : courses créées et livrées, marge, tableau mois par mois (export).
- Seuils réglables : client « à renégocier » (10 % de marge par défaut), forfait « à revoir à la hausse » (au-delà de
  100 %) et « sous-utilisé » (sous 50 %).

### API
- `GET /stats/profitability?from=&to=&activity=` — rentabilité (droit caisse).
- `GET /stats/performance?activity=` — objectifs et 13 mois.
- `GET|POST /fixed-costs`, `PUT|DELETE /fixed-costs/{id}` — frais fixes (lecture : caisse ; saisie : « Encaisser et reverser »).
- `PATCH /companies/{id}` avec `stats_settings` (`goals`, `renegotiate_margin`, `forfait_high`, `forfait_low`).

## Étape 2 — Délais, échecs, distribution, alertes

### Onglet « ⏱ Délais et échecs »
- **Respect des délais** : e-commerce livré au plus tard le jour prévu ; distribution et MAD avant leur délai
  interne (sans délai : le jour prévu) ; par activité, client, zone et livreur.
- **Lots** arrivés à échéance sur la période : terminés à temps, en retard ou en cours.
- **MAD** : jours de présence faits (hors absences) sur jours prévus par le contrat.
- **Échecs** : motifs, heures, motifs × zone (carte de chaleur), par livreur, client et zone avec le motif le plus
  fréquent (ex. un client aux numéros faux, une zone aux absences).
- **Distribution** : plis et chèques remis par jour, accusés manquants, temps médian par pli, lots en cours contre
  la courbe idéale jusqu'à l'échéance.
- **Courses non traitées dans le temps** : relevé chaque soir à 23 h 50 (`stats:daily --snapshot`), avec le délai
  interne dépassé et les courses sans livreur.

### Alertes du matin (07 h 35, `stats:daily --alerts`)
Réglables dans Analyse et partage › Partage et alertes : zone sous un taux de réussite (80 %) sur 7 jours, livreur dont les
échecs doublent par rapport à la semaine d'avant, client en baisse (−30 % sur 30 jours contre la moyenne des 3 mois
d'avant), volume minimal (10). Cloche des administrateurs, et WhatsApp si coché (modèle « alerte_statistique »).
Une même alerte n'est pas renvoyée avant 7 jours.

API : `GET /stats/quality?from=&to=&activity=`.

## Étape 3 — Clients et analyse croisée

### Onglet « 🏪 Fidélité et paiements » (droit Clients ; montants : caisse)
- **Concentration (80/20)** : nombre de clients qui font 80 % du chiffre d'affaires, part du premier et des trois
  premiers (alerte au-delà de 30 % pour un seul client).
- **Clients en baisse** : 30 derniers jours contre la moyenne mensuelle des 3 mois d'avant (baisse de 20 % et plus),
  avec boutons Appeler et WhatsApp (message de relance prérempli).
- **Fidélité des nouveaux clients** : part des clients arrivés un mois qui envoient encore 1, 2, 3 et 6 mois après.
- **Paiement des factures** : délai moyen de paiement, reste à payer pas encore échu puis par retard (1-30, 31-60,
  61-90, plus de 90 jours), par client.

### Onglet « 🔀 Analyse croisée »
Deux axes au choix (client, zone, livreur, activité, jour de la semaine, mois, heure de création) et un indicateur
(courses, livrées, échecs, taux de réussite, plis et colis, chiffre d'affaires), sur les courses créées pendant la
période. Export du tableau.

API : `GET /stats/clients`, `GET /stats/pivot?rows=&cols=&metric=`.

## Étape 4 — Intégrations (sans IA)

- **📥 Excel** (en haut des statistiques) : un fichier de plusieurs onglets pour la période (synthèse, mois par mois,
  rentabilité par client, zone et livreur, contrats, délais, échecs, clients en baisse, paiements). Onglets avec
  montants : droit caisse. `GET /stats/export?from=&to=&activity=`.
- **🖨 Rapport du mois** (`/admin/rapport-mensuel?mois=AAAA-MM`) : page à l'en-tête de l'entreprise, à imprimer ou
  enregistrer en PDF (chiffres clés, objectifs, rentabilité, 10 plus gros clients, qualité de service).
- **Vue « Partage et alertes »** :
  - **Google Sheets** : « Créer le lien », puis copier une formule `=IMPORTDATA("…")` dans une cellule. Jeux : mois
    par mois, rentabilité clients, zones, livreurs, délais, échecs, relevés du soir ; `?jours=90` pour une autre
    période (30 jours par défaut). Le lien contient une clé secrète (`companies.stats_feed_token`) : « Nouveau lien »
    rend l'ancien inutilisable, « Couper le lien » l'arrête. `GET /api/v1/stats-feed/{clé}/{jeu}.csv`.
  - **Point par e-mail** : destinataires, chaque lundi (semaine écoulée) et/ou le 1er du mois (mois écoulé), avec le
    fichier Excel. Bouton « Envoyer un essai ». Le serveur doit avoir un envoi d'e-mails configuré (`MAIL_MAILER`,
    `MAIL_HOST`… dans `.env`).
  - **Rapports mensuels des clients sous contrat** (distribution, MAD) : préparés en brouillon le 1er du mois
    (`stats:email monthly`), relus par l'équipe (droit « Gérer les factures » pour envoyer), avec un mot facultatif.
    Une fois envoyé, le rapport ne change plus ; le client (propriétaire, gestionnaire) est prévenu et le retrouve
    dans **Mes rapports** (`/marchand/rapports`), imprimable.
- **Espace client** : statistiques enrichies de la part des courses livrées dans le délai et des courses par
  département.

Tâches planifiées : `stats:email weekly` (lundi 07 h 40), `stats:email monthly` (1er du mois 07 h 45).
