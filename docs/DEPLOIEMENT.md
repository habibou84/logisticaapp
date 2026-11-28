# Mise en ligne sur app.logisticadelivery.com

L'application tourne dans des **conteneurs Docker** sur le serveur qui héberge déjà les sites
`logisticadelivery.com` (Apache, PHP 7.4, MySQL, Grafana, PM2). Rien de l'existant n'est modifié :

```
Internet ──► Apache (443, HTTPS) ──┬──► sites actuels (PHP 7.4)                       inchangés
                                   └──► app.logisticadelivery.com ──► 127.0.0.1:8090 ──► conteneurs :
                                          • web (Nginx)   • app (PHP 8.3)   • horizon (files d'attente)
                                          • reverb (temps réel)   • scheduler (tâches planifiées)
                                          • postgres (base)   • redis (cache, files)
```

- Les conteneurs n'écoutent que sur `127.0.0.1` : ils ne sont pas joignables depuis Internet (Docker
  contourne le pare-feu UFW pour les ports publiés, d'où cette précaution).
- La base PostgreSQL et Redis sont propres à l'application (volumes Docker), à côté du MySQL actuel.
- Apache garde le HTTPS, avec un certificat Let's Encrypt pour `app.logisticadelivery.com`.

Comptez 20 à 30 minutes, dont la construction des images.

---

## 1. DNS chez LWS

