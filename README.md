# Logistica : gestion de livraison (e-commerce, distribution, MAD)

Back-office (entreprise de livraison), espace client et application livreur, pour une seule entreprise de
livraison. Repris de Delivery App (`habibou84/deliverynew`) sans la partie plateforme (plusieurs entreprises,
abonnements).

- **Plan d'exécution, choix techniques et roadmap** : [`docs/PLAN.md`](docs/PLAN.md)
- **Schéma de base de données** : [`docs/database.dbml`](docs/database.dbml) (à importer sur https://dbdiagram.io)

## Stack

| Couche | Technologie |
|---|---|
| API | Laravel 12, Sanctum (jetons), spatie/laravel-permission |
| Base de données | PostgreSQL 16 |
| Files d'attente / cache | Redis + Laravel Horizon |
| Temps réel | Laravel Reverb (WebSocket) |
| Front-end | Vue 3, Pinia, Vue Router, Tailwind CSS 4 (Vite) |

## Installation locale

Prérequis : PHP 8.2+ (extensions `pdo_pgsql` et `redis` ; `pcntl` seulement pour Horizon en production), Composer, Node 22, PostgreSQL 16, Redis.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan reverb:install          # génère les clés REVERB_* dans .env

# Créer la base (adapter à votre installation PostgreSQL)
createuser -P livraison             # mot de passe : secret (cf. .env)
createdb -O livraison livraison

php artisan migrate --seed          # rôles + données de démonstration
composer dev                        # serveur, file d'attente, Reverb et Vite
```

`composer dev` fonctionne sous Windows, macOS et Linux : il lance `queue:listen` (et non Horizon) et ne lance pas `pail`.
Les logs sont dans `storage/logs/laravel.log` ; sous macOS/Linux, `php artisan pail` les affiche en direct.

#### Windows

Horizon et Pail ont besoin des extensions PHP `pcntl` et `posix`, qui n'existent pas sous Windows.
`composer.json` les déclare dans `config.platform` pour que `composer install` fonctionne quand même ;
ces deux outils ne s'utilisent simplement pas en local (Horizon sert en production, sur un serveur Linux).

Sans Redis (cas fréquent sous Windows), utilisez la base de données pour le cache et les files d'attente dans `.env` :

```dotenv
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Après une mise à jour du code, relancez toujours `composer install`, `npm install` et `php artisan migrate`.

Puis ouvrir http://localhost:8000.

### Applications mobiles (PWA)

| Application | Adresse | Pour |
|---|---|---|
| Back-office | `/admin` | administrateurs, dispatch, caisse (écran d'ordinateur) |
| Mes livraisons | `/marchand` | clients (téléphone) |
| Livreur | `/livreur` | livreurs (téléphone) |

Après connexion, chacun arrive sur son application. Sur Android (Chrome), un bouton « Installer l'application » apparaît ;
sur iPhone (Safari), *Partager → Sur l'écran d'accueil*. L'installation et le service worker exigent **HTTPS**
(ou `localhost`) : pour tester sur un téléphone en local, passez par un tunnel HTTPS (ngrok, Cloudflare Tunnel…).
Le service worker n'est actif qu'avec le build de production (`npm run build`), pas avec `npm run dev`.

Hors ligne, l'application s'ouvre et affiche les dernières données connues ; les actions demandent le réseau.

### WhatsApp et SMS

Le mode réel ou simulation se décide **entreprise par entreprise** (`WHATSAPP_DRIVER=auto`, par défaut) : une
entreprise dont le numéro est connecté (vérifié auprès de Meta) envoie de vrais messages ; les autres restent en
simulation (messages visibles dans **Messages**, sur la fiche colis et dans `storage/logs/laravel.log`).
`WHATSAPP_DRIVER=log` impose la simulation partout (développement) ; `meta` envoie dès qu'un numéro est renseigné.

Pour envoyer de vrais messages :

1. Une fois : l'application Meta et le webhook (`docs/DEPLOIEMENT.md`, « WhatsApp ») ;
   `WHATSAPP_APP_SECRET` et `WHATSAPP_VERIFY_TOKEN` dans `.env`.
2. Dans le back-office, **WhatsApp** : identifiant du numéro, identifiant WABA et jeton permanent, puis « Enregistrer et
   vérifier ». L'application interroge Meta (`App\Services\Messaging\WhatsAppConnection`) : nom vérifié, note de qualité,
   erreurs expliquées (jeton expiré, identifiant introuvable, autorisations) ; elle abonne l'application Meta au compte
   WhatsApp (messages reçus et accusés). Connecté = envois réels pour cette entreprise.
3. « Soumettre les modèles à Meta » crée tous les modèles (Utilitaire, Français, avec exemples) ; « Vérifier l'approbation
   chez Meta » relève leur état.
5. SMS de repli (facultatif) : `SMS_DRIVER=twilio` avec `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` ; `SMS_DRIVER=none` pour le désactiver.

#### Courses par WhatsApp

Un marchand dont le numéro est connu écrit au numéro de l'agence (message libre, commande transférée de son client
ou menu à boutons) : le bot demande ce qui manque, montre un récapitulatif chiffré et crée la course après
« Confirmer ». Il donne aussi le suivi d'un colis (envoyer son numéro `LV-…`) et le point du jour. Le réglage
« Courses par WhatsApp » se trouve sur la page **WhatsApp**, et **WhatsApp > Simulateur** permet de tout essayer
sans compte Meta (les courses confirmées sont réellement créées).

L'analyse se fait par règles ; avec `ANTHROPIC_API_KEY` (et `ANTHROPIC_MODEL`, par défaut `claude-opus-5-5`),
Claude comprend aussi les messages sans format, avec retour automatique aux règles en cas d'erreur.

### Réglages et administration sur téléphone

**Réglages** (menu Configuration, `/admin/reglages`) : une carte par thème, avec l'état actuel en résumé et une
recherche (« heure limite », « logo »…) — Entreprise et marque, Courses et livraison, Zones et tarifs (frais des
retours dans Tarifs), Alertes et rappels, Messages aux clients (WhatsApp), Clients, Équipe et paie,
Intégrations (API, reprise de l'ancienne application), Abonnement. Chaque page est courte ; une barre
« Enregistrer / Annuler » apparaît dès qu'un réglage change, et quitter sans enregistrer demande confirmation.
Raccourcis : ⚙️ dans la liste des courses, « Délais d'alerte » sur le tableau de bord. L'ancienne adresse
`/admin/parametres` mène aux Réglages.

**Sur téléphone** (moins de 768 px) : barre d'onglets en bas (Accueil, Courses, Carte, Alertes et Menu pour le
dispatch ; selon les droits sinon), bouton ＋ « Nouvelle course », recherche par la loupe, déconnexion dans le menu.
Les courses s'affichent en cartes (appel du destinataire, sélection, barre d'actions fixée en bas), les filtres dans
un panneau ; les tableaux de la Caisse, des Clients et des Utilisateurs passent en cartes (`stack-table`).
`npm run test:mobile` (et la CI) vérifie qu'aucune page de l'administration ne dépasse un écran de 360 px (petits téléphones).

### Remontées terrain

Les notes et problèmes enregistrés par les livreurs (échec ou report de livraison, échec de ramassage, refus de
mission, frais déclarés, notes, même internes) arrivent **en direct** au dispatch : toast, **son** (carillon pour une
note, trois bips pour un problème) et notification du bureau si l'onglet est caché. Réglages (son, volume, test,
notifications du bureau) via 🔊 dans l'en-tête ; le navigateur n'autorise le son qu'après un premier clic dans la page.
La page **Remontées terrain** les liste (filtres : à traiter / traitées, type, livreur, dates, recherche) ; chaque
remontée se marque « traitée » avec un commentaire, le menu affiche le nombre restant (30 derniers jours).

