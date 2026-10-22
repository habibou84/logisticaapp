# Mise en service de la plateforme jibiat.com

Ce guide fait passer le serveur actuel (une seule entreprise, installé avec `deploy/ispconfig.sh`)
en **plateforme multi-entreprises** :

| Adresse | Pour qui |
|---|---|
| `https://jibiat.com` | Le site de présentation : accueil, tarifs (servi par l'application, voir « Site de présentation ») |
| `https://admin.jibiat.com` | Console du super administrateur : entreprises, assistance |
| `https://express.jibiat.com` | Une entreprise : marchands sur `/`, équipe sur `/admin`, livreurs sur `/livreur` |

Une seule installation sert toutes les entreprises : un seul code, une seule base, une seule
mise à jour. Chaque nouvelle entreprise créée dans la console reçoit aussitôt son adresse, sans
rien toucher sur le serveur ni dans les DNS.

Comptez environ une heure, dont l'attente de la propagation DNS. L'application quitte son
ancienne adresse (ex. `test.jibiat.com`) à l'étape 4, quand chaque entreprise reçoit la sienne.

---

## Étape 1 — DNS chez Cloudflare

### 1.1 Le domaine dans Cloudflare (si ce n'est pas déjà fait)

1. Cloudflare > **Add a site** > `jibiat.com` > formule **Free**.
2. Cloudflare importe les enregistrements existants de LWS. **Gardez tout ce qui concerne la
   messagerie** (MX, `mail`, `autoconfig`, SPF/DKIM en TXT…) en mode **DNS only** (nuage gris).
3. Chez LWS (espace client > Domaines > `jibiat.com` > **Serveurs DNS**), remplacez les serveurs
   de noms par les deux indiqués par Cloudflare (`xxx.ns.cloudflare.com`).
4. Attendez que Cloudflare affiche le domaine **Active** (de quelques minutes à quelques heures).

### 1.2 Les enregistrements de la plateforme

Cloudflare > `jibiat.com` > **DNS** > **Records**. Supprimez les anciens enregistrements A/AAAA/CNAME
de `jibiat.com` et `www` qui pointent vers l'hébergement LWS, puis ajoutez :

| Type | Nom | Contenu | Proxy |
|---|---|---|---|
| A | `@` | adresse IP du serveur | Proxied (nuage orange) |
| A | `*` | adresse IP du serveur | Proxied (nuage orange) |

L'enregistrement `*` couvre `admin.jibiat.com` et toutes les entreprises. Un enregistrement
plus précis (ex. `mail`) reste prioritaire : la messagerie n'est pas touchée.

### 1.3 Réglages Cloudflare

- **SSL/TLS** > **Overview** : mode **Full (strict)**.
- **SSL/TLS** > **Edge Certificates** : **Always Use HTTPS** activé.
- **Network** : **WebSockets** activé (c'est le cas par défaut) — nécessaire au temps réel.

> Le mode proxy (nuage orange) masque l'adresse du serveur et le protège des attaques. Le
> serveur lit l'adresse réelle des visiteurs dans les en-têtes de Cloudflare (`TRUSTED_PROXIES`,
> réglé à l'étape 4).

---

## Étape 2 — Certificat d'origine Cloudflare

Le certificat « d'origine » chiffre la liaison Cloudflare ↔ serveur. Il couvre `jibiat.com` et
`*.jibiat.com`, vaut 15 ans et **ne demande aucun renouvellement**.

1. Cloudflare > **SSL/TLS** > **Origin Server** > **Create Certificate**.
2. « Generate private key and CSR with Cloudflare », type **RSA (2048)**.
3. Noms : `jibiat.com` et `*.jibiat.com` (proposés par défaut). Validité : **15 years**.
4. **Create**. Format **PEM**. Copiez dans deux fichiers texte sur votre ordinateur :
   - **Origin Certificate** → `jibiat-cert.pem`
   - **Private Key** → `jibiat-key.pem` — **elle n'est affichée qu'une seule fois.**

Gardez la clé privée pour vous : elle ne doit circuler ni par e-mail ni par messagerie.

---

## Étape 3 — Les sites dans ISPConfig

Deux sites web, chacun avec son rôle :

| Site ISPConfig | Adresses servies | Contenu |
|---|---|---|
| `jibiat.com` | `jibiat.com`, `www.jibiat.com` | Votre site de présentation (WordPress ou autre) |
| Le site de l'application, sous son nom actuel (ex. `test.jibiat.com`) | toutes les autres : `admin.jibiat.com`, `express.jibiat.com`… | L'application |

**Ne renommez pas le site de l'application** : il reçoit les sous-domaines par la directive
ci-dessous. (Apache lit les sites par ordre alphabétique de leur nom : `jibiat.com` passe avant
`test.jibiat.com`, donc `www.jibiat.com` reste au site de présentation.)

### 3.1 Le site de l'application

ISPConfig > **Sites** > **Website** > le site de l'application :

1. Onglet **Domain** :
   - **SSL** : coché ;
   - **Let's Encrypt SSL** : **décoché** (Let's Encrypt via ISPConfig ne sait pas couvrir
     `*.jibiat.com` ; le certificat Cloudflare le remplace).
2. **Save**.
3. Onglet **SSL** :
   - **SSL Key** : contenu de `jibiat-key.pem` ;
   - **SSL Certificate** : contenu de `jibiat-cert.pem` ;
   - **SSL Bundle** : vide ;
   - **SSL Action** : **Save Certificate**.
4. Onglet **Options**, champ **Apache Directives** : ajoutez en **première ligne**, sans toucher
   au reste :
   ```
   ServerAlias *.jibiat.com
   ```
5. **Save**.

### 3.2 Le site de présentation `jibiat.com`

Onglet **Domain** : **Auto-Subdomain** `www.`, **SSL** coché. Pour le certificat : Let's Encrypt
(case cochée), ou le même certificat d'origine que l'application, collé dans l'onglet **SSL**.

Attendez une minute qu'ISPConfig régénère Apache.

> Sans site de présentation, un seul site suffit : celui de l'application, renommé `jibiat.com`
> avec **Auto-Subdomain** `*.` (et sans la directive `ServerAlias`). La page `jibiat.com` est
> alors celle de la plateforme, qui invite les entreprises à vous contacter.

---

## Étape 4 — L'application

En SSH sur le serveur, en root. `<dossier>` est le dossier de l'application (affiché à
l'installation, ex. `/var/www/clients/client1/web1/web/livraison`) :

```bash
# Dernière version (contient ce script)
bash <dossier>/deploy/update.sh

# Passage en plateforme
PLATFORM_DOMAIN=jibiat.com bash <dossier>/deploy/platform.sh
```

Le script :

- vérifie que `jibiat.com`, `admin.jibiat.com` et une adresse d'entreprise au hasard répondent ;
- règle le `.env` : `PLATFORM_DOMAIN=jibiat.com`, `APP_URL=https://admin.jibiat.com`,
  `TRUSTED_PROXIES=cloudflare`, temps réel sur l'adresse ouverte ;
- recompile l'interface et redémarre les processus ;
- affiche les adresses de la console et des entreprises, et ce qu'il reste à corriger.

### Adresse de votre entreprise actuelle

Elle vient de son nom (ex. `livraison-express-ci.jibiat.com`). Pour la raccourcir :

```bash
cd <dossier>
sudo -u <utilisateur du site> php artisan platform:status --rename=livraison-express-ci:express
```

(ou plus tard, depuis la console : fiche de l'entreprise > Adresse). Lettres minuscules,
chiffres et tirets, 3 à 40 caractères ; `www`, `admin`, `api`… sont réservés.

### Compte super administrateur

`platform:status` indique s'il en existe un. Sinon :

```bash
cd <dossier>
sudo -u <utilisateur du site> php artisan app:create-super-admin
```

Ce compte ne se connecte **que** sur `https://admin.jibiat.com`.

### Mots de passe et comptes de démonstration

Un serveur installé avec les données de démonstration (`DEMO=1`, par défaut) contient des comptes
dont le mot de passe est `password`, dont un super administrateur (`07 00 00 00 00`). À faire
avant d'accueillir de vraies entreprises :

```bash
cd <dossier>
# Changer un mot de passe (demandé deux fois, jamais écrit dans l'historique) ; le compte est déconnecté
sudo -u <utilisateur du site> php artisan user:password 0700000000

# Lister les comptes qui ont encore le mot de passe « password », puis les suspendre
sudo -u <utilisateur du site> php artisan app:demo-accounts
sudo -u <utilisateur du site> php artisan app:demo-accounts --suspend
```

`--suspend` ne touche jamais aux super administrateurs (ce serait perdre l'accès à la console) :
changez leur mot de passe avec `user:password`. Un compte suspendu se réactive depuis le
back-office (Utilisateurs). Si un numéro a un compte dans plusieurs entreprises, `user:password`
demande laquelle : `--company=express`, ou `--company=console` pour un super administrateur.

---

## Étape 5 — Vérifications

1. `https://jibiat.com` et `https://www.jibiat.com` : votre site de présentation.
2. `https://admin.jibiat.com` : connexion du super administrateur, liste des entreprises.
3. `https://express.jibiat.com/admin` : connexion de l'équipe de l'entreprise.
4. `https://express.jibiat.com/livreur` et `https://express.jibiat.com` : livreurs et marchands.
5. Temps réel : ouvrez la carte des livreurs et, sur un autre appareil, faites avancer une
   course : la carte et le tableau de bord se mettent à jour sans recharger.
6. `https://inconnue.jibiat.com` : « Aucune entreprise à cette adresse ».

---

## Ce qui change pour les utilisateurs de l'entreprise actuelle

- **Nouvelle adresse** : l'ancienne ne fonctionne plus. Prévenez l'équipe, les livreurs et les
  marchands (un message WhatsApp avec le nouveau lien suffit).
- **Applications installées** (livreurs, marchands) : à réinstaller depuis la nouvelle adresse,
  puis **réactiver les notifications** (une autorisation de notifications est liée à l'adresse).
- **Liens de suivi déjà envoyés** aux destinataires : ils pointent vers l'ancienne adresse et ne
  s'ouvrent plus. Les nouveaux messages utilisent la nouvelle adresse.
- **WhatsApp (Meta)** : dans le tableau de bord Meta, l'URL du webhook devient
  `https://admin.jibiat.com/api/webhooks/whatsapp` (elle répond à toutes les adresses de
  l'application, mais pas à `jibiat.com` quand c'est un site de présentation à part ; le jeton de
  vérification ne change pas).
- **API publique** : les marchands qui l'utilisent changent seulement l'adresse de base
  (`https://express.jibiat.com/api/public/v1`) ; leurs clés restent valables.

---

## Ajouter une entreprise

Console `https://admin.jibiat.com` > **Nouvelle entreprise** : nom, adresse (ex. `eclair`),
premier administrateur. L'adresse `https://eclair.jibiat.com` fonctionne immédiatement :
l'enregistrement DNS `*` et le certificat `*.jibiat.com` la couvrent déjà.

Les entreprises peuvent aussi **s'inscrire seules** sur `https://admin.jibiat.com/creer-mon-entreprise`
(essai gratuit, adresse et espace créés aussitôt). Mettez ce lien sur votre site de présentation
`jibiat.com` (bouton « Essayer gratuitement »). Dans la console, **Entreprises** > « Inscription en
ligne » : **Fermer** pour la suspendre ; il faut au moins une formule active dans **Formules**.

---

## Domaines personnalisés (marque blanche)

Une entreprise dont la formule inclut « Domaine personnalisé » peut utiliser sa propre adresse,
par exemple `livraison.rapide-express.ci`, en plus de `rapide-express.jibiat.com`. Sur son
adresse, l'application porte son nom et son logo ; « Jibiat » n'apparaît pas. Une fois le
domaine vérifié, les liens envoyés (suivi, boutiques, bons de retour, point de la semaine)
l'utilisent.

**Comment ça marche** : le client crée chez son fournisseur de domaine un enregistrement
`CNAME livraison → clients.jibiat.com`. Cloudflare for SaaS reçoit ce trafic, crée le
certificat HTTPS du client tout seul, et l'envoie à votre serveur avec le nom du client.
L'application reconnaît l'entreprise par ce nom. Le domaine passe « Actif » dès que
`https://livraison.rapide-express.ci/.well-known/domain-check` répond avec cette entreprise
(vérifié toutes les 10 minutes, ou avec le bouton « Vérifier maintenant »).

### Mise en place (une seule fois)

1. **Cloudflare, zone jibiat.com > DNS** : ajoutez `A clients → 180.149.196.70` (proxy orange
   activé). C'est l'« origine de repli » des domaines clients.
2. **Cloudflare > SSL/TLS > Custom Hostnames** : activez Cloudflare for SaaS (les 100 premiers
   domaines sont gratuits), puis **Fallback Origin** = `clients.jibiat.com`.
3. **Cloudflare > Rules > Configuration Rules** : créez une règle « Domaines clients » :
   *Hostname does not end with* `jibiat.com` → **SSL : Full**. Le certificat d'origine du serveur
   couvre `*.jibiat.com`, pas les domaines des clients ; « Full » garde la connexion chiffrée
   sans exiger ce nom (les adresses en .jibiat.com restent en Full (strict)).
4. **Fichier .env** du serveur, puis `bash deploy/update.sh` :

   ```
   PLATFORM_CUSTOM_DOMAIN_TARGET=clients.jibiat.com
   # Facultatif : création automatique des domaines dans Cloudflare
   CLOUDFLARE_API_TOKEN=…   # Mon profil > Jetons d'API : « Zone > SSL and Certificates > Edit » sur jibiat.com
   CLOUDFLARE_ZONE_ID=…     # Tableau de bord de jibiat.com, colonne de droite « Zone ID »
   ```

### À chaque nouveau domaine

- **Cloudflare** : si le jeton n'est pas configuré, ajoutez le domaine dans
  *SSL/TLS > Custom Hostnames > Add Custom Hostname* (validation par HTTP). Avec le jeton,
  c'est automatique.
- **ISPConfig** : ouvrez le site de l'application > *Options* > *Apache Directives* et ajoutez
  une ligne `ServerAlias livraison.rapide-express.ci` sous la ligne `ServerAlias *.jibiat.com`.
  Sans elle, Apache ne sait pas que ce nom va à l'application.
- La console (fiche de l'entreprise) rappelle ces deux points tant que le domaine est en attente.

---

## WhatsApp : application Meta (une seule pour toute la plateforme)

Une application Meta « Jibiat » sert à toutes les entreprises : chacune y branche son propre numéro,
en un clic avec le bouton **Connecter WhatsApp** (inscription intégrée de Meta, « Embedded Signup »).

### 1. Application Meta (une fois)

1. **business.facebook.com** : portefeuille d'entreprise Jibiat **vérifié** (Paramètres > Centre de sécurité).
2. **developers.facebook.com** > *Créer une app* : nom « Jibiat », e-mail `contact@jibiat.com`, cas d'usage
   **Communiquer avec vos clients sur WhatsApp**, portefeuille Jibiat.
3. *Paramètres de l'app > Général* : domaine `jibiat.com` ; confidentialité `https://jibiat.com/confidentialite` ;
   conditions `https://jibiat.com/conditions` ; suppression des données `https://jibiat.com/confidentialite#suppression` ;
   icône 1024 × 1024 ; catégorie « Entreprise et pages ». Notez la **clé secrète** et l'**identifiant de l'app**.
4. *WhatsApp > Configuration* : URL de rappel `https://admin.jibiat.com/api/webhooks/whatsapp`, jeton de vérification
   = une phrase de votre choix ; abonnez le champ **messages**.
5. **Facebook Login for Business** (ajouter le produit) > *Configurations* > **Créer à partir d'un modèle** :
   « WhatsApp Embedded Signup Configuration With 60 Expiration Token » (le bouton *Créer* simple ne propose que la
   variante « General », qui ne montre pas les écrans WhatsApp). Vérifiez que la variante affichée est
   **WhatsApp Embedded Signup**, autorisations `whatsapp_business_management` et `whatsapp_business_messaging`.
   Notez l'**identifiant de configuration**.
   Dans ses *Paramètres* : connexion OAuth Web et SDK JavaScript activés ; domaines autorisés pour le SDK JavaScript :
   `https://jibiat.com` (seule adresse à déclarer : la fenêtre de Meta s'ouvre toujours depuis l'adresse de la
   plateforme, quelle que soit l'adresse de l'entreprise).
6. Activez le statut de **fournisseur de technologie** (Tech Provider) proposé dans le tableau de bord WhatsApp de l'app.
7. Publiez l'app (**Live**) : sans cela, Meta n'envoie pas les messages reçus des vrais numéros.
8. **Utilisateur système de Jibiat** (jeton permanent) : le jeton remis par le bouton expire au bout de 60 jours.
   *business.facebook.com > Paramètres > Utilisateurs > Utilisateurs système* > *Ajouter* : nom « Jibiat API »,
   rôle **Admin**. *Ajouter des éléments* : l'app Jibiat (contrôle total). *Générer un jeton* : app Jibiat,
   expiration **Jamais**, autorisations `business_management`, `whatsapp_business_management` et
   `whatsapp_business_messaging`. Notez le jeton et l'**identifiant** de l'utilisateur système (affiché sous son nom).
   À chaque connexion, Jibiat ajoute cet utilisateur au compte WhatsApp de l'entreprise et utilise son jeton :
   plus d'expiration. Sans lui, Jibiat prévient les administrateurs de l'entreprise 7 jours avant l'échéance
   (il suffit de cliquer à nouveau sur « Connecter WhatsApp » avec le même numéro).
9. Fichier `.env` du serveur (jamais sur GitHub), puis `php artisan config:clear` :

   ```
   WHATSAPP_DRIVER=auto
   WHATSAPP_APP_ID=…            # identifiant de l'app (étape 3)
   WHATSAPP_APP_SECRET=…        # clé secrète (étape 3)
   WHATSAPP_VERIFY_TOKEN=…      # phrase de l'étape 4
   WHATSAPP_SIGNUP_CONFIG_ID=…  # identifiant de configuration (étape 5)
   WHATSAPP_SYSTEM_USER_ID=…    # identifiant de l'utilisateur système (étape 8)
   WHATSAPP_SYSTEM_USER_TOKEN=… # son jeton permanent (étape 8), à ne jamais partager
   ```

### 2. Validation de Meta (App Review)

Avant validation, le bouton ne fonctionne que pour les comptes de l'app (administrateurs, testeurs) et leurs propres
entreprises. Testez-le avec votre entreprise, filmez le parcours (bouton, fenêtre Meta, numéro connecté dans Jibiat,
message reçu), puis demandez l'**accès avancé** à `whatsapp_business_management` et `whatsapp_business_messaging`.
Une fois validé, toutes les entreprises clientes peuvent se connecter seules.

### 3. Chaque entreprise : Paramètres > WhatsApp > Connecter WhatsApp

La page `jibiat.com/connecter-whatsapp` s'ouvre dans le même onglet (ticket chiffré valable 20 minutes, lié à l'utilisateur
et à son entreprise), avec « Continuer avec Facebook » et « Revenir à mon espace ». La fenêtre de Meta s'ouvre ensuite : connexion Facebook, choix ou création du compte WhatsApp Business, ajout du numéro (code par SMS).
Jibiat échange ensuite l'autorisation contre un jeton propre à l'entreprise, active le numéro (code PIN gardé chiffré),
le vérifie, l'abonne aux messages reçus et soumet les modèles de messages, puis ramène sur la page WhatsApp de l'espace (en cas d'échec, le message d'erreur y est affiché). Le numéro ne doit pas être utilisé dans
l'application WhatsApp d'un téléphone ; l'entreprise ajoute un moyen de paiement dans Meta (messages facturés à son compte).

**Saisie manuelle (avancé)**, dans la même page : identifiant du numéro, WABA ID et jeton permanent d'un utilisateur
système (Meta Business > Utilisateurs système) — utile sans le bouton ou pour un numéro déjà branché.

---

## Site de présentation (jibiat.com)

L'application sert elle-même le site de présentation sur `jibiat.com` et `www.jibiat.com` : accueil
(`/`), tarifs (`/tarifs`) et confidentialité. Les tarifs sont ceux des **formules actives** de la
console ; les boutons « Essai gratuit » mènent à `admin.jibiat.com/creer-mon-entreprise` (formule
présélectionnée), « Demander une démo » ouvre WhatsApp.

Pour que `jibiat.com` arrive sur l'application, une seule fois dans ISPConfig :

1. Si un site `jibiat.com` à part existe (étape 3.2) et que vous n'en avez plus besoin :
   **Sites** > **Website** > `jibiat.com` > supprimez-le (sauvegardez son contenu avant si besoin).
2. Site de l'application > onglet **Options** > **Apache Directives**, sous la ligne `ServerAlias *.jibiat.com` :
   ```
   ServerAlias jibiat.com www.jibiat.com
   ```
3. **Save**, attendez une minute, puis ouvrez `https://jibiat.com`.

Réglages dans le `.env` (puis `php artisan optimize`) : `MARKETING_WHATSAPP_NUMBER` (numéro WhatsApp
commercial, `+2250507016739` par défaut ; l'ancien `MARKETING_WHATSAPP` n'est plus lu) et `PLATFORM_TRIAL_DAYS` (durée de l'essai affichée).
Les avis clients se modifient dans `config/marketing.php`. Le numéro WhatsApp n'est jamais affiché : seuls
les boutons l'utilisent.

**Conditions générales** : `/conditions` (et `/confidentialite`), sur toutes les adresses, liées depuis le pied
de page, l'inscription des entreprises et celle des e-commerçants. L'éditeur se règle dans le `.env` :
`LEGAL_NAME` (Jibiat SARL par défaut), `LEGAL_ADDRESS`, `LEGAL_EMAIL` (contact@jibiat.com) et `LEGAL_RCCM`
(affiché quand il est renseigné). Les délais de paiement cités viennent des réglages de facturation
(`PLATFORM_INVOICE_DUE_DAYS`, `PLATFORM_BILLING_WARNING_DAYS`, `PLATFORM_BILLING_RESTRICT_DAYS`).

---

## Reprise depuis l'ancienne application (Laravel 5.8)

E-commerçants et livreurs d'une entreprise passent sur la plateforme avec **le même numéro et le même mot de passe**
(hachages bcrypt repris tels quels). Aucun message n'est envoyé. Les commandes ne sont pas reprises, ni les comptes de
l'équipe : recréez-les dans **Utilisateurs**.

Ce qui est repris (une ancienne base = une entreprise) :

| Ancienne base | Sur la plateforme |
|---|---|
| `clients` + son compte `users` de type `client` (`clients.user_id`, ou `users.client_id`) | e-commerçant + compte propriétaire ; la commune (`city`) choisit la zone de ramassage |
| autres `users` de type `client` d'un même client (`users.client_id`) | comptes employés de la boutique |
| `livreurs` + son compte `users` de type `livreur` | livreur (suspendu si `inactive` ou si la formule est pleine) |
| `clients` ou `livreurs` rattachés à un compte d'un autre type (ex. la fiche client de l'administrateur) | non repris |
| `users` d'un autre type (`usertype` ni `client` ni `livreur`) | non repris : l'équipe se recrée dans **Utilisateurs** |

Avant tout : créez l'entreprise sur la plateforme et ses **zones**.

### Avec le bouton de l'ancienne application (recommandé)

1. Dans l'espace de l'entreprise : **Paramètres › Reprise de l'ancienne application** › télécharger
   `JibiatMigrationController.php` (aussi dans `legacy/laravel-5.8/` du dépôt).
2. Sur le serveur de l'ancienne application : copier le fichier dans `app/Http/Controllers/` et ajouter les deux routes
   indiquées en tête du fichier dans `routes/web.php` (PHP 7.1 ou plus, extension curl, connexions HTTPS sortantes).
3. Dans **Paramètres › Reprise**, créer une **clé de reprise** (valable 7 jours, affichée une fois).
4. Dans l'ancienne application, connecté en administrateur : ouvrir `/jibiat-migration`, coller l'adresse de l'espace
   (`https://entreprise.jibiat.com`) et la clé, **Simuler**, vérifier le compte rendu, puis **Envoyer**.
   L'envoi se fait par paquets de 200 (e-commerçants, puis livreurs) vers `POST /api/legacy-import` ; le mot de passe
   des comptes de l'équipe n'est jamais envoyé.
5. Une fois terminé : désactiver la clé, retirer les deux routes et le fichier de l'ancienne application.

### Avec un export SQL (sans accès au code de l'ancienne application)

1. Dans le phpMyAdmin de l'ancienne base de l'entreprise : cochez **clients**, **livreurs** et **users**, puis
   *Exporter* > méthode *Personnalisée* > format **SQL**, *structure et données*. Envoyez le fichier sur le serveur
   (SFTP), hors du dossier web, par exemple dans `/root/imports/`.
2. Simulation, puis import :

   ```bash
   cd /var/www/clients/client0/web2/web/livraison
   sudo -u "$(stat -c %U .env)" php artisan legacy:import rapide-express /root/imports/rapide.sql --dry-run
   sudo -u "$(stat -c %U .env)" php artisan legacy:import rapide-express /root/imports/rapide.sql
   ```

   Supprimez le fichier d'export après l'import.

Dans les deux cas, le compte rendu liste les lignes non importées (numéro invalide, numéro déjà présent, fiche
rattachée à un compte de l'équipe) et les points à vérifier : commune non reconnue (zone à choisir sur la fiche),
compte sans mot de passe repris (« Mot de passe oublié », ou `php artisan user:password`), livreur suspendu faute de
place dans la formule. Relancer ne crée pas de doublon : un numéro déjà présent est ignoré.

---

## Limites par entreprise

Pour qu'une entreprise très active ne ralentisse pas les autres, l'espace connecté accepte par
minute (réglables dans le `.env`, puis `php artisan optimize`) :

| Réglage | Défaut | Portée |
|---|---|---|
| `PLATFORM_USER_RATE_LIMIT` | 300 | par compte |
| `PLATFORM_COMPANY_RATE_LIMIT` | 3000 | pour toute une entreprise |

Au-delà, l'application répond « Trop de tentatives. Réessayez dans quelques instants. » pendant
quelques secondes. La console du super administrateur n'a pas de limite d'entreprise.

---

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Erreur Cloudflare **526** | Certificat absent ou incorrect dans ISPConfig (étape 3), ou mode SSL autre que Full (strict) |
| Erreur Cloudflare **521** / **522** | Serveur injoignable : Apache arrêté, pare-feu, mauvaise adresse IP dans le DNS |
| « Aucune entreprise à cette adresse » | Adresse mal saisie, ou entreprise renommée : `php artisan platform:status` |
| « Service suspendu » | Entreprise suspendue dans la console |
| Pas de temps réel (carte figée) | WebSockets désactivés chez Cloudflare, ou `livraison-reverb` arrêté : `supervisorctl status` |
| « Trop de tentatives » pour tout le monde | `TRUSTED_PROXIES=cloudflare` absent du `.env` : tous les visiteurs semblent venir de Cloudflare |
| Mise à jour de l'application non visible sur les téléphones | Fermer et rouvrir l'application ; le fichier `sw.js` n'est jamais mis en cache |

Revenir à une seule entreprise : videz `PLATFORM_DOMAIN` dans le `.env`, retirez la directive
d'origine dans ISPConfig, puis `bash <dossier>/deploy/update.sh`.

---

## Serveur sans ISPConfig (Nginx, `deploy/install.sh`)

Mêmes étapes 1 et 2, puis, avec les deux fichiers du certificat copiés sur le serveur :

```bash
PLATFORM_DOMAIN=jibiat.com ORIGIN_CERT=/root/jibiat-cert.pem ORIGIN_KEY=/root/jibiat-key.pem \
    bash /var/www/livraison/deploy/platform.sh
```

Le script réécrit le site Nginx pour `jibiat.com` et `*.jibiat.com` (l'ancienne configuration
est gardée dans `/etc/nginx/sites-available/livraison.avant-plateforme`).

Sans le proxy Cloudflare (nuage gris), utilisez Let's Encrypt par validation DNS : créez un jeton
Cloudflare (**My Profile** > **API Tokens** > modèle **Edit zone DNS**, zone `jibiat.com`), puis :

```bash
PLATFORM_DOMAIN=jibiat.com CLOUDFLARE=0 CF_API_TOKEN=… EMAIL=moi@jibiat.com \
    bash /var/www/livraison/deploy/platform.sh
```

Le certificat se renouvelle ensuite tout seul.
