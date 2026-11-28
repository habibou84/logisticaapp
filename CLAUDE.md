# Logistica (livraison) — consignes pour Claude

Application de livraison pour **une seule entreprise** (Laravel 12, Vue 3, PWA, Capacitor), reprise de Delivery App
(`habibou84/deliverynew`) sans la partie plateforme : ni super administrateur, ni abonnements, ni sous-domaines.
Elle est servie à `https://app.logisticadelivery.com` (Docker derrière Apache, voir `docs/DEPLOIEMENT.md`).
L'application e-commerçant **Jibiat Marchand** (`habibou84/ecommerce`) s'y connecte par l'API publique
`/api/public/v1` (clés d'API) et ses webhooks. Le code commun est dans `habibou84/socle`.

## Langue
Toujours répondre en français. Textes de l'interface, messages, commits et PR en français.

## Une seule entreprise
- Pas de retour de la plateforme : pas de rôle `super_admin`, de formules (`feature:`), de `Tenancy`, de console.
- La colonne `company_id` et le filtrage par entreprise (`BelongsToCompany`) restent dans le schéma : ne pas les retirer.
- Liens envoyés (WhatsApp, SMS, API) : `Company::url()`, c'est-à-dire `APP_URL`.
- Première mise en service : `php artisan app:install` (entreprise + premier administrateur).

## Mise en ligne : sur demande du propriétaire
- « Ouvre la PR et fusionne » : PR vers `main`, attendre les contrôles verts, fusionner (squash).
  La préproduction (preprod.logisticadelivery.com) l'installe toute seule.
- « Mets en production » : PR `main` → `production`, contrôles verts, fusionner (commit de fusion, pas squash).
  La production l'installe entre 20 h et 7 h.
- « Mets en production immédiatement » : pareil, avec « [immédiat] » dans le titre de la fusion ;
  installation dès que les contrôles du commit de fusion sont verts.
- Ne jamais fusionner sans cette demande explicite ; contrôle rouge = corriger d'abord, ne pas fusionner.
- Sans demande de fusion : travail terminé, testé et poussé → ouvrir la PR et s'arrêter là.
- Ne jamais pousser directement sur `main` ni sur `production`.
- Une PR = un sujet ; description : ce qui change, migrations, risques, ce qu'il faut essayer en préproduction.
- Migrations compatibles avec la version précédente (ajouter avant de retirer) : retour arrière par
  `deploy/rollback.sh` sans toucher à la base.

## Déploiement
- `docker-compose.yml` + `Dockerfile` (images `app` et `web`, nommées d'après la version `APP_VERSION`).
- Scripts : `deploy/install.sh` (production, ou préproduction avec `INSTANCE=preprod`), `deploy/update.sh`,
  `deploy/auto-update.sh`, `deploy/rollback.sh`, `deploy/backup.sh`.
- Deux installations sur le même serveur : production dans `/opt/logistica` (branche `production`), préproduction
  dans `/opt/logistica-preprod` (branche `main`, code d'accès, messages WhatsApp, SMS et e-mails simulés).
- Les conteneurs n'écoutent que sur `127.0.0.1` ; Apache du serveur fait le HTTPS (`deploy/apache-site.conf`).
- Le serveur héberge d'autres sites (PHP 7.4, MySQL) : ne rien installer directement sur le système.
- Avant chaque mise à jour, `deploy/update.sh` sauvegarde la base puis contrôle `/up` (base et Redis compris).
- Sauvegardes chaque nuit, copiées chiffrées chez Cloudflare R2 (base et fichiers, `.env.offsite`).
- Sentry (`SENTRY_LARAVEL_DSN`) reçoit les erreurs : ne jamais y envoyer de données personnelles.

## Tests et style
```bash
php artisan test                                   # SQLite en mémoire (rapide)
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=livraison_test \
  DB_USERNAME=livraison DB_PASSWORD=secret php artisan test   # PostgreSQL, comme la CI
vendor/bin/pint                                    # style (la CI lance pint --test)
npm run build                                      # build front-end
```
Si PostgreSQL ou Redis sont arrêtés : `service postgresql start; service redis-server start`.
Attention aux écarts SQLite / PostgreSQL (dates : utiliser `DATE()`, pas `CAST(... AS DATE)`).

## Contrat avec Jibiat Marchand
- L'API publique et les webhooks suivent le contrat de `habibou84/socle` (`contract/v1`), vérifié par
  `tests/Feature/Api/PublicContractTest.php`.
- Ajouter un champ, un statut ou un événement : libre (version 1.x suivante du contrat, Jibiat Marchand d'abord prêt).
- Retirer, renommer ou changer le type d'un champ : interdit sans nouvelle version majeure annoncée des deux côtés.
- Un changement de socle se fait dans son dépôt, puis `composer update habibou84/socle` ici et dans Jibiat Marchand.

 ## Mise en ligne : sur demande du propriétaire
- « Ouvre la PR et fusionne » : ouvrir la PR vers `main`, attendre les contrôles verts, la fusionner (squash).
  La préproduction (preprod.logisticadelivery.com) l'installe toute seule.
- « Mets en production » : vérifier les contrôles verts sur `main`, publier la version suivante vX.Y.Z
  (étiquette sur `main`, liste des changements) ; la production l'installe toute seule.
- Ne jamais fusionner ni publier sans cette demande explicite ; contrôle rouge = corriger d'abord, ne pas fusionner.
- Sans demande de fusion : travail terminé, testé et poussé → ouvrir la PR et s'arrêter là.
- Une PR = un sujet ; description : ce qui change, migrations, risques, ce qu'il faut essayer en préproduction.
- Migrations compatibles avec la version précédente (ajouter avant de retirer) : retour arrière par
  `deploy/rollback.sh` sans toucher à la base.