- **Réponse au livreur** : depuis une remontée (ou la fiche d'une course), le dispatch envoie une consigne, avec des
  réponses rapides en un clic. Le livreur la reçoit en direct (son, vibration, bandeau), la retrouve dans sa mission
  et dans **Consignes de l'agence**, et la confirme d'un « 👍 Compris » ; le dispatch voit « lu » puis « compris ».
  La consigne est tracée au journal de la course (note interne) et peut marquer la remontée comme traitée.
- **Relance automatique** (`field-reports:remind`, chaque minute) : un problème resté sans réponse ni traitement est
  relancé au dispatch après le délai réglé dans **Réglages › Alertes et rappels** (10 min par défaut, 0 = jamais), puis aux
  administrateurs après trois fois ce délai. Rien n'est relancé si la course est terminée.

### Colis non livrés chez les livreurs

L'application sait à tout moment quel livreur a quel colis en main (ramassé, en livraison, en échec ou reporté),
jusqu'à ce qu'il soit rendu au dépôt, livré ou retourné au marchand.

- **Point de caisse** : le versement liste les colis non livrés du livreur ; le caissier coche ceux qu'il reçoit
  (« Tout cocher » possible). Les colis non cochés sont notés « gardés » sur le versement. Un colis ramassé rendu
  passe « Au dépôt » ; un colis en échec ou reporté garde son statut pour que le dispatch décide de la suite.
  Un livreur sans argent à verser rend ses colis via « Recevoir les colis ».
- **Point bloqué** tant qu'une course du livreur est « En chemin » : il doit d'abord indiquer « livré » ou « échec ».
- **Colis chez les livreurs** (back-office, `orders.dispatch`) : colis par livreur avec leur ancienneté, filtre
  « En retard », réception au dépôt en un clic. Au-delà du délai réglé dans **Réglages › Alertes et rappels** (24 h par défaut,
  0 = jamais), le colis passe en rouge, le menu affiche un badge et le dispatch reçoit une alerte sonore
  (`parcels:overdue`, toutes les 15 min, une alerte par colis).
- Le livreur voit dans **Ma caisse** les colis à rapporter au dépôt.
- **Livreurs disponibles** (back-office, `orders.dispatch`) : livreurs libres (aucune mission en cours) ou bientôt
  libres (2 missions ou moins), zone de leur dernier arrêt de la journée, position GPS récente et colis à ramasser
  près d'eux (à moins de 3 km, dans la zone du dernier arrêt ou dans leurs zones habituelles). **Mission ramassage** :
  les colis cochés sont confiés d'un coup au livreur, sans changer leur date de livraison (souvent demain). Le dispatch
  reçoit une alerte quand un livreur qui a déjà travaillé devient libre ou bientôt libre avec des ramassages près de
  lui (`couriers:available`, chaque minute, une alerte par palier et par jour).
- **Après une mission ramassage** (**Réglages › Courses et livraison**) : les colis **restent chez le livreur** jusqu'à leur jour de
  livraison, **reviennent au dépôt** le soir même, ou **au choix du dispatch** à chaque mission (par défaut). Un colis
  gardé n'est pas « en retard » avant son jour de livraison ; ce jour-là, sa livraison est confiée automatiquement au
  livreur qui l'a (`parcels:kept-deliveries`, toutes les 15 min). Confier la livraison à un autre livreur signale une
  passation. Passé l'**heure de fermeture du dépôt**, le livreur garde les colis jusqu'au matin. Le livreur voit dans
  **Ma caisse** les colis à garder et ceux à rapporter.
- **Décider de la suite** (file **À décider** des courses, ou fiche de la course) : un échec de livraison attend
  une décision avant de revenir dans « À livrer » : **relivrer** à une date (aujourd'hui, demain…, livreur
  facultatif), **retourner au marchand** ou, pour une commande d'entrepôt, **remettre en stock**. Le marchand est
  prévenu par WhatsApp (nouvelle date ou retour du colis). Les reports à une date future sont dans **Reportées** et
  reviennent dans « À livrer » le jour prévu.
- **Rappel du matin** (`orders:due-today`, chaque jour à 06:50) : le dispatch est prévenu des courses reportées à
  aujourd'hui et pas encore assignées, avec les livreurs qui ont déjà le colis en main (à leur confier en priorité).
  Dans la liste des courses, 🎒 indique le livreur qui a le colis.
- **Bon de retour groupé** (**Retours marchands**, back-office) : les colis à rendre sont regroupés par marchand ;
  le dispatch crée un bon (`BR-…`) confié à un livreur (celui qui a déjà les colis est proposé). Le marchand reçoit
  le lien du bon par WhatsApp. Dans son application, le livreur ouvre le bon, coche les colis remis, saisit le nom
  de la personne qui reçoit et fait **signer à l'écran** ou **photographie** la remise : toutes les courses passent
  « Retourné » et le marchand reçoit la confirmation. Le bon est imprimable (A4) depuis `/bon-de-retour/{id}`.
  Deux nouveaux modèles WhatsApp sont à faire approuver : `bon_de_retour` et `retour_remis`.
- **Colis perdu** : un administrateur (dispatch + caisse) déclare la perte depuis la fiche de la course (bouton
  « 🚨 Déclarer le colis perdu ») : circonstances, **indemnité au marchand** (valeur des articles proposée), portée à
  son grand livre et versée avec son prochain reversement, et **retenue facultative** sur la paie du livreur
  responsable. La course passe « Perdu » (statut final), le marchand est prévenu par WhatsApp, le stock d'une
  commande d'entrepôt est sorti. Un colis resté chez un livreur plus de trois fois le délai d'alerte (72 h par défaut)
  est signalé « peut-être perdu » aux administrateurs et dans « Colis chez les livreurs ».

### Notifications push des livreurs

Le livreur active les notifications depuis le bandeau de **Missions** ou son **Profil** (bouton de test). Il est alors
prévenu même application fermée ou téléphone en veille : **nouvelle mission** et **consigne de l'agence** (le toucher
ouvre la mission). Web Push standard (VAPID, chiffrement `aes128gcm`), implémenté sans dépendance ni service tiers.

- Générer les clés une fois par serveur : `php artisan webpush:vapid`, puis copier `VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY` et `VAPID_SUBJECT` (mailto: ou https:) dans `.env`. Ne pas les changer ensuite : les abonnements
  existants deviendraient invalides. Sans clés, la fonction est simplement masquée.
- HTTPS obligatoire (sauf `localhost`) ; le service worker n'est enregistré que dans la version compilée (`npm run build`).
- **iPhone** : iOS 16.4 minimum et application **installée sur l'écran d'accueil** ; l'app le signale au livreur.
- Les abonnements expirés (réponse 404/410) sont supprimés automatiquement ; la déconnexion retire celui de l'appareil.

### Notifications des clients (à la place de WhatsApp)

Un client qui active les notifications dans son application (invitation sur l'**Accueil**, ou **Profil** >
**Mes notifications**) reçoit ses messages en **notification gratuite** au lieu de WhatsApp : course validée, colis
récupéré, livré ou expédié, incident, reversement, bon de retour, point d'activité. Toucher la notification ouvre la
course, les paiements ou les statistiques.

- Il garde le choix des messages reçus ; le numéro WhatsApp sert **de secours** : une notification qu'aucun de ses
  appareils n'a reçue (application désinstallée, notifications coupées) repart aussitôt par WhatsApp, puis par SMS
  si WhatsApp échoue aussi.
- Réglage de l'entreprise dans **WhatsApp** > **Envois** (activé par défaut), avec le nombre de clients déjà
  passés aux notifications. Les destinataires des colis, qui n'ont pas l'application, restent prévenus par WhatsApp.
