# Événements terrain

Le livreur signale ce qui lui arrive sur la route (panne, accident, contrôle, vol, météo…), même sans réseau.
Tout se règle dans **Réglages › Événements terrain** ; le suivi est dans **Opérations › Remontées terrain › Événements terrain**.

## Côté livreur
- Bouton **« Signaler un problème »** en haut de ses missions.
- Il choisit le type (grosses icônes), dit s'il peut continuer, s'il est bloqué un moment (30 min à 4 h) ou s'il
  arrête pour la journée, puis ajoute une photo, une note vocale, un mot et le coût du dépannage.
  Ses courses en cours sont cochées d'office.
- Sa position est jointe (selon le type). Sans réseau, le signalement part tout seul au retour du réseau, une seule fois.
- Il suit son signalement (« Pris en charge par… »), échange avec l'agence, puis touche **« C'est réglé, je reprends »**.

## Côté équipe
- Alerte immédiate (son, cloche, notification du bureau) au dispatch et aux responsables d'activité ; un événement
  urgent prévient aussi les administrateurs (réglable). Sans prise en charge, relances avec le même délai que les
  remontées des courses (Réglages › Alertes et rappels).
- Sur l'événement : carte du lieu, photos, note vocale, courses touchées, échange avec le livreur, puis :
  - **Je m'en occupe** (le livreur voit qui suit son signalement) ;
  - **Confier les courses à un autre livreur** : livreurs en service du plus proche au plus loin ; un colis déjà chez
    le livreur bloqué (même en route) passe au nouveau, qui reçoit une consigne pour aller le récupérer, avec la
    position. Une livraison arrêtée ainsi ne compte pas comme une tentative ;
  - **Retirer ses ramassages** pas encore faits (remplaçant du planning, sinon dispatch) ;
  - **Prévenir les clients du retard** (une fois par course) ;
  - **Marquer comme réglé** (avec ce qui a été fait), ou rouvrir.
- Le livreur concerné porte un badge sur la carte des livreurs, les livreurs disponibles et la page Livreurs.

## Effets automatiques (au choix de l'administrateur)
Quand le livreur est bloqué ou arrête sa journée :
- il passe **hors service** jusqu'à sa reprise (plus de missions) ;
- ses **ramassages pas encore faits** lui sont retirés (désactivé par défaut) ;
- une journée arrêtée est notée au **planning** (absence du jour, retirée s'il reprend) ;
- les **clients** des courses touchées sont prévenus d'un retard, sans détail (désactivé par défaut ; modèle
  WhatsApp `course_retardee` à faire approuver).

Pour les types marqués « Retards excusés », les retards et échecs des courses touchées ne comptent ni dans la note
automatique du livreur ni dans la prime au taux de réussite.

## Types
Panne, Accident, Contrôle, Vol / agression, Météo / route, Matériel, Santé, Autre. Pour chacun : icône, libellé,
précision, gravité (info, gênant, bloquant, urgent), photo obligatoire, position jointe, retards excusés, proposé ou non.
On peut en ajouter ; un type enregistré se retire en décochant « Proposé ».

## Suivi
- **Statistiques › Terrain** : nombre par type, délai de prise en charge et de résolution, immobilisation des
  livreurs, coût des dépannages, livreurs et zones les plus touchés.
- Page **Livreurs** : événements des 30 derniers jours de chacun.
- **Point de la semaine** (cloche) : nombre d'événements et immobilisation.
