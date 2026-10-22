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

En SSH, en root :

```bash
apt-get update && apt-get install -y git
git clone -b main https://github.com/habibou84/logisticaapp.git /opt/logistica
cd /opt/logistica
```

Dépôt privé : créez d'abord une clé de déploiement (`ssh-keygen -t ed25519 -f /root/.ssh/logistica -N ""`),
ajoutez le contenu de `/root/.ssh/logistica.pub` dans GitHub › dépôt › **Settings › Deploy keys** (sans droit
d'écriture), puis :

```bash
GIT_SSH_COMMAND="ssh -i /root/.ssh/logistica" git clone -b main git@github.com:habibou84/logisticaapp.git /opt/logistica
git -C /opt/logistica config core.sshCommand "ssh -i /root/.ssh/logistica"
```

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

```bash
bash /opt/logistica/deploy/update.sh
```

Récupère la dernière version de la branche suivie, construit les nouvelles images pendant que le site reste
en ligne, puis redémarre les conteneurs (quelques secondes ; migrations comprises).

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
| Arrêter / redémarrer | `docker compose stop` / `docker compose up -d` |

Les variables `VITE_*` (temps réel vu du navigateur) sont compilées dans l'interface : après les avoir changées,
lancez `bash deploy/update.sh`.

**Restauration d'une sauvegarde** (remplace la base actuelle) :

```bash
gunzip -c /var/backups/logistica/<fichier>.sql.gz | docker compose exec -T postgres psql -U logistica -d logistica
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
(commune non reconnue, compte sans mot de passe). Relancer ne crée pas de doublon.

**Avec le bouton de l'ancienne application** : **Réglages › Reprise de l'ancienne application** explique la
marche à suivre (fichier à copier dans l'ancienne application, clé de reprise valable 7 jours).

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
| Pas de temps réel (alertes, carte) | `docker compose logs reverb` ; module `proxy_wstunnel` actif (`apache2ctl -M`) |
| Messages ou notifications en retard | `docker compose logs horizon` |
| Certificat expiré | `certbot renew --dry-run` (le port 80 doit être libre, voir section 3) |