Espace client LWS › **Domaines** › `logisticadelivery.com` › **Zone DNS** : un enregistrement **A**,
nom `app`, valeur `45.147.98.44` (l'adresse du serveur). Les autres enregistrements ne changent pas.

Vérification depuis votre ordinateur : `ping app.logisticadelivery.com` doit répondre `45.147.98.44`
(quelques minutes à quelques heures après la création).

## 2. Récupérer le code sur le serveur

En SSH, en root (le dépôt et le paquet commun `habibou84/socle` sont publics : pas de clé à créer) :

```bash
apt-get update && apt-get install -y git
git clone -b production https://github.com/habibou84/logisticaapp.git /opt/logistica
cd /opt/logistica
```

La branche clonée est celle que suivent les mises à jour (`deploy/update.sh`) : **`production`** (voir section 4).

Si le dépôt devient privé : créez une clé de déploiement (`ssh-keygen -t ed25519 -f /root/.ssh/logistica -N ""`),
ajoutez `/root/.ssh/logistica.pub` dans GitHub › dépôt › **Settings › Deploy keys** (sans droit d'écriture), puis
`git -C /opt/logistica remote set-url origin git@github.com:habibou84/logisticaapp.git` et
`git -C /opt/logistica config core.sshCommand "ssh -i /root/.ssh/logistica"`.

## 3. Installation

```bash
cd /opt/logistica
DOMAIN=app.logisticadelivery.com EMAIL=votre-adresse@exemple.com bash deploy/install.sh
```

Le script :

1. crée le fichier `.env` de production (mots de passe de la base, clés du temps réel et des notifications
   générés une seule fois) ;
2. construit les images et démarre les conteneurs ; les migrations s'appliquent au démarrage ;
3. demande le **nom de l'entreprise** et le **premier administrateur** (nom, téléphone, mot de passe) ;
4. active les modules Apache `proxy`, `proxy_http`, `proxy_wstunnel` et `headers` (sans effet sur les
   autres sites), obtient le certificat HTTPS et ajoute le site `app.logisticadelivery.com` ;
5. programme une sauvegarde de la base chaque nuit (`/var/backups/logistica`, 14 jours).

Il peut être relancé sans risque : chaque étape déjà faite est sautée.

**Certificat** : le port 80 du serveur n'est servi par personne (Apache écoute sur 99, 101 et 443). Certbot
l'ouvre donc lui-même quelques secondes (méthode `standalone`), à l'installation puis à chaque
renouvellement. Si un jour Apache écoute de nouveau sur le port 80, renouvelez avec la méthode Apache :
`certbot certonly --apache -d app.logisticadelivery.com --force-renewal`.

Ouvrez ensuite `https://app.logisticadelivery.com/admin` et connectez-vous avec le téléphone et le mot de
passe de l'administrateur. La **mise en route** guide les réglages (zones, tarifs, livreurs, paie…).

## 4. Mises à jour

### Le chemin d'une modification

```
branche de travail ──► PR vers main ──► contrôles GitHub ──► fusion (squash)
    ──► PRÉPRODUCTION (preprod.logisticadelivery.com) : installée toute seule, on essaie
    ──► PR main → production ──► fusion (commit de fusion)
    ──► PRODUCTION : installée dans la plage horaire (sauvegarde, bascule, contrôle du site)
```

- **Fusionner sur `main` = mettre en préproduction. Fusionner `main` dans `production` = mettre en production.**
- On ne pousse jamais directement sur `main` ni sur `production` : tout passe par une PR, aux contrôles verts.
- Les deux fusions se font sur demande du propriétaire, par lui ou par Claude (« ouvre la PR et fusionne »,
  « mets en production »).
- Avant de mettre en production : la modification essayée en préproduction (au moins les parcours touchés :
  saisie, attribution, livraison, caisse…).

**Réglages GitHub à faire une fois** (dépôt › *Settings*) :

1. *General › Default branch* : `main`.
2. *General › Pull Requests* : cocher « Allow squash merging » (PR des modifications vers `main` : un commit par
   modification) **et** « Allow merge commits » (PR `main` → `production` : la production reçoit exactement les
   commits de `main`).
3. *Rules › Rulesets › New branch ruleset*, cible `main` **et** `production` : « Restrict deletions »,
   « Require a pull request before merging », « Require status checks to pass » (cocher *Style (Pint)*,
   *Tests (PostgreSQL, PHP 8.3)*, *Tests (PostgreSQL, PHP 8.4)*, *Build front-end*, *Téléphone (largeur des pages)*),
   « Block force pushes ».
4. Branche `production` : créée une fois à partir de `main` (*Branches › New branch*).

### Mettre en production

GitHub › dépôt › **Pull requests › New pull request** : *base* `production` ← *compare* `main`, puis
**Create pull request**. La liste des modifications depuis la dernière mise en production s'affiche (onglet
*Commits*). Quand les contrôles sont verts : **Merge pull request** (« Create a merge commit », pas « Squash »).

La production l'installe au début de la plage horaire (`AUTO_UPDATE_WINDOW`, 20 h à 7 h), une fois les contrôles
GitHub du commit de fusion passés. L'historique des mises en production est la liste des PR `main → production`.

**Mise en production immédiate** (correction urgente) : ajoutez **`[immédiat]`** au titre de la fusion (champ
*Commit message* au moment de cliquer sur *Merge*, ou titre de la PR avant la fusion). La production l'installe
dès que les contrôles du commit de fusion sont verts (environ 10 minutes), même en pleine journée. Autre moyen,
sur le serveur : `bash deploy/auto-update.sh --now`.

### Mise à jour automatique

Le serveur vérifie toutes les 5 minutes s'il y a une nouvelle version sur la branche qu'il suit. Il l'installe
**seulement si les contrôles GitHub sont passés** sur cette version (onglet *Actions*) ; sinon il attend, ou
l'écarte si un contrôle a échoué. Rien n'est ouvert sur le serveur : c'est lui qui va chercher le code. Une
installation qui échoue est réessayée deux fois, puis abandonnée (la version en service reste) jusqu'à `--now`.

| | Production (`/opt/logistica`) | Préproduction (`/opt/logistica-preprod`) |
|---|---|---|
| Branche suivie (celle du dossier) | `production` | `main` |
| `AUTO_UPDATE_TRACK` (`.env`) | `branch` | `branch` (défaut) |
| `AUTO_UPDATE_WINDOW` (`.env`) | `20:00-07:00` : en dehors des tournées | vide : à toute heure |

```bash
bash deploy/auto-update.sh --install   # activer
bash deploy/auto-update.sh --status    # suivi, version en service, dernière version, contrôles, plage, journal
bash deploy/auto-update.sh --now       # installer tout de suite la dernière version validée (même hors plage)
touch .no-auto-update                  # pause (intervention en cours) ; rm pour reprendre
bash deploy/auto-update.sh --remove    # désactiver
```

Journal : `/var/log/<projet>-update.log` (`logistica`, `logistica-preprod`).

**Passer la production sur la branche `production`** (une fois, la branche créée sur GitHub) :

```bash
cd /opt/logistica
git fetch origin production
git checkout -B production origin/production
sed -i '/^AUTO_UPDATE_TRACK=/d' .env && echo 'AUTO_UPDATE_TRACK=branch' >> .env
grep -q '^AUTO_UPDATE_WINDOW=' .env || echo 'AUTO_UPDATE_WINDOW=20:00-07:00' >> .env
bash deploy/auto-update.sh --status     # « Suivi : branche production »
```

Variante : `AUTO_UPDATE_TRACK=tags` fait suivre les versions publiées `v1.2.3` (*Releases* GitHub) au lieu d'une
branche.

### À la main

```bash
bash deploy/update.sh
```

Étapes : récupère la dernière version de `main`, construit les nouvelles images pendant que le site reste en
ligne, **sauvegarde la base** (`/var/backups/<projet>/…_avant-<version>.sql.gz`, les 10 dernières gardées),
redémarre les conteneurs (quelques secondes ; migrations comprises), puis **vérifie que le site répond**
(`/up` contrôle aussi la base et Redis). Si une étape échoue, elle s'arrête et le dit. Les images sont nommées
d'après la version ; les 3 précédentes restent sur le serveur pour le retour arrière.

### Revenir en arrière

**En quelques secondes, sans rien reconstruire** :

```bash
cd /opt/logistica
bash deploy/rollback.sh             # versions disponibles sur le serveur (▶ = en service)
bash deploy/rollback.sh 7d29741     # remet cette version en service, contrôle du site compris
```

La version retirée n'est pas réinstallée par la mise à jour automatique : le serveur attend la suivante.
Ensuite, corrigez : PR (ou **Revert** sur la PR fautive), fusion, essai en préproduction, nouvelle mise en
production (PR `main → production`).

La base garde les migrations de la version retirée ; c'est sans conséquence quand elles ne font qu'ajouter
(règle suivie dans ce projet). Si une migration a abîmé des données, restaurez la sauvegarde d'avant la mise à
jour (`…_avant-<version-retirée>.sql.gz`, commande en section 5) : **les saisies faites depuis sont perdues**.

### Préproduction

Une copie complète de l'application, sur le même serveur, à `https://preprod.logisticadelivery.com` : même code,
mais **base, fichiers et conteneurs séparés** de la production. Elle sert à essayer chaque modification avant
les clients.

- **Fermée au public** : un code d'accès est demandé avant toute page (mémorisé 30 jours), jamais indexée.
- **Rien n'est envoyé** : WhatsApp, SMS et e-mails sont simulés (visibles dans le journal de l'application) ; ne
  connectez pas le WhatsApp de l'entreprise dans ses réglages.
