# Annuaire des entreprises de livraison et connexion en un clic

Objectif : les e-commerçants trouvent une entreprise de livraison fiable, et les entreprises de livraison trouvent
de nouveaux clients. Contrat partagé avec Jibiat Marchand : `habibou84/socle`, schémas `directory` et `connect-token`.

## Annuaire

- L'entreprise choisit d'y figurer : Réglages › E-commerçants › « Figurer dans l'annuaire », avec une phrase de présentation.
- Ouvert à toutes les formules : la clé remise à Jibiat Marchand par la connexion en un clic (`api_keys.client`) ouvre l'API publique et les webhooks même sans le module Intégrations ; les clés créées à la main restent réservées à ce module.
- Publié sur `jibiat.com/annuaire` et en JSON sur `GET https://jibiat.com/api/public/directory` (lu par Jibiat Marchand).
- Chiffres calculés par l'application sur 90 jours, hors courses d'essai : colis livrés, part de réussite
  (livrés / livrés + retours + perdus), délai moyen entre l'enregistrement et la livraison. Masqués sous 20 courses.
- Prix de départ : plus petit prix de la grille de tarifs par défaut. Mise à jour toutes les 10 minutes, aussitôt
  après une modification de la fiche.

## Connexion en un clic (Jibiat Marchand)

1. Dans Jibiat Marchand, l'e-commerçant choisit une entreprise et touche « Connecter ».
2. Il arrive sur `{entreprise}.jibiat.com/marchand/connecter` :
   - déjà client : il se connecte puis touche « Autoriser » ;
   - inscriptions ouvertes : il crée son compte, puis autorise ;
   - inscriptions fermées : il envoie une demande d'ouverture de compte.
3. Jibiat Marchand reçoit un code à usage unique (10 minutes, PKCE) et l'échange contre une clé `lv_…` ; il crée
   lui-même son adresse webhook. Le marchand ne copie jamais de clé.

Applications autorisées et adresses de retour : `config/connect.php` (`CONNECT_JIBIAT_MARCHAND_HOSTS`, par défaut
`jibiat-marchand.com,*.jibiat-marchand.com`). L'accès se coupe en supprimant la clé dans Profil › Intégrations.

## Demandes d'ouverture de compte

- Arrivent dans E-commerçants (encadré « nouveaux clients potentiels ») et dans la cloche de l'équipe qui gère les e-commerçants.
- Accepter crée la boutique et le compte du gérant avec le mot de passe qu'il a choisi ; il peut se connecter aussitôt
  et relancer « Connecter » depuis Jibiat Marchand. Refuser clôt la demande. Le mot de passe haché est effacé dans les deux cas.

## Inviter ses e-commerçants sur Jibiat Marchand

Réglages › E-commerçants donne à l'entreprise son lien d'invitation :
`https://jibiat-marchand.com/creer-ma-boutique?partenaire=<identifiant de l'entreprise>` (adresse de Jibiat Marchand
réglable par `JIBIAT_MARCHAND_URL`). Si l'entreprise figure dans l'annuaire, Jibiat Marchand offre un mois d'essai en
plus à l'e-commerçant invité et garde l'entreprise comme parrain. Le lien se copie ou s'envoie par WhatsApp.
