# Statistiques avancées

Menu **Statistiques** de l'équipe. Les montants sont réservés aux comptes qui voient la caisse.

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
