# Activité des livreurs

Une seule entrée de menu, **Activité des livreurs** (`/admin/activite`), avec des onglets : Suivi du jour, Carte,
Disponibles, Colis en main, Remontées terrain, Planning. Les anciennes adresses (`/admin/carte`, `/admin/planning`…)
mènent au bon onglet. Chaque onglet suit son droit (le planning et les remontées ont le leur). Sur téléphone :
onglet **Livreurs** dans la barre du bas.

## Suivi du jour
- Par livreur : livraisons et ramassages faits sur prévus, échecs, colis et argent en main, rythme et taux de
  réussite comparés à ses 30 derniers jours, **fin estimée** (alerte s'il risque de finir après la fermeture du
  dépôt), avancement vers sa **prime**.
- Une course compte pour son **jour de livraison** (date prévue, sinon jour de création). Aujourd'hui, les courses
  prévues avant et encore à faire sont comptées à part (« en retard d'avant »), hors avancement.
- **À surveiller** : remontée ouverte, colis gardé trop longtemps, argent au-delà du plafond ou gardé plus d'un jour,
  mission en retard, pas de position depuis 20 min, fin après la fermeture du dépôt, retards d'avant.
- **Distribution** : plis et chèques comptés un à un, courses hors délai interne, accusés de réception manquants
  (livrée sans la photo demandée), avancement des lots en cours.
- **MAD** : le livreur réservé apparaît « En MAD chez … » avec son horaire et les courses faites pour le client ; il
  n'entre ni dans le rythme ni dans la fin estimée, et n'est proposé pour reprendre une course que si elle est de son
  client.
- Filtre par activité (e-commerce, distribution, MAD) ; un jour passé ou l'un des 30 jours à venir (ce qui est prévu).
- Un livreur choisi : position, trajet et prochains arrêts, missions du jour ; appeler, WhatsApp, envoyer une
  consigne, **réassigner** une mission (les plus proches et les moins chargés d'abord), faire le point à la caisse.
- Export CSV ; **écran mural** sans montant (`/admin/activite/mural`) pour le dépôt.

## Colis en main et planning
- Colis en main : résumé par livreur, colis chargés en ouvrant un livreur (25 par 25), recherche côté serveur.
- Planning : clients sans ramasseur seulement s'ils ont des colis ce jour-là, rangés par zone (la plus chargée
  d'abord), avec leur livreur habituel ; les clients habituels sans colis à part ; la MAD du jour reste affichée.

## Livraisons du jour dans l'application livreur
Réglages › Courses et livraison › **Livraisons du jour**, par activité :
- **E-commerce** (par défaut : jour même) : seulement les livraisons prévues ce jour-là ;
- **Distribution** (par défaut : dans le délai) : celles du jour, plus celles encore dans leur délai interne ou dont
  le lot n'a pas atteint son échéance ;
- **MAD** (par défaut : sans limite).

Le livreur ne voit pas les autres livraisons (bandeau « N livraisons prévues un autre jour : voyez avec l'agence ») et
ne peut pas les faire partir ni les clore ; une action faite hors connexion est jugée au jour où elle a été faite.
Les retards et les jours à venir se règlent à l'agence (nouvelle date ou annulation).