- Le journal des messages les affiche avec 📱 (filtre « Notifications »).

### Carte des livreurs

**Carte des livreurs** (back-office, droit `orders.dispatch`) montre la dernière position de chaque livreur, ses
missions en cours et l'âge de la position ; elle se met à jour en direct (Reverb). L'application livreur envoie sa
position toutes les 30 secondes pendant le service (localisation autorisée sur le téléphone).
**Trajet du jour** (depuis la carte, ou `/admin/carte?livreur={id}&date=AAAA-MM-JJ`) : tracé de la journée, étapes
des courses localisées (ramassé, livré, échec…), distance parcourue et heures de service. Les positions trop
imprécises, à l'arrêt ou aberrantes ne sont pas conservées ; l'historique est purgé après 90 jours (`model:prune`). Fond de carte
OpenStreetMap via Leaflet, sans clé ; pour un usage intensif, passer à un fournisseur de tuiles (MapTiler, Stadia…).

### Stock et entrepôts

Le marchand enregistre ses produits et le stock qu'il garde chez lui (**Mon stock** dans son application) ; l'entreprise
tient le stock de ses entrepôts (**Stock** dans le back-office : entrées, retraits, inventaires, mouvements). En créant
une course, on choisit les articles : ils sont réservés, puis sortis du stock à la livraison, ou libérés si la course
est annulée ou le colis remis en stock. Une course qui part d'un entrepôt n'a pas de ramassage : elle est préparée
(onglet « À préparer ») puis livrée. Le stockage est facturé chaque mois selon le contrat du marchand
(`storage:bill`, lancé par le planificateur le 1er du mois ; `php artisan storage:bill --month=2026-09` pour un mois donné).

### API publique, webhooks et import

Un client connecte sa boutique en ligne ou son logiciel depuis **Profil › Intégrations** (l'administration
le fait aussi depuis **Intégrations (API)**) :

- **Clé API** (`lv_…`, affichée une seule fois) pour l'API `/api/public/v1` : zones, devis, création et suivi des
  courses, annulation, point, produits. Documentation : **`/developpeurs/api`** (spécification
  `public/docs/openapi.yaml`, importable dans Postman). `Idempotency-Key` obligatoire sur `POST /orders`,
  120 requêtes par minute et par clé.
- **Synchronisation avec l'application du marchand** (Jibiat Marchand ou une boutique) : rien ne change sans qu'elle le
  sache. `order.updated` signale un changement sans changement de statut (date modifiée ou décalée par la fermeture de la
  journée, nouvelle date d'un report, adresse, montant, retour demandé ; champs dans `change.fields`). La course indique
  `needs_decision` (échec de livraison : le marchand doit décider) et `return_requested`. `GET /delivery-day` donne
  l'état de la journée et le premier jour de livraison possible ; `GET /payouts?paid_since=` rattrape les reversements.
  `notify_merchant: false` à la création : l'application prévient elle-même l'équipe du marchand, pas d'alerte en double.
  Une adresse webhook qui échoue 20 fois d'affilée (tentatives épuisées) est coupée et le marchand est prévenu.
- **Actions du marchand par l'API** (mêmes règles que son application) : `PATCH /orders/{code}` (tout avant le ramassage,
  seulement la livraison ensuite), `POST /orders/{code}/decision` (`redeliver` avec une date, ou `return`, quand
  `needs_decision`), `POST /orders/{code}/return-request` et `POST /orders/{code}/notes`. L'historique de la course
  indique « via Jibiat Marchand » (en-tête `X-Client-App`) ou « via API ».
- **Contrat avec Jibiat Marchand** (`habibou84/socle`, `contract/v1`) : `PublicContractTest` valide les vraies réponses
  et les vrais webhooks envoyés contre les schémas partagés, et vérifie que les statuts et événements sont ceux du
  contrat. Ajouter un champ est libre ; en retirer ou en renommer un demande une version majeure du contrat.
- **Webhooks** : la boutique est prévenue de chaque événement (`order.created`, `order.status_changed`,
  `order.incident`, `order.updated`, `payout.paid`, `stock.low`). Vérification de la signature côté boutique :

  ```php
  $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp').'.'.$request->getContent(), $secret);
  abort_unless(hash_equals($expected, $request->header('X-Webhook-Signature')), 401);
  ```

  Envois par la file `default`, 6 tentatives au plus, journal et renvoi dans l'écran Intégrations.
- **Import** : **Courses › Importer** (back-office) ou **Profil › Importer des courses** (application marchand),
  à partir du modèle CSV téléchargeable ou d'un fichier Excel ; aperçu contrôlé avant création.

Les envois passent par la file `messages` et les points d'activité par le planificateur (`reports:send` toutes les 5 minutes) :
`composer dev` lance les deux.

### Pages de connexion

| Adresse | Pour | Aspect |
|---|---|---|
| `/` | Clients (page d'accueil du site) | Logo, accroche, avantages, connexion, suivi d'un colis, contact WhatsApp |
| `/admin` (→ `/admin/connexion`) | Équipe : administration, dispatch, caisse, entrepôt | Sobre, sombre |
| `/livreur` (→ `/livreur/connexion`) | Livreurs | Application mobile bleue |

Chaque page accepte tous les comptes : après connexion, chacun arrive dans son espace. Le logo (PNG, JPEG ou WebP)
et l'accroche se règlent dans **Réglages › Entreprise et marque**.

### Une seule entreprise

L'installation sert **une seule entreprise de livraison**, à l'adresse `APP_URL` (ex. `https://app.logisticadelivery.com`) :
pas de super administrateur, pas d'abonnement, tous les modules sont inclus (WhatsApp, API et webhooks, stock,
boutiques, statistiques avancées).

- Première mise en service : `php artisan app:install` crée l'entreprise (communes d'Abidjan, grille tarifaire et plan de
  paie de départ) et son premier administrateur ; refusé si l'entreprise existe déjà.
- Les autres comptes se créent dans **Utilisateurs** ; mot de passe perdu : `php artisan user:password <téléphone>`.
- Conditions générales (`/conditions`) et politique de confidentialité (`/confidentialite`) au nom de l'entreprise, ou
  des réglages `LEGAL_NAME`, `LEGAL_ADDRESS`, `LEGAL_EMAIL`, `LEGAL_RCCM`.
- Limites de l'espace connecté, par minute : `PLATFORM_USER_RATE_LIMIT` par compte (300) et
  `PLATFORM_COMPANY_RATE_LIMIT` pour toute l'entreprise (3000).

