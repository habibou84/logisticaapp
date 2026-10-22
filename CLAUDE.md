# Jibiat (livraison) — consignes pour Claude

Jibiat est un SaaS de livraison multi-entreprise (Laravel 12, Vue 3, PWA, Capacitor). L'application e-commerçant
**Jibiat Marchand** (`habibou84/ecommerce`) s'y connecte par l'API publique `/api/public/v1` et ses webhooks.
Le code commun est dans `habibou84/socle`.

## Langue
Toujours répondre en français. Textes de l'interface, messages, commits et PR en français.

## Branches et déploiement
- `main` = préproduction, `production` = production. Le déploiement est automatique à la fusion (migrations comprises).
- On ouvre une PR seulement à la demande (« ouvre la PR et fusionne », « mets en production »).
- Mise en production : PR `main` → `production` n'est **pas** possible telle quelle (voir ci-dessous). On part de
  `origin/production`, on reprend le merge voulu (`git cherry-pick -m 1 --no-commit <merge>`), on vérifie qu'aucun
  fichier réservé à la préproduction n'est touché, on lance les tests, puis PR vers `production`, fusion quand c'est vert.
  Ensuite la branche de travail revient sur `main`.

## Réservé à la préproduction — ne JAMAIS mettre en production
- **PR #87** : affichage des comptes de démonstration avec leurs mots de passe. Fichiers :
  `app/Http/Controllers/Api/V1/DemoAccountController.php`, `app/Http/Middleware/PreprodGate.php`,
  `docs/PREPRODUCTION.md`, `resources/js/components/auth/LoginForm.vue` (partie démo), routes `demo-accounts` de `routes/api.php`,
  `tests/Feature/Api/DemoAccountsTest.php`.
- **PR #100** : exemption `api/public/*` dans `PreprodGate` (et son test dans `PreprodGateTest`).

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
- Une fonctionnalité qui touche les deux applications : socle d'abord, puis Jibiat, puis Jibiat Marchand.
