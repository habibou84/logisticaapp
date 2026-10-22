# Déployer sur un VPS LWS

Ce guide met l'application en ligne pour la tester avec de vrais téléphones : back-office,
applications livreur et marchand (PWA), temps réel, notifications push.

- **Serveur avec ISPConfig** (VPS LWS livré avec Debian 13 « Trixie » + ISPConfig 3) : partie A,
  script `deploy/ispconfig.sh`. ISPConfig garde la main sur Apache, PHP, le certificat HTTPS et le
  pare-feu ; le script ajoute le reste sans y toucher.
- **VPS nu** (Ubuntu 22.04/24.04 ou Debian 12, sans panneau) : partie B, script `deploy/install.sh`.

Dans les deux cas : PostgreSQL, Redis, Horizon (files d'attente), Reverb (temps réel), tâches planifiées.

Ensuite, pour servir plusieurs entreprises (une adresse chacune sur `jibiat.com`) : [PLATEFORME.md](PLATEFORME.md).

## Avant de commencer

| Il faut | Pourquoi |
|---|---|
| L'accès **root en SSH** au VPS (identifiants envoyés par LWS) | Les scripts installent des paquets système |
| 2 Go de RAM minimum (4 Go conseillés) | Compilation de l'interface, PostgreSQL |
| Un **sous-domaine**, par ex. `test.mondomaine.ci`, avec un enregistrement **A** vers l'adresse IP du VPS | HTTPS obligatoire pour la géolocalisation, l'installation des applications sur téléphone et les notifications push |
| L'accès au dépôt GitHub `habibou84/deliverynew` | Le serveur télécharge le code depuis GitHub |

L'enregistrement A se crée dans l'espace client LWS : **Domaines > Zone DNS**, nom `test`, valeur
= adresse IP du VPS. Vérification depuis votre ordinateur : `ping test.mondomaine.ci`.

---

## A. Serveur avec ISPConfig (Debian 13)

### A1. Créer le site dans ISPConfig

Panneau ISPConfig (`https://ADRESSE_IP:8080`) > **Sites > Site web > Ajouter un site web** :

- **Domaine** : `test.mondomaine.ci` (décochez « Auto-sous-domaine www » si vous n'avez pas créé `www.test…`) ;
- **PHP** : `PHP-FPM` ;
- **SSL** et **Let's Encrypt SSL** : cochés (le domaine doit déjà pointer vers le VPS) ;
- enregistrez, attendez une minute qu'ISPConfig crée le site.

### A2. Donner au site l'accès au dépôt

Connectez-vous au VPS (`ssh root@ADRESSE_IP`) puis :

```bash
apt-get update && apt-get install -y git
DOMAIN=test.mondomaine.ci
SITE=$(readlink -f /var/www/$DOMAIN); U=$(stat -c %U $SITE/web)
sudo -u $U mkdir -p $SITE/private/.ssh && chmod 700 $SITE/private/.ssh
sudo -u $U ssh-keygen -t ed25519 -N "" -C deploy-livraison -f $SITE/private/.ssh/id_ed25519
cat $SITE/private/.ssh/id_ed25519.pub
```

Copiez la ligne affichée (`ssh-ed25519 …`) dans GitHub : **dépôt `deliverynew` > Settings >
Deploy keys > Add deploy key**, sans cocher « Allow write access ». Puis téléchargez le code :

```bash
sudo -u $U -H env HOME=$SITE/private \
  GIT_SSH_COMMAND="ssh -i $SITE/private/.ssh/id_ed25519 -o UserKnownHostsFile=$SITE/private/.ssh/known_hosts -o StrictHostKeyChecking=accept-new" \
  git clone -b main git@github.com:habibou84/deliverynew.git $SITE/web/livraison
```

### A3. Lancer l'installation

```bash
cd $SITE/web/livraison
DOMAIN=$DOMAIN EMAIL=vous@mondomaine.ci bash deploy/ispconfig.sh
```

Comptez 10 à 15 minutes. Le script installe PostgreSQL, Redis, Supervisor, Node.js, Composer et les
extensions PHP manquantes, crée la base, le `.env` de production et les clés, compile l'interface,
lance Horizon et Reverb (port local 6001 : 8080 et 8081 sont pris par ISPConfig) et la tâche
planifiée. Ajoutez `DEMO=0` devant `bash` pour une base vide, `BRANCH=…` pour une autre branche.

### A4. Coller les directives Apache dans ISPConfig

À la fin, le script affiche un bloc à coller dans **Sites > test.mondomaine.ci > Options > Apache
Directives** (racine du site sur `livraison/public`, en-tête d'authentification transmis à PHP,
WebSocket du temps réel). Le voici :

```apache
DocumentRoot {DOCROOT}/livraison/public
<Directory {DOCROOT}/livraison/public>
    Options +FollowSymLinks -Indexes
    AllowOverride All
    Require all granted
    CGIPassAuth On
</Directory>
ProxyPass /app/ ws://127.0.0.1:6001/app/
ProxyPassReverse /app/ ws://127.0.0.1:6001/app/
```

Enregistrez, attendez une minute (ISPConfig régénère Apache), puis ouvrez `https://test.mondomaine.ci`.

> Le champ « PHP open_basedir » du même onglet doit contenir `{DOCROOT}` (valeur par défaut).
> Si ISPConfig utilise Nginx au lieu d'Apache sur votre serveur, le script s'arrête et le signale.

### A5. Mettre à jour

```bash
bash $SITE/web/livraison/deploy/update.sh
```

---

## B. VPS nu (sans ISPConfig)

```bash
ssh root@ADRESSE_IP
apt-get update && apt-get install -y git
mkdir -p /var/www/.ssh /var/www/livraison
chown -R www-data:www-data /var/www/.ssh /var/www/livraison && chmod 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N "" -C deploy-livraison -f /var/www/.ssh/id_ed25519
cat /var/www/.ssh/id_ed25519.pub          # à ajouter dans GitHub > Deploy keys (lecture seule)
sudo -u www-data -H env GIT_SSH_COMMAND="ssh -i /var/www/.ssh/id_ed25519 -o UserKnownHostsFile=/var/www/.ssh/known_hosts -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:habibou84/deliverynew.git /var/www/livraison
cd /var/www/livraison
DOMAIN=test.mondomaine.ci EMAIL=vous@mondomaine.ci bash deploy/install.sh
```

Le script installe aussi Nginx, PHP 8.3, le pare-feu et le certificat HTTPS (Certbot). Mise à jour :
`bash /var/www/livraison/deploy/update.sh`.

---

## Tester

| Qui | Adresse | Compte de démonstration (mot de passe `password`) |
|---|---|---|
| Back-office | `https://test.mondomaine.ci/admin` | 07 00 00 00 01 (administrateur), 02 (dispatcher), 03 (caissier) |
| Livreur | `https://test.mondomaine.ci/livreur` | 07 00 00 00 04 ou 05 |
| E-commerçant | `https://test.mondomaine.ci` (page d'accueil) | 05 00 00 00 01 |

Sur le téléphone, ouvrez l'adresse dans **Chrome** (Android) ou **Safari** (iPhone), connectez-vous,
puis **Ajouter à l'écran d'accueil**. Le livreur active ensuite les notifications depuis le bandeau de
ses missions (iPhone : iOS 16.4 minimum, application installée).

> Le site est accessible à tous : changez les mots de passe des comptes de démonstration (menu
> Utilisateurs), ou installez avec `DEMO=0` puis créez le premier compte :
> `cd <dossier de l'application> && sudo -u <utilisateur du site> php artisan app:create-super-admin`.

Les scripts peuvent être **relancés sans risque** : base, `.env` et clés sont conservés.

## Facultatif

- **WhatsApp réel** : dans le `.env`, `WHATSAPP_DRIVER=meta` et `WHATSAPP_APP_SECRET`, puis dans Meta
  le webhook `https://test.mondomaine.ci/api/webhooks/whatsapp` avec le jeton `WHATSAPP_VERIFY_TOKEN`
  du `.env` (généré à l'installation). Le numéro se saisit dans **Paramètres > WhatsApp**.
- **Inscription en ligne des e-commerçants** (page d'accueil, « Créer mon compte ») : le numéro se vérifie par
  WhatsApp dès que le WhatsApp réel ci-dessus est branché (webhook compris). Pour le SMS de secours :
  `SMS_DRIVER=twilio`, `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`. Sans l'un ni l'autre, le code s'affiche sur la
  page (mode démonstration) : fermez les inscriptions dans **Paramètres > E-commerçants** si le site est public.
- **Commandes WhatsApp comprises par Claude** : `ANTHROPIC_API_KEY=…` dans le `.env`.
- Après une modification du `.env` : relancez `deploy/update.sh` (il recompile l'interface et vide les caches).
- **Sauvegarde quotidienne de la base** :
  ```bash
  mkdir -p /var/backups/livraison
  echo '30 2 * * * postgres pg_dump livraison | gzip > /var/backups/livraison/$(date +\%F).sql.gz && find /var/backups/livraison -mtime +14 -delete' > /etc/cron.d/livraison-backup
  ```

## En cas de problème

| Symptôme | À vérifier |
|---|---|
| Page blanche ou erreur 500 | `tail -50 <application>/storage/logs/laravel-*.log` |
| Page par défaut d'ISPConfig au lieu de l'application | Directives Apache (A4) pas encore appliquées : attendez une minute, vérifiez **Outils > Journal** dans ISPConfig |
| Pas de temps réel (pas de son, carte figée) | `supervisorctl status` (livraison-reverb `RUNNING`), `tail <application>/storage/logs/reverb.log`, directives `ProxyPass` présentes |
| Notifications ou WhatsApp qui ne partent pas | `supervisorctl status` (livraison-horizon), `tail <application>/storage/logs/horizon.log` |
| Relances et alertes automatiques absentes | `cat /etc/cron.d/livraison` |
| Erreur 401 sur toutes les pages après connexion | `CGIPassAuth On` manquant dans les directives Apache |
| Pas de proposition d'installation, icône cassée | `https://<domaine>/app-icons/marchand-192.png` doit afficher l'icône ; sinon relancer `deploy/update.sh` |

Architecture installée (ISPConfig) :

```
Internet ──HTTPS──▶ Apache (ISPConfig) ──▶ PHP-FPM du site ──▶ Laravel ──▶ PostgreSQL
                     │                                          │
                     └─ /app/ (WebSocket) ──▶ Reverb (127.0.0.1:6001)
                                                                │
                     Redis ◀── files d'attente ─────────────────┴─ Horizon (Supervisor)
                     cron : schedule:run chaque minute (relances, alertes, rapports)
```