**Mise en ligne** (Docker derrière Apache, certificat Let's Encrypt) : [`docs/DEPLOIEMENT.md`](docs/DEPLOIEMENT.md).

### Formules des clients : distribution et mise à disposition (MAD)

En dehors des courses ordinaires (frais déduits course par course), un client peut avoir une **formule**
(**Distribution et MAD › Contrats**) ; ses courses passent alors sur sa **facture du mois**, payée à part.

Chaque client a une ou plusieurs **activités** (e-commerce, distribution, MAD), tenues séparément : courses (filtre
« Activité » côte à côte), factures (une série par activité : `DIS-…`, `MAD-…`), chiffre d'affaires (**Finances ›
Chiffre d'affaires**). Les comptes de l'équipe peuvent être limités à certaines activités (périmètre), avec le rôle
« Responsable d'activité » et le réglage fin des permissions (ajoutées ou retirées au rôle, compte par compte).
L'application du client suit ses activités : en distribution et en MAD, rien à encaisser, type d'envoi et délai à la
saisie, forfait du mois, factures (onglet Factures, moyens de paiement), livreurs attitrés en MAD, notifications de
facture et de forfait (voir le guide, section 7).
Guide : [`docs/FORMULES-CLIENTS.md`](docs/FORMULES-CLIENTS.md).

| Formule | Facturation |
|---|---|
| Distribution sous contrat | Forfait mensuel pour un nombre de courses (Abidjan et, au choix, intérieur) ; au-delà, chaque course au prix de sa destination (prix du contrat à Abidjan, grille de la ville à l'intérieur) ; chèque livré à l'unité ; délai fixé par le contrat (24 h, 48 h, 72 h) |
| Distribution à l'unité | Chaque pli, colis ou chèque au prix du délai choisi (Distribution › Tarifs) |
| Mise à disposition (MAD) | Forfait mensuel, courses incluses, chacune au-delà au prix du contrat ; livreurs attitrés |

- Villes de l'intérieur : prix d'un pli (0-500 g), d'un colis (0-5 kg) et du kilogramme au-delà de 5 kg, par délai
  (Distribution › Tarifs, saisis ou importés depuis un fichier CSV ou Excel) ; chaque ville a sa zone.
- Au-delà de 5 kg sur Abidjan : kilogramme au prix de la formule. Hors des communes du forfait (Bingerville…) : tarif ordinaire.
- `billing:invoice` (le 1er à 01:30) calcule les brouillons du mois écoulé ; **Distribution et MAD › Factures** :
  vérifier, ajouter une remise, émettre (numéro `DIS-AAAAMM-0001` ou `MAD-AAAAMM-0001`, échéance `CLIENT_INVOICE_DUE_DAYS`, 15 jours),
  enregistrer les paiements. Le client voit ses factures émises dans son application (Profil › Mes factures).

### Statistiques de l'entreprise

Menu **📊 Statistiques** du back-office (`orders.view`, personnel seulement), service
`App\Services\Reports\Statistics`, API `GET /api/v1/stats/{overview,deliveries,couriers}?from=AAAA-MM-JJ&to=AAAA-MM-JJ`
(par défaut le mois en cours, un an au plus ; dates dans le fuseau de l'entreprise). Chaque événement compte à sa date
(créée le jour de sa création, livrée le jour de sa livraison…).

- **Vue d'ensemble** : courses créées et livrées, taux de livraison (livrées parmi les courses terminées : livrées,
  retournées, perdues), échecs, retours et annulations, chiffre d'affaires (frais de livraison des courses livrées),
  encaissé, frais moyen, avec la **comparaison à la période précédente** de même durée ; courbe par jour (tableau et export
  CSV), chiffre d'affaires par jour, **heures de pointe** (jour de la semaine × heure).
- **Livraisons** : réussite au premier passage, passages par course livrée, livrées le jour même, **motifs d'échec**,
  délais habituels (médianes : création → ramassage → livraison), issues de la période.
- **Livreurs** (`orders.dispatch`) : classement triable et exportable — livrées, ramassages, échecs, réussite, délai,
  jours travaillés, courses par jour ; encaissé, écarts de caisse et gains.
- **Clients** (`merchants.view`, `GET /stats/merchants`) : actifs, nouveaux, **à relancer** (plus de course depuis
  30 jours, bouton WhatsApp), part des 5 premiers ; top et tableau triable (créées, livrées, retournées, réussite,
  chiffre d'affaires, encaissé, reste à reverser).
- **Zones** (`GET /stats/zones`) : par commune (les quartiers comptent dans leur commune) — créées, livrées, échecs,
  réussite des passages, délai médian, frais moyen, chiffre d'affaires ; part de l'expédition ; commune la plus lente.
- **Finances** (`finance.view`, `GET /stats/finances`) : revenus (frais de livraison, de retour, de stockage du grand livre),
  dépenses (paie des livreurs, frais de course à la charge de l'entreprise, indemnités de colis perdus), **marge** totale
  et par course livrée, mois par mois ; frais refacturés à part ; **argent en circulation** (chez les livreurs, à reverser
  aux marchands, dû par des marchands).
- Les montants ne sont renvoyés qu'aux comptes qui voient la caisse (`finance.view`). Tableaux triables et exportables
  (CSV pour Excel).
- **Statistiques avancées** : onglets Zones et Finances et export CSV.
- **Point de la semaine** (`reports:weekly`, vérifié toutes les 15 minutes, envoyé le lundi à 7 h 30 heure de
  l'entreprise, une fois par semaine) : aux administrateurs, par WhatsApp (modèle `point_semaine_gerant`, à faire
  approuver par Meta) et dans la cloche — courses créées et livrées, taux de livraison, chiffre d'affaires comparés à la
  semaine d'avant, clients à relancer, lien vers les statistiques. Réglage « Point de la semaine » dans Réglages › Alertes et rappels
  (activé par défaut) ; rien n'est envoyé une semaine sans activité.
- **Application client** : « 📊 Mes statistiques » (`GET /api/v1/merchant/stats`) — taux de livraison avec
  l'évolution, réussite au premier passage, passages ratés et leurs motifs, communes de livraison, délais, courbe par
  jour ; montants (encaissé, frais) pour les comptes qui voient les paiements.

### Politique de confidentialité

Page publique `/confidentialite`, exigée par Meta pour publier l'application WhatsApp : données traitées, responsable
(l'entreprise de livraison), prestataires (Meta, SMS, Anthropic si activé, hébergement), durées, droits (loi ivoirienne
n° 2013-450, ARTCI) et section `#suppression` (URL d'instructions de suppression des données demandée par Meta).
Exploitant et contact : nom, adresse et e-mail de l'entreprise, ou `LEGAL_NAME`, `LEGAL_ADDRESS`, `LEGAL_EMAIL`.
Texte à faire relire avant publication.

### Paie des livreurs : plans de rémunération

**Paie des livreurs** (`settings.manage`) : chaque entreprise compose ses plans. Un plan est une liste de
**règles** qui s'additionnent à chaque étape d'une course :

- **étapes** : ramassage, livraison réussie, tentative de livraison ratée (payée une fois par course et par jour),
  retour au marchand, remise au transporteur (expédition ; sans règle propre, celles de la livraison s'appliquent) ;
- **calculs** : montant fixe, % des frais de livraison, % du montant encaissé, grille par zone (zone, sinon sa
  commune, sinon « Autres zones ») ;
- **conditions** : zones, express, fragile, type de véhicule, motif d'échec (tentative ratée) ;
- **ramassage** par colis, ou par passage (les colis suivants du même marchand dans les 3 h ne rapportent que le
  montant « colis supplémentaire ») ; **minimum** et **plafond** par étape.

Le **plan par défaut** s'applique aux livreurs sans plan ; un plan peut être attribué à plusieurs livreurs
(page Livreurs), et « Personnaliser pour ce livreur » crée un **plan personnel** copié de son plan actuel. Modèles
prêts à l'emploi : fixe par course, pourcentage des frais, par zone, salarié, mixte, plan vide. Chaque gain garde son
plan, sa règle et le détail du calcul (« 40 % de 2 500 F ») : modifier un plan ne change jamais les gains acquis.
Le livreur voit son gain prévu sur chaque mission et le détail de ses gains à recevoir dans sa caisse. Les anciennes
commissions par livreur ont été reprises en plans personnels à la migration.

**Salaire, périodes et fiches automatiques.** Un plan peut aussi avoir :

- un **salaire de base** par période (livreur salarié, ou mixte avec des règles par course), au prorata des jours
  pour un livreur arrivé en cours de période ;
- une **période de paie** : chaque semaine (lundi → dimanche), chaque quinzaine (1er → 15, 16 → fin du mois) ou
  chaque mois. Chaque nuit, `payroll:close` (00:20) prépare la fiche des périodes terminées : salaire, gains des
  courses, primes et retenues de la période. Une seule fiche par livreur et par période, même annulée.
  « À la demande » : la caisse prépare la fiche quand elle veut (« Préparer la paie ») ;
- un **plafond des retenues** (%) : les retenues (manques de caisse, colis perdus…) ne prennent pas plus que ce
  pourcentage des gains d'une fiche ; le reste est reporté sur la fiche suivante (« Retenue reportée »).

Une fiche peut être payée en **gardant le montant sur l'argent encaissé** : le livreur le garde sur l'argent des
clients qu'il a en main, et le verse en moins lors de son prochain point à la caisse (refusé s'il n'a pas assez
d'argent en main). Le livreur voit dans sa caisse son salaire, sa période, la date de sa prochaine fiche et ses
fiches prêtes en attente de paiement. Modèles : « Salarié » et « Mixte » en plus des modèles à la course.

**Jours, heures, primes et simulateur.**

- Une règle peut ne s'appliquer que **certains jours** (ex. samedi et dimanche) et/ou dans une **plage horaire**
  (ex. 22:00 → 06:00, qui passe minuit), à l'heure de l'étape et dans le fuseau horaire de l'entreprise.
- **Primes d'objectifs** par période de paie : livraisons réussies, ramassages, taux de livraisons réussies (à partir
  d'un nombre minimal de livraisons tentées), jours travaillés. Pour un même objectif, seul le palier le plus haut
  atteint est payé ; des objectifs différents s'additionnent. Les primes sont ajoutées à la fiche de fin de période
  (une fois par période) ; le livreur suit sa progression dans « Ma caisse ». Les indicateurs viennent du journal des
  courses (étapes faites par la mission du livreur).
- **Simulateur** (éditeur de plan, réglages même non enregistrés) : « Une course » calcule le gain d'une étape fictive
  (zone, frais, encaissé, véhicule, jour et heure, express, fragile, motif d'échec) ligne par ligne ; « Rejouer une
  période » reprend l'activité réelle de chaque livreur sur une période de paie passée et la compare à ce qu'il a
  réellement gagné (`POST /pay-plans/simulate`).

### Mise en route de l'administrateur

**Réglages › Mise en route** (`/admin/mise-en-route`, droit `settings.manage`) conduit l'administrateur étape par
étape. Chaque étape se coche toute seule, d'après les vraies données (`App\Services\Onboarding\SetupGuide`) :
- **essentielles** : entreprise (téléphone, adresse, logo), zones, tarifs (chaque paire de communes a un prix dans
  la grille par défaut), règles des courses (heure limite), livreurs (au moins un connecté à son application et avec
  des zones), paie (plan par défaut avec des montants), clients (zone et adresse de ramassage), une première
  course livrée ;
- **conseillées** (remises à plus tard si on veut) : WhatsApp et modèles approuvés, équipe du bureau, alertes,
  assignation automatique, boutiques en ligne.

Chaque étape dit pourquoi elle compte, ce qui manque, et ouvre le bon réglage ; une barre « ← Revenir à la mise en
route » suit l'administrateur dans les réglages ouverts depuis le parcours. Le parcours s'ouvre tout seul à la
première connexion d'un administrateur (tant que les étapes essentielles ne sont pas faites), puis un bandeau du
tableau de bord rappelle ce qui reste. API : `GET /setup` (→ `seen`), `POST /setup/skip` (`step`, `skipped`),
`POST /setup/seen`.

**Course d'essai** (étape 8) : « Lancer une course d'essai » crée une vraie course d'une « Boutique d'essai » vers
l'administrateur lui-même (il reçoit les messages du destinataire). Le parcours coche ses étapes : validée, livreur
de ramassage, colis récupéré, livreur de livraison, livrée avec le code, argent encaissé. « Effacer la course
d'essai » supprime la course, son journal, les gains du livreur, les écritures du client, l'argent encaissé et
la boutique d'essai, sans fausser la caisse ni les statistiques ; l'étape reste faite. L'argent ne doit pas être versé
à la caisse : une fois versé ou payé, la course ne s'efface plus (`POST|DELETE /setup/test-order`).

**Bilan de santé** (en tête de la mise en route, `GET /setup` → `health`) : ce qui se dérègle ensuite, en alerte
(rouge) ou à surveiller (orange) : trajets sans prix, accès WhatsApp qui expire dans moins de 14 jours ou en erreur,
modèles refusés par Meta, livraison automatique avec des livreurs sans zone, ramassage automatique sans règle,
automatisation suspendue, course d'essai pas effacée. Les alertes mettent un point rouge sur « Réglages » dans le
menu.

### Planning des livreurs et ramassage automatique

**Opérations › Planning des livreurs** (`/admin/planning`, permission `orders.dispatch`) dit qui ramasse où et quand.
Une règle vise des **zones** (une commune couvre ses quartiers) ou des **clients**, et vaut tous les jours, certains
jours de la semaine, une date ou une période, avec un **remplaçant** facultatif. La règle la plus précise l'emporte :
un client avant sa zone, un quartier avant sa commune, une date avant une période, avant certains jours, avant « tous
les jours ». Deux règles qui se contredisent (même cible, mêmes jours, autre livreur) sont refusées. Un livreur
**absent** (absences saisies sur la même page) ou **en pause** (bouton « disponible » de son application éteint) est
remplacé par son remplaçant ; sinon personne. La vue d'un jour montre qui couvre chaque règle et les **clients sans
ramasseur** (actifs depuis 30 jours ou avec des courses validées en attente).

**Ramassage automatique** (Réglages › Courses et livraison) : *désactivé* (le planning sert de repère), *à l'essai*
(rien n'est envoyé, la course porte la note « le ramassage aurait été confié à… »), *actif* (à la validation, le
ramassage va au livreur du planning, avec la note « Ramassage confié automatiquement à…, planning « Cocody · tous les
jours » »). Les courses validées avant sont rattrapées toutes les cinq minutes (`assignments:auto`). Sans règle qui
couvre la course, rien ne change : elle reste à l'équipe et aux alertes « sans livreur ».

**Message du matin** : à partir de 6 h 45 (heure de l'entreprise), chaque livreur du planning reçoit une fois ce qu'il
couvre aujourd'hui ou ce qu'il remplace (`couriers:planning-digest`). API : `GET /planning?date=`,
`POST|PUT|DELETE /planning/rules`, `POST|DELETE /planning/absences`.

**Acceptation des missions** (Réglages › Courses et livraison) : *le livreur accepte ou refuse* (par défaut),
*acceptées d'office, empêchement possible* (avant de partir, « Je ne peux pas faire cette mission » rend la mission),
ou *acceptées d'office* (pas de refus, le livreur appelle l'agence). Un administrateur peut donner une autre règle à
un livreur depuis sa fiche. Les missions du planning sont toujours acceptées d'office. Un ramassage du planning refusé
ou rendu passe au remplaçant (le livreur est écarté pour cette course) ; sinon il revient à l'équipe, prévenue.

**Sécurité** (`assignments:watch`, chaque minute) : un livreur **injoignable** (aucun signe de son application depuis
20 min par défaut, alors qu'une mission l'attend depuis aussi longtemps) voit ses ramassages du planning pas commencés
passer à son remplaçant ; l'équipe reçoit une alerte « 📵 … injoignable ». À l'approche de l'heure limite du jour
(une heure avant par défaut), l'équipe est prévenue des **ramassages du jour pas commencés**, une fois par mission ;
rien n'est retiré au livreur. Le bouton **« Suspendre l'automatisation »** du planning arrête toute assignation et tout
passage au remplaçant jusqu'à la reprise (`POST /planning/pause`) ; les alertes continuent. Une nouvelle mission a sur
le téléphone du livreur un son (quatre notes montantes) et une vibration (trois longues) qui lui sont propres.

**Livraison du jour même automatique** (Réglages › Courses et livraison : désactivée, à l'essai, active) : dès qu'un
colis est récupéré ou au dépôt (et toutes les cinq minutes pour rattraper), la livraison du jour part seule si la
course est à livrer aujourd'hui avant l'heure limite, sa zone connue et pas une expédition, le téléphone valable, le
montant à encaisser sous le plafond, sans retour demandé ni tentatives épuisées. Le livreur doit être en service
(disponible, pas absent, avant sa fin de service), avoir un véhicule adapté au colis (pas de grand colis à pied),
rester sous sa capacité (colis en cours, argent en main) et ne pas avoir refusé la course. Ordre du choix : celui qui
a déjà le colis (ou le ramasseur, si l'entreprise le veut), les règles de **livraison** du planning, puis la zone
principale avant la zone de secours, celui qui livre déjà dans le secteur, le moins chargé. La course porte la raison
(« Livraison confiée automatiquement à Koffi : zone principale Yopougon, déjà 1 livraison dans le secteur, 2 colis en
cours »). Zones principales et de secours, colis et argent en main au plus, fin de service : fiche de chaque livreur.
L'**heure limite** peut être différente certains jours de la semaine (`cutoff_by_day`).

### Courses sans livreur

Une course **validée sans livreur de ramassage**, ou un colis **récupéré / à l'entrepôt / reporté à aujourd'hui sans
livreur de livraison**, porte dans la liste une pastille « 🛵 Sans livreur · ramassage · 25 min ». Au-delà du délai
de l'entreprise (**Réglages › Alertes et rappels** : 30 min pour le ramassage, 1 h pour la livraison par défaut ; 0 = pas d'alerte) :

- la pastille et la ligne passent au rouge (« ⏰ ») ;
- les onglets « À ramasser » et « À livrer » affichent leur nombre de courses et, en rouge, celles en retard ;
  le menu « Courses » affiche le total en retard (`GET /orders/counts`) ;
- le dispatch reçoit une alerte sonore regroupée (une fois par course et par statut), les administrateurs une
  seconde alerte après trois fois le délai (`orders:unassigned`, chaque minute).

Le **tableau de bord** du dispatch affiche un encadré « 🛵 En attente d'un livreur » : à assigner au ramassage et à
la livraison, nombre en retard et attente la plus longue (`GET /orders/counts` → `waiting`), avec un lien vers la file.

L'onglet **« 🛵 Sans livreur »** regroupe les deux étapes, **de la plus ancienne attente à la plus récente**
(`GET /orders?queue=unassigned&awaiting=pickup|delivery`), avec un filtre Les deux / Ramassage / Livraison. Avec la
sélection groupée, « Selon la course » assigne en une fois le ramassage des courses à ramasser et la livraison des
colis à livrer au livreur choisi.

Le délai part de l'entrée dans le statut (un désassignement le relance) ; une course prévue un autre jour se ramasse
dès sa validation, mais sa livraison ne compte qu'à partir de ce jour (colis ramassé en avance : file « Prévues plus tard »,
puis « À livrer » le jour venu). Les commandes d'entrepôt ne demandent pas de ramassage ; un report qui a déjà son
livreur de livraison n'est pas compté.

**Jour affiché** (liste des courses de l'agence) : la liste s'ouvre sur **aujourd'hui** (Aujourd'hui, Demain, Toutes les
dates ou un autre jour, gardé d'un onglet à l'autre ; `?day=` dans l'adresse). « Toutes » montre les courses à livrer ce
jour-là et les **retards** encore en cours (pastille « ⏳ En retard », en tête). Les files de travail (À ramasser,
Ramassages par client, À préparer, À livrer, Sans livreur) ne montrent que ce qui est dû ce jour-là, retards compris ;
les courses à livrer plus tard sont signalées à part (« + 4 à ramasser pour une livraison après le … · Afficher ») et
peuvent être prises en avance, aussi lot par lot dans « Ramassages par client ». À valider, À décider, Incidents,
À retourner et Codes à vérifier restent complètes. Une période (dates du filtre) remplace le jour. API :
`GET /orders?day=AAAA-MM-JJ&include_later=1`, `GET /orders/counts?day=` (→ `day` : compteurs du jour, `later`, `days`),
`GET /orders/pickup-groups?day=` (colis `later` et `later_count`). Les alertes et le tableau de bord ne changent pas.

**Heure limite du jour** (Réglages › Courses et livraison, heure de l'entreprise, vide = aucune) : passé cette heure, les courses encore
sans livreur portent la pastille « 🕒 Après 15:00 », le tableau de bord affiche un bandeau rouge (« Il est plus de
15:00 : 3 courses du jour n'ont toujours pas de livreur… ») et les administrateurs et le dispatch reçoivent une
alerte, une fois par jour (`orders:unassigned`, `GET /orders/counts` → `cutoff`).

**Validation des nouvelles courses** (Réglages › Courses et livraison) : *manuelle* (file « À valider »), *automatique* (validées dès
l'enregistrement) ou *automatique avec règles*. Avec les règles, une course prévue aujourd'hui (ou « au plus vite »)
arrivée **après l'heure limite**, ou pendant que la **journée est fermée** par le dispatch, est validée pour le
lendemain ; le ramassage reste le jour même. Le marchand reçoit « course validée pour une livraison le mardi 21/10
(livraisons du jour closes depuis 16:00) » (modèle WhatsApp `course_validee_date`, préférence « incidents et reports »),
le journal de la course garde la raison, et l'application marchand l'annonce avant l'envoi. Toutes les entrées sont
concernées : application, WhatsApp, boutique en ligne, API et import.

Le bandeau en haut de la liste des courses montre l'état de la journée. « 🌙 Fermer la journée » (dispatch) vaut jusqu'à
minuit ou jusqu'à « Rouvrir la journée » ; une case permet de reporter aussi au lendemain les courses du jour pas encore
parties en livraison (celles en attente sont validées, chaque marchand est prévenu). API : `GET /delivery-day`,
`POST /delivery-day/close` (`postpone`), `POST /delivery-day/reopen`.

**Position de ramassage des marchands**, nécessaire à la carte. Elle vient de trois sources, notées sur la fiche :

- **le marchand** (« 📍 Lieu de ramassage » dans son application) : depuis sa boutique, « Je suis à ma boutique »
  prend la position GPS du téléphone (précision affichée), et le repère se corrige sur la carte. Proposé juste après
  l'inscription (« Plus tard » possible), puis rappelé sur l'accueil tant que la position manque ;
- **l'agence** (fiche du marchand) : repère sur la carte, lien Google Maps collé (même un lien court « Partager »)
  ou coordonnées écrites, ou position actuelle si l'agent est sur place (`PUT /merchants/{id}/pickup-location`,
  `POST /geo/link`) ;
- **les livreurs** : à chaque « Récupéré », la position GPS du livreur affine celle du marchand. Après 2 ramassages
  concordants (à moins de 150 m), la position est remplie automatiquement, marquée « estimée ». Elle ne remplace
  jamais une position donnée par le marchand ou l'agence. `merchants:locate` (chaque nuit, et à chaque `deploy/update.sh`)
  rattrape les marchands déjà ramassés.

**Carte des livreurs** : les ramassages sans livreur apparaissent chez le marchand (position de la course, sinon
celle du marchand), en rouge s'ils sont en retard. Le panneau liste pour chacun les trois livreurs en service les
plus proches à vol d'oiseau (distance, missions en cours, position ancienne signalée) : un clic trace les traits
vers eux, un autre assigne le ramassage (`GET /couriers/map` → `pickups`).

### Boutique en ligne du marchand (page de commande)

Chaque client ouvre, depuis **Profil › Ma boutique en ligne**, une page de commande à son nom
(`/b/{lien}`, par ex. `/b/awa-mode`) à partager dans ses statuts WhatsApp, sur Facebook, Instagram ou TikTok :

- **Le client** voit les articles (photo, prix, « Épuisé »), choisit sa commune, voit le prix de la livraison
  (« offerte » si le marchand la paie), puis commande avec son nom, son téléphone et son adresse, sans compte,
  en paiement à la livraison. Il reçoit son code de suivi.
- **La commande devient une course** (source `shop`), avec le montant à encaisser, et le marchand reçoit une
  notification « Nouvelle commande sur votre boutique ».
- **Stock** : un article dont le stock est suivi est réservé ; le colis part de l'emplacement qui a tout (un
  entrepôt en priorité, sinon chez le marchand). Un article sans stock suivi est un simple article ramassé chez le
  marchand.
- **Livraison** : le marchand choisit qui la paie pour les commandes de sa boutique (réglage propre à la boutique,
  indépendant des autres courses) : **le client** (par défaut : prix selon la commune, ajouté au total) ou **lui-même**
  (« Livraison offerte », prix retiré de ses reversements).
- **Côté marchand** : ouverture et fermeture, lien personnalisable, présentation, articles (nom, prix, photo PNG,
  JPEG ou WebP, « En boutique »). Limites : 5 commandes par minute et 30 par heure par adresse IP, champ piège à robots.

### Inscription des clients et mot de passe oublié

Depuis la page d'accueil, **Créer mon compte** (`/inscription`) : boutique, responsable, téléphone, commune et
adresse de ramassage, mot de passe. Rien n'est créé avant la vérification du numéro ; ensuite la boutique et le
compte du gérant sont **actifs aussitôt** (tarif par défaut), la personne est connectée et l'équipe reçoit une
notification « Nouvel client » (filtre **Inscrits en ligne** dans Clients).

Vérification du numéro, valable 30 minutes :

1. **WhatsApp** (gratuit, proposé en premier si WhatsApp est branché à Meta) : un bouton ouvre WhatsApp avec le
   message « Code de vérification : 123456 » vers le numéro de l'entreprise. Le webhook le reçoit et vérifie que
   l'expéditeur est bien le numéro saisi ; la page continue toute seule (elle interroge le serveur toutes les 3 s).
2. **SMS** (secours) : code à 6 chiffres, uniquement vers les mobiles ivoiriens (01, 05, 07), 5 essais par code,
   un renvoi par minute, 3 SMS par numéro et par heure ; le code n'est pas écrit dans le journal des messages.

Limites : 5 demandes par numéro et par heure, 3 par minute et 10 par heure par adresse IP. Quand les SMS sont simulés
(`SMS_DRIVER=log`), le code SMS s'affiche sur la page (**mode démonstration**). La vérification par WhatsApp n'est
proposée que si le numéro de l'entreprise est connecté.
Les inscriptions se ferment dans **Réglages › Clients** (la page d'accueil propose alors le contact WhatsApp).

**Mot de passe oublié ?** (lien sous chaque formulaire de connexion, `/mot-de-passe-oublie`) : même vérification
du numéro, puis nouveau mot de passe ; les autres appareils sont déconnectés. La réponse est identique que le
numéro ait un compte ou non.

### Comptes de démonstration

Créés par `DemoSeeder` (environnements `local` et `testing` uniquement). Mot de passe : `password`.

| Rôle | Téléphone | E-mail |
|---|---|---|
| Administrateur | 07 00 00 00 01 | admin@livraison.test |
| Dispatcher | 07 00 00 00 02 | dispatch@livraison.test |
| Caissier (caisse, reversements, paie) | 07 00 00 00 03 | caisse@livraison.test |
| Agent d'entrepôt (stock, préparation) | 07 00 00 00 06 | entrepot@livraison.test |
| Livreur | 07 00 00 00 04 | - |
| Livreuse | 07 00 00 00 05 | - |
| Client (Boutique Chic Abidjan) | 05 00 00 00 01 | boutique@livraison.test |

On se connecte avec le **téléphone** (format local `07 xx xx xx xx` ou international `+225…`) **ou** l'e-mail.

### Production

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # à chaque déploiement (idempotent)
php artisan app:install                                          # première installation : entreprise et administrateur
php artisan user:password 0700000000                             # changer un mot de passe (déconnecte le compte)
php artisan app:demo-accounts --suspend                          # suspendre les comptes au mot de passe de démonstration
```

**Mise en ligne** (Docker derrière l'Apache du serveur, HTTPS Let's Encrypt) : voir [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md).
`deploy/install.sh` installe, `deploy/update.sh` met à jour, `deploy/backup.sh` sauvegarde la base (chaque nuit).

**Mode hors connexion des livreurs** (actions gardées sur le téléphone sans réseau, envoyées au retour) : voir [docs/MODE-HORS-CONNEXION.md](docs/MODE-HORS-CONNEXION.md).

Processus (un conteneur chacun, voir `docker-compose.yml`) : `php artisan horizon`, `php artisan reverb:start`, et le planificateur (`php artisan schedule:work`).
Les notifications, la diffusion temps réel et les messages WhatsApp/SMS passent par les files `default` et `messages` : sans Horizon (ou `queue:work --queue=default,messages`), ils ne partent pas.

## Tests et qualité

```bash
php artisan test                    # SQLite en mémoire (rapide)
DB_CONNECTION=pgsql DB_DATABASE=livraison_test php artisan test   # sur PostgreSQL, comme la CI
vendor/bin/pint                     # formatage du code
npm run test:mobile                 # pages de l'administration à 360 px (serveur lancé, comptes de démonstration)
npm run build                       # build front-end
```

La CI GitHub Actions (`.github/workflows/ci.yml`) exécute Pint, les migrations aller-retour et les tests sur PostgreSQL, puis le build front-end.

## API

Toutes les routes sont préfixées par `/api/v1` et répondent en JSON au format `{ "message": "...", "errors": { ... } }` en cas d'erreur.
Authentification : en-tête `Authorization: Bearer <jeton>`.

| Méthode | Route | Accès |
|---|---|---|
| POST | `/auth/login` | public (`login` = téléphone ou e-mail, `password`, `device_name`) ; 5 essais/minute |
| GET | `/auth/me` | connecté : profil, entreprise, permissions |
| POST | `/auth/logout` | connecté : révoque le jeton de l'appareil courant |
| GET | `/shops/{lien}` · POST `/shops/{lien}/quote` · POST `/shops/{lien}/orders` | public : page de commande d'un marchand (catalogue, devis, commande) |
| GET/PATCH | `/shop` · POST/DELETE `/products/{id}/photo` | marchand (`integrations.manage` / `stock.manage`) : réglages de la boutique, photo d'un article |
| GET | `/signup` | public : inscriptions ouvertes, zones de ramassage |
| POST | `/signup` | public : demande d'inscription → `token` et état de la vérification |
| POST | `/password/forgot` | public (`phone`) → `token` et état de la vérification |
| GET | `/verifications/{token}` | public : état (`pending`, `verified`, `completed`…) ; ouvre la session à la fin d'une inscription |
| POST | `/verifications/{token}/sms` | public : envoie le code par SMS |
| POST | `/verifications/{token}/code` | public (`code`) : vérifie le code SMS |
| POST | `/password/reset` | public (`token`, `password`, `password_confirmation`) : nouveau mot de passe, session ouverte |
| GET | `/roles` | rôles que l'utilisateur peut attribuer |
| GET/POST | `/users` | `users.view` / `users.manage` (filtres `role`, `status`, `search`) |
| GET/PATCH/DELETE | `/users/{id}` | même entreprise + `users.manage` |
| GET/PATCH | `/companies/{id}` | administrateur de l'entreprise (paramètres hors statut et identifiant) |
| GET | `/zones` · POST/PATCH/DELETE `/zones/{id}` | tous · `settings.manage` (`is_shipping`, `shipping_fee_estimate` pour une zone d'expédition) ; suppression refusée pour une zone utilisée (courses, clients, entrepôts, quartiers) : la désactiver |
| CRUD | `/pricing-grids`, PUT `/pricing-grids/{id}/rules` et `/surcharges` | `settings.manage` |
| POST | `/quotes` | devis d'une course (marchand ou personnel) |
| GET/POST/PATCH | `/merchants`, POST `/merchants/{id}/users` | `merchants.view` / `merchants.manage` |
| GET/PATCH | `/couriers` | dispatch / `users.manage` |
| GET/POST/PATCH | `/orders` (filtres `queue` dont `to_prepare`, `status[]`, `merchant_id`, `hub_id`, `courier_id`, `search`…) ; création avec `items[]` et `pickup_hub_id` | personnel, ou le marchand pour ses courses |
| POST | `/orders/{id}/status` | changement de statut (règles par rôle dans `OrderWorkflow`) |
| POST | `/orders/{id}/assign`, `/orders/bulk-assign` | `orders.dispatch` (missions ramassage / livraison / retour) |
| POST | `/orders/bulk-confirm` | `orders.dispatch` : validation groupée des courses en attente (`order_ids`) ; renvoie `confirmed` et les refus course par course (`errors`) |
| POST | `/orders/{id}/notes`, `/return-request`, `/attachments` | notes, demande de retour, photo de preuve |
| GET | `/courier/missions` (`gain` : prévu selon le plan, ou acquis avec `history=1`) · POST `/courier/assignments/{id}/accept\|refuse` · PATCH `/courier/status` | livreur |
| GET | `/reports/summary`, `/incident-reasons`, `/recipients`, `/notifications` | connecté |
| GET | `/tracking/{code}` | **public** (suivi destinataire, 30 req/min) |
| GET | `/finance/cash`, `/finance/couriers/{id}/collections`, `/finance/remittances` · POST `/finance/remittances` | caisse : argent chez les livreurs et versements (`finance.view` / `finance.manage`) |
| GET | `/finance/merchants`, `/finance/merchants/{id}/ledger` · POST `.../adjustments` | soldes et grand livre (le marchand voit le sien) |
| GET/POST | `/finance/payouts`, `/finance/payouts/{id}` · POST `.../pay`, `.../cancel` | reversements aux marchands |
| GET/POST | `/finance/couriers/{id}/earnings`, `/finance/courier-payouts` · POST `.../pay` (`method` : mode de paiement, ou `compensation` pour la paie gardée sur l'encaissé), `.../cancel` | paie des livreurs |
| CRUD | `/pay-plans` (création : `name` + `template` `fixed`/`percent`/`zone`/`empty`, `copy_from`, ou `courier_id` pour un plan personnel) ; PATCH avec `rules[]` remplace les règles ; `base_salary`, `pay_period` (`weekly`, `biweekly`, `monthly`, null), `deduction_cap_percent`, `bonuses[]` ; POST `/pay-plans/simulate` (`mode` : `scenario` ou `replay`) | `settings.manage` : plans de rémunération des livreurs ; PATCH `/couriers/{id}` `pay_plan_id` (null = plan par défaut) |
| GET | `/courier/wallet` | livreur : à verser (encaissé + avances − frais payés), gains non payés et leur détail (`earnings[].detail`) |
| POST | `/finance/couriers/{id}/advances` | `finance.manage` : avance de caisse au livreur (frais de gare…) |
| POST | `/orders/{id}/expenses` · `/orders/{id}/expenses/{expense}/cancel` | frais d'une course : le livreur de la course, ou dispatch / caisse (payé par, à la charge de) ; annulation par le personnel |
| GET/PUT | `/merchants/{id}/notifications` | messages WhatsApp du marchand (événements, points quotidien et hebdomadaire, numéro) : le marchand ou `merchants.manage` |
| GET | `/field-reports` (`state`, `kind`, `courier_id`, `from`, `to`, `search`) · GET `/field-reports/counts` · POST `/field-reports/{event}/handle` · `/reopen` | `orders.dispatch` : remontées terrain et leur suivi |
| GET/POST | `/orders/{id}/courier-messages` (`body`, `reply_to_event_id`, `mark_handled`) · livreur : GET `/courier/messages` · POST `/courier/messages/{id}/ack` | `orders.dispatch` / livreur : consignes au livreur |
| GET | `/couriers/{id}/track?date=` | `orders.dispatch` : trajet d'une journée (points, étapes, distance) |
| GET | `/couriers/map` | `orders.dispatch` : positions et missions en cours des livreurs (diffusion `courier.location` sur `company.{id}`) |
| GET/POST/DELETE | `/api-keys` | `integrations.manage` : clés de l'API publique (le marchand les siennes, l'administration avec `merchant_id`) |
| GET/POST/PATCH/DELETE | `/webhooks` · POST `/webhooks/{id}/test`, `/webhooks/{id}/secret` · GET `/webhooks/{id}/deliveries` · POST `/webhook-deliveries/{id}/redeliver` | `integrations.manage` : adresses webhook, test, journal, renvoi |
| GET | `/orders/import/template` · POST `/orders/import` (`file`, `dry_run`, `skip_invalid`, `merchant_id`) | `orders.create` : import CSV/Excel |
| — | `/api/public/v1/…` (hors `/v1`) | **clé API** : voir `/developpeurs/api` |
| GET | `/hubs` · POST/PATCH `/hubs/{id}` | connecté (liste) · `settings.manage` : entrepôts |
| GET/POST/PUT/DELETE | `/products` | marchand (ses produits) ou personnel ; création et modification : `stock.manage` |
| GET/POST | `/stock/movements` | journal ; POST `{product_id, hub_id?, action: receipt\|withdrawal\|count, quantity}` : le marchand chez lui, le personnel dans les entrepôts |
| GET/POST/PUT/DELETE | `/storage-contracts` | marchand (les siens) ; gestion : `settings.manage` ou `finance.manage` |
| GET/PUT | `/whatsapp/settings` · POST `/whatsapp/test`, `/whatsapp/templates/sync` | `settings.manage` : numéro Meta, options, test, approbation des modèles |
| GET | `/messages` · POST `/messages/{id}/retry` · GET `/orders/{id}/messages` | `orders.dispatch` : journal des messages WhatsApp/SMS, renvoi d'un échec |
| POST | `/whatsapp/simulate` | `settings.manage` : simulateur de conversation (`from`, `text` ou `button_id`) ; renvoie les réponses du bot |
| GET/POST | `/api/webhooks/whatsapp` (hors `/v1`) | **public**, signé par Meta (`X-Hub-Signature-256`) : accusés de réception et messages reçus |

Temps réel (Reverb) : canaux privés `company.{id}` (personnel), `merchant.{id}` (marchand) et `App.Models.User.{id}` (notifications), événement `order.changed`.

## Architecture

- Chaque utilisateur appartient à l'entreprise (`users.company_id`) ; le schéma garde la colonne `company_id` partout.
- Les modèles métier utilisent le trait `App\Models\Concerns\BelongsToCompany` : filtrage automatique par entreprise et `company_id` rempli à la création.
- Les rôles et permissions sont définis dans `App\Enums\Role` et `App\Enums\Permission`, puis synchronisés en base par `RolesAndPermissionsSeeder`.
