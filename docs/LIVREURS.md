# Page Livreurs et fiche du livreur

Reprise de Jibiat Livraison (page Livreurs en tableau, fiche complète), adaptée : mise à disposition, activités,
parc de véhicules, événements terrain, capacités et paie.

## Page Livreurs (`/admin/livreurs`)
Droits : répartir les courses, gérer les livreurs ou gérer les notes.

- **Résumé cliquable** : livreurs, disponibles, en MAD aujourd'hui, note moyenne, note à suivre, alertes véhicule.
- **Tableau** (25 par page, tri sur chaque chiffre) : note sur 5, état (disponible, MAD, événement terrain en cours,
  dernière position), zones, véhicule et ses alertes, missions en cours, livrées, réussite, temps de livraison, échecs,
  note à suivre.
- **Filtres** : recherche, zone, disponibilité, MAD, véhicule, note, activité (les chiffres d'une seule activité),
  compte (actifs, suspendus), période (7, 30 ou 90 jours). Les filtres restent dans l'adresse.
- Sur téléphone : une ligne par livreur.

## Fiche du livreur (`/admin/livreurs/{id}`)
- En-tête : note, état, MAD, événement en cours ; appeler, WhatsApp, ses courses, carte, modifier.
- **Activité** : aujourd'hui (livraisons, ramassages, restantes, échecs, colis et argent en main, points à surveiller),
  chiffres de la période et livraisons jour par jour. Encaissé, écart de caisse et gains : seulement pour qui voit la caisse.
- **Notes** : la note détaillée (automatique, clients, équipe), son évolution, l'évaluation du mois par l'équipe,
  les dernières notes (neutraliser, rétablir).
- **Terrain** : événements de la période (droit Remontées terrain).
- **Informations** : profil, véhicule du parc et ses alertes, ce qu'il fait, notes internes, liens utiles.

La page « Qualité des livreurs » est retirée du menu : ses anciennes adresses mènent à la page Livreurs ou à
l'onglet Notes de la fiche.

## API
| Méthode | Adresse | Rôle |
|---|---|---|
| GET | `/api/v1/couriers/directory` | tableau (filtres, tri, pagination, résumé) |
| GET | `/api/v1/couriers/{id}/profile?days=7\|30\|90` | fiche du livreur |
