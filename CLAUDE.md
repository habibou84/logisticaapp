# Logistica (livraison) — consignes pour Claude

Application de livraison pour **une seule entreprise** (Laravel 12, Vue 3, PWA, Capacitor), reprise de Delivery App
(`habibou84/deliverynew`) sans la partie plateforme : ni super administrateur, ni abonnements, ni sous-domaines.
Elle est servie à `https://app.logisticadelivery.com` (Docker derrière Apache, voir `docs/DEPLOIEMENT.md`).
Aucun contrat avec Jibiat Marchand (`habibou84/ecommerce`) : les évolutions ne tiennent pas compte de cette application.
Le code commun (argent, téléphones, webhooks) vient de la bibliothèque `habibou84/socle`.

## Langue
Toujours répondre en français. Textes de l'interface, messages, commits et PR en français.

## Une seule entreprise
- Pas de retour de la plateforme : pas de rôle `super_admin`, de formules (`feature:`), de `Tenancy`, de console.
- La colonne `company_id` et le filtrage par entreprise (`BelongsToCompany`) restent dans le schéma : ne pas les retirer.
- Liens envoyés (WhatsApp, SMS, API) : `Company::url()`, c'est-à-dire `APP_URL`.
- Première mise en service : `php artisan app:install` (entreprise + premier administrateur).

## Déploiement
- `docker-compose.yml` + `Dockerfile` (images `app` et `web`) ; `deploy/install.sh`, `deploy/update.sh`, `deploy/backup.sh`.
- Les conteneurs n'écoutent que sur `127.0.0.1` ; Apache du serveur fait le HTTPS (`deploy/apache-site.conf`).
- Le serveur héberge d'autres sites (PHP 7.4, MySQL) : ne rien installer directement sur le système.
- On ouvre une PR seulement à la demande.

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