- **Erreurs** : avec le même `SENTRY_LARAVEL_DSN` que la production, elles arrivent dans Sentry sous
  l'environnement *staging*, à part de la production.
- **Ressources** : environ 0,5 à 1 Go de mémoire en plus. Vérifiez avant : `free -h` (colonne *available*).

**Mise en place** (une fois, 20 à 30 minutes) :

1. DNS chez LWS : un enregistrement **A**, nom `preprod`, valeur `45.147.98.44` (comme pour `app`, section 1).
2. Sur le serveur, en root :

   ```bash
   git clone -b main https://github.com/habibou84/logisticaapp.git /opt/logistica-preprod
   cd /opt/logistica-preprod
   INSTANCE=preprod DOMAIN=preprod.logisticadelivery.com APP_PORT=8091 COMPOSE_PROJECT_NAME=logistica-preprod \
     EMAIL=votre-adresse@exemple.com bash deploy/install.sh
   bash deploy/auto-update.sh --install
   ```

   Le script crée une entreprise et un administrateur de test, puis affiche **le code d'accès** (aussi dans
   `.env` : `PREPROD_ACCESS_CODE`).
3. Optionnel : `SENTRY_LARAVEL_DSN` (le même que la production) dans `/opt/logistica-preprod/.env`, puis
   `docker compose up -d --force-recreate --no-deps app horizon reverb scheduler`.

Les deux installations sont indépendantes : chaque commande se lance **dans son dossier** (`/opt/logistica` pour la
production, `/opt/logistica-preprod` pour la préproduction).

## Sauvegardes

- **Chaque nuit** (2 h 30) : `/var/backups/logistica/AAAA-MM-JJ_HHMM.sql.gz`, gardées 14 jours. Journal :
  `/var/log/logistica-backup.log`.
- **Avant chaque mise à jour** : `…_avant-<version>.sql.gz`, les 10 dernières.
- **Hors du serveur** (à faire : sans copie ailleurs, une panne du serveur emporte la base, les fichiers et leurs
  sauvegardes) :

  ```bash
  cd /opt/logistica
  cp deploy/offsite.env.example .env.offsite && chmod 600 .env.offsite
  nano .env.offsite                    # stockage (Cloudflare R2, Backblaze B2, S3…), clé, mot de passe de chiffrement
  bash deploy/backup.sh --verifier     # format du fichier et connexion, sans rien envoyer
  bash deploy/backup.sh                # essai : « base copiée hors du serveur » puis « fichiers copiés hors du serveur »
  ```

  `--verifier` signale en clair les erreurs de saisie courantes : `https:` ou `stockage:` en double, clé de mauvaise
  longueur, espaces, guillemets, valeur d'exemple oubliée, fins de ligne Windows. Les mêmes contrôles sont faits avant
  chaque copie de la nuit. Une copie ne peut pas rester bloquée : pas de réponse en 30 secondes ou plus de 5 minutes sans
  échange, elle s'arrête en erreur (et Healthchecks.io prévient).

  Chaque nuit, tout est **chiffré** avant l'envoi :

  | Quoi | Où (dans le stockage) | Gardé |
  |---|---|---|
  | Base, chaque nuit | `jour/` | 14 jours |
  | Base, le dimanche | `semaine/` | 12 semaines |
  | Base, le 1er du mois | `mois/` | 12 mois |
  | Fichiers (photos, pièces d'identité, documents, logos) | `fichiers/actuel/` | miroir du serveur |
  | Fichiers supprimés ou remplacés sur le serveur | `fichiers/supprimes/<date>/` | 30 jours |

  La première copie des fichiers envoie tout (elle peut prendre du temps) ; ensuite, seuls les nouveaux partent.
  Notez la phrase de chiffrement dans un gestionnaire de mots de passe : sans elle, les copies sont illisibles.
  Durées réglables dans `.env.offsite`.

  Voir ce qui est stocké (noms en clair, grâce à la phrase de chiffrement) :

  ```bash
  docker run --rm --env-file .env.offsite rclone/rclone:1.68.2 lsl chiffre:jour
  docker run --rm --env-file .env.offsite rclone/rclone:1.68.2 size chiffre:fichiers/actuel
  ```

- **Restaurer la base** : section 5 (fichier local) ; depuis le stockage, récupérez d'abord le fichier :
  `docker run --rm --env-file .env.offsite -v /root:/out rclone/rclone:1.68.2 copy chiffre:jour/<fichier> /out`.
- **Restaurer les fichiers** (serveur neuf ou fichiers perdus ; ne remplace que ce qui manque ou diffère) :

  ```bash
  cd /opt/logistica
  docker run --rm --env-file .env.offsite --volumes-from "$(docker compose ps -q app)" rclone/rclone:1.68.2 \
      copy chiffre:fichiers/actuel /var/www/html/storage/app
  docker compose exec app chown -R www-data:www-data storage/app
  ```

  Un fichier supprimé par erreur ces 30 derniers jours : `… lsf -R chiffre:fichiers/supprimes`, puis `copyto` du
  fichier vers son dossier.
- **Essayer** de temps en temps de restaurer sur une base de test : une sauvegarde qu'on n'a jamais restaurée n'est
  pas une sauvegarde sûre.

## Surveillance

Pour être prévenu avant les utilisateurs. Les trois services ont une formule gratuite suffisante.

| Quoi | Service | Réglage |
|---|---|---|
| Le site est-il joignable ? | [UptimeRobot](https://uptimerobot.com) (ou Better Stack) | Moniteur HTTP(S) sur `https://app.logisticadelivery.com/up`, toutes les 5 minutes, alerte par e-mail ou SMS. `/up` répond une erreur si la base ou Redis ne répondent pas. |
| Erreurs de l'application | [Sentry](https://sentry.io) | Projet *Laravel* ; copiez son DSN dans `.env` : `SENTRY_LARAVEL_DSN=https://…`, puis `docker compose up -d --force-recreate`. Chaque erreur (pages, files d'attente, tâches planifiées) arrive par e-mail avec la version du code (`APP_VERSION`). Ni utilisateur, ni adresse IP, ni contenu des formulaires n'est envoyé. |
| La sauvegarde de la nuit a-t-elle eu lieu ? | [Healthchecks.io](https://healthchecks.io) | Un contrôle « tous les jours », 1 h de tolérance ; copiez son adresse dans `.env` : `BACKUP_PING_URL=https://hc-ping.com/…`. Il prévient si la sauvegarde échoue ou n'arrive pas. |

Essai de Sentry : `docker compose exec -u www-data app php artisan sentry:test`.

## 5. Commandes utiles

Depuis `/opt/logistica` :

| Besoin | Commande |
|---|---|
| État des conteneurs | `docker compose ps` |
| Journaux (site, files, temps réel) | `docker compose logs -f app horizon reverb` |
| Journal de l'application | `docker compose exec app tail -n 100 storage/logs/laravel-$(date +%F).log` |
| Commande Laravel | `docker compose exec -u www-data app php artisan …` |
| Changer un mot de passe | `docker compose exec -u www-data app php artisan user:password 0700000000` |
| Prendre en compte un changement du `.env` | `docker compose up -d --force-recreate` |
| Sauvegarde immédiate | `bash deploy/backup.sh` |
| Versions sur le serveur / retour arrière | `bash deploy/rollback.sh` / `bash deploy/rollback.sh <version>` |
| État de la mise à jour automatique | `bash deploy/auto-update.sh --status` |
| Données fictives (préproduction seulement, depuis `/opt/logistica-preprod`) | `docker compose exec -u www-data app php artisan app:preprod-data` |
| Arrêter / redémarrer | `docker compose stop` / `docker compose up -d` |

**Données fictives de la préproduction** (`app:preprod-data`, refusée si `APP_ENV=production`) : 10 clients,
8 livreurs, un dispatcher, un caissier, un agent d'entrepôt et 30 jours de courses (≈ 18 par jour) passées par les
vrais services : prix, journal, encaissements, versements des livreurs chaque soir, reversements aux clients le
vendredi. Les courses du jour restent à différentes étapes (à valider, en ramassage, en chemin…). Aucun message ne
part pendant la génération (WhatsApp, SMS, notifications, webhooks). Comptes en `07 99 …`, mot de passe
`Preprod2026` (option `--password`). Relançable : les jours déjà remplis sont sautés, donc la relancer le matin
ajoute seulement les courses du jour. Options : `--days=30`, `--per-day=18`, `--seed=` (mêmes données).
Les clients fictifs ont leur propre grille de tarifs (« Clients fictifs (préproduction) ») : les tarifs réglés de
l'entreprise ne sont pas touchés. Si des courses échouent, la commande affiche les erreurs les plus fréquentes.

Les variables `VITE_*` (temps réel vu du navigateur) sont compilées dans l'interface : après les avoir changées,
lancez `bash deploy/update.sh`.

**Restauration d'une sauvegarde** (remplace la base actuelle) :

```bash
gunzip -c /var/backups/logistica/<fichier>.sql.gz | docker compose exec -T postgres psql -U logistica -d logistica
```

### Remise à zéro de l'activité (avant la mise en service réelle)

Efface **toutes les courses** et ce qui en découle : argent (encaissements, caisse, gains, paies et avances des
livreurs, comptes, reversements et factures des clients), messages et suivi terrain (photos comprises), mouvements
de stock (quantités remises à zéro), statistiques. **Restent** : l'entreprise et ses réglages, les utilisateurs, les
clients, les livreurs, les zones et tarifs, les véhicules, les produits, l'annuaire. Irréversible, sauf restauration
de la sauvegarde.

```bash
cd /opt/logistica
bash deploy/backup.sh avant-remise-a-zero                                    # sauvegarde d'abord
docker compose exec -u www-data app php artisan app:reset-activity           # simulation : ce qui serait effacé
docker compose exec -u www-data app php artisan app:reset-activity --execute # efface (taper EFFACER)
```

## 6. Reprise des données de l'ancienne application

L'ancienne application (`admin.`, `client.`, `livreur.logisticadelivery.com`, base MySQL du même serveur) reste
en ligne pendant la reprise. Sont repris : les **clients** et les **livreurs**, avec **le même numéro et le
même mot de passe**. Ne sont pas repris : les commandes et les comptes de l'équipe (à recréer dans
**Utilisateurs**). Aucun message n'est envoyé.

Avant tout : dans la nouvelle application, réglez les **zones** (la commune de chaque client choisit sa zone
de ramassage).

**Avec un export SQL** (le plus simple ici, tout est sur le même serveur) :

```bash
cd /opt/logistica
# Export des trois tables de l'ancienne base (nom de la base et utilisateur MySQL de l'ancienne application)
mysqldump --complete-insert -u <utilisateur> -p <ancienne_base> clients livreurs users > /root/ancienne-base.sql
docker compose cp /root/ancienne-base.sql app:/tmp/ancienne-base.sql

# Simulation (rien n'est enregistré), puis import
docker compose exec -u www-data app php artisan legacy:import /tmp/ancienne-base.sql --dry-run
docker compose exec -u www-data app php artisan legacy:import /tmp/ancienne-base.sql

docker compose exec app rm /tmp/ancienne-base.sql && rm /root/ancienne-base.sql
```

Le compte rendu liste les lignes non importées (numéro invalide ou déjà présent) et les points à vérifier
(commune non reconnue, compte sans mot de passe, photo introuvable). Relancer ne crée pas de doublon.
Un livreur inscrit plusieurs fois avec le même numéro : seule la fiche la plus récemment utilisée est
reprise (dernière position envoyée, sinon dernière mise à jour) ; les autres sont dans le compte rendu.

**Photos des livreurs et de leur pièce d'identité** : elles sont dans le dossier `storage/app/public` de
l'ancienne application (sous-dossiers `image` et `piece`). Copiez ces deux sous-dossiers dans le conteneur
et ajoutez `--files` (à faire avec l'import des livreurs : un livreur déjà importé n'est pas repris) :

```bash
# <ancienne_app> : dossier de l'ancienne application sur le serveur
docker compose exec app mkdir -p /tmp/anciens-fichiers
docker compose cp <ancienne_app>/storage/app/public/image app:/tmp/anciens-fichiers/image
docker compose cp <ancienne_app>/storage/app/public/piece app:/tmp/anciens-fichiers/piece
docker compose exec app chown -R www-data /tmp/anciens-fichiers

docker compose exec -u www-data app php artisan legacy:import /tmp/ancienne-base.sql --files=/tmp/anciens-fichiers --dry-run
docker compose exec -u www-data app php artisan legacy:import /tmp/ancienne-base.sql --files=/tmp/anciens-fichiers

docker compose exec app rm -rf /tmp/anciens-fichiers
```

Les photos sont copiées dans les fichiers privés de l'application : l'équipe les voit sur la fiche du
livreur (onglet **Informations**, carte **Identité**), où elle peut aussi les ajouter ou les changer.

**Avec le bouton de l'ancienne application** : **Réglages › Reprise de l'ancienne application** explique la
marche à suivre (fichier à copier dans l'ancienne application, clé de reprise valable 7 jours).

**Annuler la reprise des livreurs** (pour les recréer à la main) : les livreurs dont le numéro figure dans l'export
sont supprimés **définitivement** (fiche, zones, barème, planning, positions, véhicules attribués, photo et pièce
d'identité) ; leurs numéros redeviennent libres. Les autres comptes (équipe, clients, livreurs créés à la main avec
un autre numéro) ne sont jamais touchés. Un livreur qui a encore de l'activité bloque tout : faites d'abord la remise
à zéro de l'activité (section 5).

```bash
cd /opt/logistica
mysqldump --complete-insert -u <utilisateur> -p <ancienne_base> livreurs users > /root/ancienne-base.sql
docker compose cp /root/ancienne-base.sql app:/tmp/ancienne-base.sql
bash deploy/backup.sh avant-retrait-livreurs
docker compose exec -u www-data app php artisan legacy:remove-couriers /tmp/ancienne-base.sql            # simulation : liste
docker compose exec -u www-data app php artisan legacy:remove-couriers /tmp/ancienne-base.sql --execute  # taper SUPPRIMER
docker compose exec app rm /tmp/ancienne-base.sql && rm /root/ancienne-base.sql
```

## 7. WhatsApp

Deux possibilités, dans **Réglages › WhatsApp** :

- **Saisie manuelle** : identifiant du numéro, identifiant WABA et jeton permanent d'un utilisateur système
  (Meta Business › Utilisateurs système). C'est la voie la plus simple pour une seule entreprise.
- **Bouton « Connecter WhatsApp »** : demande votre propre application Meta (Facebook Login for Business,
  configuration « WhatsApp Embedded Signup »), avec `https://app.logisticadelivery.com` comme domaine
  autorisé, puis dans `.env` : `WHATSAPP_APP_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_SIGNUP_CONFIG_ID` et,
  pour un jeton sans expiration, `WHATSAPP_SYSTEM_USER_ID` et `WHATSAPP_SYSTEM_USER_TOKEN`.

Dans les deux cas, l'application Meta reçoit les messages à l'adresse
`https://app.logisticadelivery.com/api/webhooks/whatsapp`, avec le jeton de vérification `WHATSAPP_VERIFY_TOKEN`
du `.env` (généré à l'installation). Pages exigées par Meta : `/confidentialite`, `/conditions` et
`/confidentialite#suppression`.

## 8. Dépannage

| Symptôme | Vérification |
|---|---|
| Le site ne répond pas | `docker compose ps` (tous `running`, `app` et bases `healthy`) ; `curl -I http://127.0.0.1:8090/up` sur le serveur |
| Erreur 502/503 d'Apache | Conteneur `web` arrêté : `docker compose up -d` ; `tail /var/log/apache2/app.logisticadelivery.com-error.log` |
| Page blanche ou erreur 500 | Journal de l'application (section 5) |
| Conteneur `app` en erreur au démarrage | `docker compose logs --tail=50 app` ; `.env` lisible par l'application : `chown root:33 .env && chmod 640 .env` |
| Pas de temps réel (alertes, carte) | `docker compose logs reverb` ; module `proxy_wstunnel` actif (`apache2ctl -M`) |
| Messages ou notifications en retard | `docker compose logs horizon` |
| Certificat expiré | `certbot renew --dry-run` (le port 80 doit être libre, voir section 3) |
