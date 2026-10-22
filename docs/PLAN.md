# Plan d'exécution : plateforme de gestion de livraison pour e-commerçants

> Base existante : Laravel 12 (API + Sanctum) et SPA Vue 3 / Pinia / Tailwind.
> Schéma complet de la base de données : [`database.dbml`](./database.dbml), importable tel quel sur https://dbdiagram.io.

---

## 1. Reformulation du besoin

| Acteur | Ce qu'il fait |
|---|---|
| **E-commerçant** (marchand) | Crée des courses (dashboard, mobile, WhatsApp, API), suit leurs statuts, reçoit les alertes, consulte ses points (livrés / non livrés / reportés…), gère son stock. |
| **Administrateur / dispatcher** | Reçoit les nouvelles courses en temps réel, assigne le **ramassage** puis la **livraison** (à deux livreurs différents si besoin), suit les incidents et gère la finance. |
| **Livreur** | Reçoit ses ramassages et ses livraisons dans son application, met à jour les statuts, ajoute des notes et des preuves de livraison, encaisse. |
| **Destinataire** *(acteur ajouté)* | Reçoit un lien de suivi et un code de livraison, et peut confirmer sa disponibilité. |

Deux produits distincts :
1. **Plateforme de l'entreprise de livraison** : back-office admin, espace marchand et application livreur.
2. **Application marchand indépendante**, plus riche (boutique, CRM, stock avancé). Elle se connecte par API à **n'importe quelle** entreprise de livraison.

---

## 2. Insuffisances du cahier des charges et corrections proposées

Ces points ne figurent pas dans votre description. Ils sont pourtant indispensables au fonctionnement réel d'une société de livraison à Abidjan.

### 2.1 Paiement à la livraison (cash / contre-remboursement) : **critique**
La majorité des colis e-commerce en Côte d'Ivoire sont payés à la livraison. Il faut donc gérer :
- le **montant à encaisser** sur chaque course (prix des articles, avec ou sans frais de livraison) ;
- **qui paie la livraison** : le marchand (frais déduits de ses reversements) ou le destinataire (frais ajoutés au montant encaissé) ;
- l'encaissement par le livreur, en espèces, Wave, Orange Money, MTN ou Moov ;
- le **versement du livreur** à la caisse en fin de journée, avec écart constaté ;
- le **reversement au marchand** (relevé, validation, paiement, référence de transaction) ;
- un **grand livre par marchand** (solde = encaissements − frais de livraison − frais de stockage − reversements).

Sans ce module, le « point détaillé » demandé ne peut pas être fiable.

### 2.2 Cycle de vie complet du colis
Les trois statuts proposés (récupéré, en chemin, livré) ne suffisent pas. Comme le ramasseur et le livreur peuvent être deux personnes différentes, le colis passe en général par un **dépôt (hub)**. Il faut aussi gérer les échecs, les reports et les retours. Voir la machine à états en §4.

### 2.3 Échecs, reports et retours
- **Motifs d'incident normalisés** (liste paramétrable) plutôt que du texte libre : *destinataire injoignable, adresse introuvable, refus du colis, report demandé (avec date), colis endommagé, paiement refusé…* Le texte libre reste possible en complément. Ces codes rendent les statistiques exploitables.
- Un **nombre maximal de tentatives** (par exemple 3) au-delà duquel le colis part automatiquement en retour.
- **Frais de retour** et frais de tentative échouée, paramétrables.
- Retour **au marchand** ou **au stock de l'entreprise**, avec réintégration automatique du stock.
- **Livraison partielle** : le client ne prend que 1 article sur 3.

### 2.4 Preuve de livraison (anti-fraude)
- **Code OTP** à 4 chiffres envoyé au destinataire par WhatsApp ou SMS, que le livreur saisit pour valider la livraison.
- Photo du colis remis et/ou signature.
- Position GPS et horodatage enregistrés à chaque changement de statut.
- **Scan QR code** de l'étiquette au ramassage, au dépôt et à la remise : on sait à tout moment qui détient physiquement le colis.

### 2.5 Tarification plus souple
- Une **grille zone → zone** (Cocody → Cocody = 1 000 F, Cocody → Yopougon = 1 500 F) avec option **symétrique** pour ne pas tout saisir deux fois.
- Des **grilles négociées par marchand** (gros volumes).
- Des **suppléments** : poids ou volume, express, fragile, hors Abidjan, retour, tentative échouée.
- Le prix est **toujours calculé côté serveur** puis **figé sur la course** au moment de sa création : une modification de tarif ne change pas les courses passées.
- Des **sous-zones** facultatives (commune → quartier), par exemple Cocody Angré et Cocody Riviera.

### 2.6 Adresses imprécises
À Abidjan, l'adresse seule ne suffit pas. Il faut prévoir un **repère** (« face pharmacie X »), un second numéro du destinataire, la **position GPS** (partage de localisation WhatsApp ou épingle sur carte) et un **carnet de destinataires** réutilisable par marchand.

### 2.7 Rôles et multi-entreprise
- **Prévoir le multi-entreprise dès le départ** (`company_id` sur toutes les tables). L'application marchand indépendante doit se connecter à « toute entreprise de livraison ». Votre plateforme pourra ainsi être **vendue en SaaS** à d'autres sociétés de livraison sans tout réécrire.
- Plusieurs rôles : `super_admin` (vous, l'éditeur), `admin`, `dispatcher`, `caissier`, `livreur`, `marchand_proprietaire`, `marchand_employe`.
- Les comptes livreurs sont créés **par l'admin** et non par auto-inscription. Le code actuel permet à n'importe qui de s'inscrire comme livreur.

### 2.8 Dispatch
- **Assignation groupée** : sélectionner 15 courses de Yopougon et les donner à un livreur en un clic.
- **Suggestion automatique** du livreur selon la zone, la charge et la disponibilité. L'assignation automatique complète vient en v2.
- Le livreur peut **accepter ou refuser** une mission, avec un délai.
- Historique des **réassignations** : chaque assignation est conservée et jamais écrasée.

### 2.9 Contraintes WhatsApp (API officielle Meta Cloud)
- Hors de la fenêtre de 24 h après le dernier message du client, on ne peut envoyer que des **modèles (templates) pré-approuvés par Meta**. Il faut prévoir la gestion des templates.
- **Opt-in** obligatoire des destinataires, et coût par conversation : prévoir une refacturation ou un forfait.
- Pour que chaque marchand et chaque entreprise utilise **son propre numéro**, passer par l'**Embedded Signup** de Meta (statut *Tech Provider*) ou par un BSP (360dialog, Twilio…).
- Création de course par WhatsApp : utiliser les **WhatsApp Flows** (formulaires natifs dans WhatsApp) plutôt que d'analyser du texte libre. En complément, une analyse par IA des messages libres, **avec confirmation obligatoire** avant création.
- Prévoir un **SMS de repli** si le destinataire n'a pas WhatsApp.

### 2.10 Rémunération des livreurs
Salaire fixe, commission par course (ramassage et livraison distincts) ou système mixte. Il faut calculer automatiquement les gains par course.

### 2.11 Autres ajouts recommandés
- **Lien de suivi public** pour le destinataire, qui réduit fortement les appels.
- **Import Excel/CSV** de courses en masse.
- **Étiquettes imprimables** avec QR code.
- **Mode hors ligne** de l'application livreur (réseau instable) avec synchronisation différée.
- **Géolocalisation des livreurs** en temps réel sur la carte du dispatcher.
- **Conformité** : loi ivoirienne n° 2013-450 sur les données personnelles et déclaration à l'ARTCI.
- **Journal d'audit** des actions sensibles (modification de tarif, annulation, ajustement de caisse).

---

## 3. Architecture technique

```
                    ┌──────────────────────────────────────────┐
  Admin (Vue SPA) ──┤                                          │
  Marchand (Vue)  ──┤   API Laravel 12 (REST /api/v1)          │── PostgreSQL 16 (+PostGIS)
  Livreur (mobile)──┤   Auth : Sanctum                         │── Redis (cache, files, verrous)
  App indépendante ─┤   Temps réel : Laravel Reverb (WebSocket)│── Stockage S3 (photos, preuves)
  WhatsApp (Meta) ──┤   Files : Horizon / Queue workers        │
                    └───────────────┬──────────────────────────┘
                                    │ Jobs asynchrones
             ┌──────────────────────┼─────────────────────────┐
        WhatsApp Cloud API     FCM (push)          SMS (repli)     Webhooks sortants
```

| Couche | Choix recommandé | Justification |
|---|---|---|
| Back-end | **Laravel 12** (déjà en place) | API, files, événements, broadcasting natifs |
| Base de données | **PostgreSQL 16 + PostGIS** (MySQL 8 acceptable) | JSONB, requêtes géographiques (zone d'un point GPS), contraintes robustes |
| Temps réel | **Laravel Reverb** + Laravel Echo | WebSocket auto-hébergé et gratuit, intégré à Laravel |
| Files d'attente | **Redis + Horizon** | Envois WhatsApp, rapports, webhooks, avec relances automatiques |
| Admin et marchand web | **Vue 3 + Pinia + Tailwind** (déjà en place) | Une seule SPA, routage par rôle |
| App livreur | **Vue 3 + Ionic + Capacitor** | Réutilise les compétences Vue ; GPS en arrière-plan, caméra, scan QR, push, SQLite hors ligne |
| App marchand mobile | Même base Ionic/Capacitor (ou PWA au départ) | |
| Push | Firebase Cloud Messaging | |
| WhatsApp | Meta WhatsApp Cloud API (direct ou via BSP) | |
| Paiement mobile | CinetPay / API Wave (v2) | Encaissements et reversements |
| Rôles et permissions | `spatie/laravel-permission` | Remplace la colonne `role` unique |
| Audit | `spatie/laravel-activitylog` | Audit des entités hors courses |
| Hébergement | VPS (Hetzner/OVH) + Docker, ou Laravel Forge | |

### Principes de conception
1. **Multi-entreprise** : un `company_id` sur toutes les tables métier et un *global scope* Eloquent qui filtre automatiquement.
2. **Journal immuable** : chaque action sur un colis crée une ligne dans `order_events`, jamais modifiée ni supprimée. C'est la réponse à « voir les différentes actions ainsi que les personnes qui interviennent sur un colis ».
3. **Assignations historisées** : `order_assignments` garde toutes les missions (ramassage, livraison, retour, transfert), y compris refusées ou réassignées.
4. **Machine à états** côté serveur : un statut ne peut passer qu'aux statuts autorisés. Chaque transition émet un événement Laravel.
5. **Instantanés** : prix, adresses et contacts sont copiés sur la course au moment de sa création.
6. **Pipeline de notifications unique** : chaque événement est traduit en notifications sur les canaux voulus (in-app, push, WhatsApp, SMS, webhook) selon les préférences, puis journalisé dans `outbound_messages`.
7. **Grand livre comptable** : les soldes ne sont jamais stockés « en dur ». On les calcule à partir des écritures (`merchant_ledger_entries`).

---

## 4. Cycle de vie d'une course (machine à états)

```
                      ┌────────────── cancelled (avant ramassage)
                      │
pending ──► confirmed ──► pickup_assigned ──► pickup_in_progress ──► picked_up
   │            │                                                       │
   │            └──(stock chez l'entreprise)──► ready_at_hub ◄── at_hub ◄┘ (dépôt, facultatif)
   │                                                │
   │                                                ▼
   │                                       delivery_assigned ──► out_for_delivery ──► delivered ✔
   │                                                ▲                    │
   │                                                │                    ▼
   │                                           rescheduled ◄──── delivery_failed
   │                                                                     │ (refus / max tentatives)
   │                                                                     ▼
   └── rejected                                          return_assigned ──► returning ──► returned
```

| Statut | Libellé affiché | Qui déclenche |
|---|---|---|
| `pending` | En attente de validation | Marchand / WhatsApp / API |
| `confirmed` | Validée | Admin (ou validation automatique paramétrable) |
| `pickup_assigned` | Ramassage assigné | Admin / dispatcher |
| `pickup_in_progress` | Livreur en route pour le ramassage | Livreur |
| `picked_up` | **Récupéré** | Livreur (scan QR) |
| `at_hub` | Au dépôt | Livreur / agent de dépôt (scan) |
| `ready_at_hub` | Préparé au dépôt (stock entreprise) | Agent de dépôt |
| `delivery_assigned` | Livraison assignée | Admin / dispatcher |
| `out_for_delivery` | **En chemin** | Livreur |
| `delivered` | **Livré** | Livreur (code OTP + photo) |
| `partially_delivered` | Livré partiellement | Livreur |
| `delivery_failed` | Échec de livraison (motif obligatoire) | Livreur |
| `rescheduled` | Reporté au JJ/MM | Livreur / marchand / admin |
| `return_assigned` / `returning` / `returned` | Retour | Admin / livreur |
| `cancelled` | Annulée | Marchand (avant ramassage) / admin |
| `rejected` | Refusée par l'entreprise | Admin |

Règles clés :
- Le livreur de ramassage et le livreur de livraison sont deux assignations distinctes. Un même livreur peut faire les deux : il passe alors directement de `picked_up` à `out_for_delivery`.
- `delivery_failed` exige un motif ; `rescheduled` exige une date.
- Chaque transition émet `OrderStatusChanged`, qui entraîne : une ligne `order_events`, un broadcast Reverb (admin et marchand), une notification WhatsApp au marchand (et au destinataire si pertinent) et un webhook sortant.

---

## 5. Base de données

Le schéma complet (≈ 45 tables, colonnes, index, relations) est dans [`database.dbml`](./database.dbml). Vue d'ensemble par domaine :

| Domaine | Tables |
|---|---|
| **Organisation et accès** | `companies`, `users`, `roles`/`permissions` (spatie), `devices`, `personal_access_tokens` |
| **Acteurs** | `merchants`, `merchant_users` (via `users.merchant_id`), `couriers`, `courier_zones`, `hubs` |
| **Géographie et tarifs** | `zones`, `pricing_grids`, `pricing_rules`, `pricing_surcharges`, `addresses`, `recipients` |
| **Courses** | `orders`, `order_items`, `order_assignments`, `order_events`, `order_attachments`, `incident_reasons`, `order_imports`, `courier_locations` |
| **Finance** | `cash_collections`, `courier_remittances`, `merchant_ledger_entries`, `merchant_payouts`, `courier_earnings`, `courier_payouts` |
| **Stock** | `products`, `stock_locations`, `stock_levels`, `stock_movements`, `storage_contracts` |
| **Notifications** | `notifications` (Laravel), `notification_preferences`, `outbound_messages`, `scheduled_reports` |
| **WhatsApp** | `whatsapp_accounts`, `whatsapp_templates`, `inbound_messages`, `whatsapp_sessions` |
| **API et intégrations** | `api_keys`, `webhook_subscriptions`, `webhook_deliveries` |
| **Système** | `settings`, `activity_log`, `jobs`, `failed_jobs`, `cache` |

### 5.1 Tables centrales (extrait)

**`orders`** (une course = un colis)
- Identité : `id`, `company_id`, `merchant_id`, `tracking_code` (unique, ex. `CI-2610-00123`), `merchant_reference`, `source` (`dashboard|mobile|whatsapp|api|import|admin`), `external_id` (identifiant côté app indépendante).
- Ramassage : `pickup_type` (`merchant_address|company_stock`), `pickup_hub_id`, `pickup_zone_id`, `pickup_address`, `pickup_landmark`, `pickup_contact_name`, `pickup_phone`, `pickup_lat/lng`, `pickup_scheduled_at`.
- Destinataire : `recipient_id`, `recipient_name`, `recipient_phone`, `recipient_phone2`, `delivery_zone_id`, `delivery_address`, `delivery_landmark`, `delivery_lat/lng`, `delivery_scheduled_date`, `delivery_time_slot`.
- Colis : `description`, `package_size`, `weight_kg`, `declared_value`, `is_fragile`, `is_express`.
- Argent : `delivery_fee` (figé), `surcharges_total`, `fee_payer` (`merchant|recipient`), `items_amount`, `cod_amount` (montant à encaisser), `collected_amount`.
- Suivi : `status`, `pickup_courier_id`, `delivery_courier_id` (dénormalisés pour la performance), `attempts_count`, `max_attempts`, `last_incident_reason_id`, `delivered_at`, `otp_hash`, `cancel_reason`.
- Index : `(company_id, status)`, `(merchant_id, created_at)`, `(delivery_courier_id, status)`, `tracking_code` unique.

**`order_assignments`** (qui doit faire quoi, historisé)
`order_id`, `courier_id`, `type` (`pickup|delivery|return|transfer`), `status` (`assigned|accepted|refused|in_progress|completed|failed|cancelled`), `assigned_by`, `assigned_at`, `accepted_at`, `started_at`, `completed_at`, `refusal_reason`, `sequence` (ordre dans la tournée).

**`order_events`** (journal immuable : la traçabilité demandée)
`order_id`, `assignment_id`, `type` (`created|status_changed|assigned|unassigned|note|incident|rescheduled|scan|cod_collected|proof_added|message_sent|edited`), `from_status`, `to_status`, `incident_reason_id`, `rescheduled_to`, `note`, `actor_type` (`user|system|whatsapp|api`), `actor_id`, `actor_role`, `lat`, `lng`, `meta` (JSON), `visible_to_merchant` (bool), `created_at`.
Pas de `updated_at`, pas de suppression.

### 5.2 Exemple de grille tarifaire

| Origine → destination | Prix |
|---|---|
| Cocody → Cocody | 1 000 F |
| Cocody → Yopougon | 1 500 F |
| Cocody → Bingerville | 2 000 F |

`pricing_rules(grid_id, origin_zone_id, destination_zone_id, price, is_symmetric)`, avec unicité sur `(grid_id, origin_zone_id, destination_zone_id)`.

Algorithme : grille spécifique du marchand si elle existe, sinon grille par défaut de l'entreprise ; on cherche la règle exacte (sous-zone), puis la zone parente, puis la règle symétrique ; on ajoute les suppléments ; on fige le résultat sur la course.

### 5.3 Gestion du stock
- `stock_locations` : chez le marchand, dans un entrepôt de l'entreprise, ou « en transit » chez un livreur.
- Quand une course est créée avec des produits : **réservation** (`quantity_reserved += n`).
- Quand elle est livrée : **sortie définitive** (`on_hand -= n`, `reserved -= n`).
- Quand elle est annulée ou retournée : **libération** ou **réintégration**.
- Chaque mouvement est tracé dans `stock_movements`. `stock_levels` n'est qu'un cache, recalculable.
- `storage_contracts` : gratuit, forfait mensuel, ou par unité et par jour. Un job mensuel génère les écritures de frais de stockage dans le grand livre.
- Si le stock est chez l'entreprise, **aucun ramassage** : la course démarre à `ready_at_hub`.

---

## 6. Modules fonctionnels par application

### 6.1 Back-office administrateur
- **Tableau de bord temps réel** : nouvelles courses (son et badge), incidents, courses en retard, livreurs actifs sur une carte, encaissements du jour.
- **Dispatch** : vue Kanban par statut et filtre par zone ; assignation individuelle ou groupée ; suggestions de livreurs ; réassignation.
- **Fiche colis** : chronologie complète (`order_events`) avec acteur, heure, GPS, photos et notes.
- **Référentiels** : zones, grilles tarifaires, motifs d'incident, dépôts, templates WhatsApp.
- **Marchands** : fiche, grille négociée, solde, relevés, clés API, connexion WhatsApp.
- **Livreurs** : comptes, zones, véhicule, gains, historique, versements.
- **Caisse** : réception des versements livreurs, écarts, reversements marchands.
- **Rapports** : volumes, taux de réussite, motifs d'échec, délais moyens, chiffre d'affaires par zone, marchand et livreur.

### 6.2 Espace marchand (web et mobile)
- Création de course (formulaire, carnet de destinataires, ajout de produits du stock, prix affiché instantanément).
- Import Excel, impression des étiquettes.
- Liste et suivi des courses, avec alertes en temps réel sur les notes et incidents ; **action rapide** sur un incident : *rappeler le client, modifier l'adresse, reporter, annuler, demander un retour*.
- **Point détaillé** : livrés, non livrés, reportés, retournés, en cours ; montants encaissés, frais, net à reverser ; export PDF/Excel ; envoi programmé sur WhatsApp.
- Stock : produits, niveaux par emplacement, mouvements, alertes de stock bas.
- Paramètres : notifications, numéro WhatsApp, clés API, webhooks.

### 6.3 Application livreur
- Liste du jour, séparée en **À ramasser** et **À livrer**, triable par zone ou par proximité ; carte et itinéraire (lien Google Maps).
- Accepter ou refuser une mission.
- Changement de statut en un tap, scan QR, saisie du code OTP, photo, encaissement (montant et mode).
- **Note ou incident** : motif dans une liste, texte libre, date de report. Envoi immédiat à l'admin et au marchand.
- Appel ou WhatsApp du destinataire en un clic.
- Mode hors ligne (file d'actions synchronisée au retour du réseau).
- Récapitulatif : courses du jour, cash en main, gains.
- Envoi de la position GPS toutes les 30 à 60 s pendant le service.

### 6.4 Destinataire (sans compte)
- Page publique `/suivi/{tracking_code}` : statut, créneau, livreur assigné, bouton « je ne suis pas disponible / reporter ».
- Messages WhatsApp : « votre colis est en route, code de livraison : 4821 ».

---

## 7. Intégration WhatsApp

### 7.1 Sortant (notifications)
| Événement | Destinataire | Template |
|---|---|---|
| Course validée | Marchand | `course_confirmee` |
| Colis récupéré | Marchand | `colis_recupere` |
| En chemin | Destinataire (montant, code de livraison, lien de suivi) | `colis_en_route` |
| Livré | Marchand | `colis_livre` |
| Échec de livraison ou de ramassage, report | Marchand (l'admin est alerté dans l'application) | `incident_livraison` |
| Point quotidien / hebdomadaire | Marchand | `rapport_activite` |
| Reversement effectué | Marchand | `reversement_effectue` |
| Point de la semaine (lundi matin) | Administrateurs de l'entreprise | `point_semaine_gerant` |

Fonctionnement : le journal de la course appelle `OrderMessages`, qui passe par `Messenger` : le message est inscrit dans `outbound_messages`, puis le job `SendOutboundMessage` l'envoie (file `messages`, 3 tentatives pour les erreurs passagères). Les webhooks de statut Meta (envoyé, reçu, lu, échec) mettent à jour `outbound_messages`. En cas d'échec définitif (par exemple numéro sans WhatsApp), un SMS de même texte part une seule fois. Le marchand choisit ses messages dans son application ; les textes des modèles sont dans `App\Enums\WhatsAppTemplate` et affichés, prêts à copier, dans Paramètres > WhatsApp.

### 7.2 Entrant (création de course par WhatsApp)
1. Meta appelle `POST /api/webhooks/whatsapp` (champ `messages`) ; la signature `X-Hub-Signature-256` est vérifiée, le compte est retrouvé par `phone_number_id` et chaque message est inscrit une seule fois dans `inbound_messages` (déduplication par identifiant Meta), puis traité par le job `ProcessInboundWhatsApp` (file `messages`).
2. Le numéro de l'expéditeur est identifié : marchand actif connu (via `users.phone` d'un compte marchand, `merchants.whatsapp_phone` ou `merchants.phone`), sinon message de refus.
3. Le marchand écrit librement, transfère la commande de son client ou suit le menu (boutons) :
   - **Analyse** du message (`OrderMessageParser`) : par **règles** par défaut (lignes « Nom : … / Tél : … », numéros ivoiriens, montants « 12 500 F » ou « 15k », communes et quartiers, repère, qui paie la livraison) ; par **Claude** quand `ANTHROPIC_API_KEY` est renseignée, avec retour automatique aux règles en cas d'erreur.
   - Ce qui manque est **demandé une question à la fois** (téléphone, commune, montant à encaisser ; « déjà payé » = 0) ; une commune ambiguë est proposée en boutons.
   - **Récapitulatif chiffré** (prix, qui paie, montant à encaisser) avec les boutons **Confirmer / Modifier / Annuler**. Rien n'est créé sans confirmation.
4. La course est créée avec `source = whatsapp` ; le marchand reçoit le numéro de suivi, le code de livraison, le montant à encaisser et le lien de suivi.
5. Autres commandes : envoyer un numéro de suivi (`LV-…`) donne le statut du colis ; « point » donne le point du jour.
6. L'état de la conversation est conservé dans `whatsapp_sessions` (expiration 30 min).

Le compte WhatsApp peut appartenir à l'**entreprise** (un numéro unique pour tous les marchands) ou au **marchand**. Les deux sont prévus via `whatsapp_accounts.owner_type`.

---

## 8. API publique et application marchand indépendante

### 8.1 API publique de la plateforme de livraison (`/api/v1`)
- Authentification par **clé API** par marchand (`api_keys` : préfixe visible et hash stocké, portées `orders:write`, `orders:read`, `stock:read`…).
- Endpoints (`/api/public/v1`) : `GET /zones`, `GET /hubs`, `POST /quotes` (devis), `GET|POST /orders`, `GET /orders/{tracking}` (avec son historique), `POST /orders/{tracking}/cancel`, `GET /reports/summary`, `GET /products`.
- En-tête `Idempotency-Key` obligatoire sur `POST /orders` (pas de doublon en cas de relance réseau).
- **Webhooks sortants** signés HMAC (`order.created`, `order.status_changed`, `order.incident`, `payout.paid`, `stock.low`), avec relances exponentielles (`webhook_deliveries`).
- Documentation OpenAPI écrite à la main (contrat stable, `public/docs/openapi.yaml`), limitation de débit et versionnement par préfixe (`/v1`).

### 8.2 Application marchand indépendante
Produit séparé, avec sa **propre base de données**. Elle consomme l'API ci-dessus.
- Table `carrier_connections` : entreprise de livraison, URL de base, clé API chiffrée, correspondance de ses zones vers celles du transporteur.
- Le marchand peut connecter **plusieurs** entreprises de livraison et choisir la moins chère ou la plus rapide par course (comparateur de devis via `POST /quotes`).
- Fonctions en plus : boutique en ligne et catalogue, CRM clients, commandes multicanales, stock multi-entrepôts, comptabilité simple, statistiques de vente.
- **Standardiser le contrat d'API** (un « protocole » publié) pour que d'autres entreprises de livraison puissent l'implémenter. C'est là que se trouve l'effet réseau du produit.

---

## 9. Plan d'exécution par phases

Hypothèse d'équipe : 2 développeurs full-stack, 1 développeur mobile et 1 chef de projet/testeur à temps partiel. Durées indicatives.

### Phase 0 : fondations (1 à 2 semaines) ✅ *réalisée*
- [x] Corriger les bugs bloquants du code existant (voir §11).
- [x] Passer à PostgreSQL ; installer Redis, Horizon, Reverb et spatie/permission.
- [x] Multi-entreprise : table `companies`, `company_id` et *global scope* (`BelongsToCompany`).
- [x] Rôles et permissions (`App\Enums\Role`, `App\Enums\Permission`) ; connexion par téléphone **ou** e-mail, limitée à 5 essais/minute.
- [x] Structure de l'API `/api/v1`, Form Requests, API Resources, gestion uniforme des erreurs (JSON en français).
- [x] Gestion des entreprises (super admin) et des utilisateurs (admin), avec canal temps réel privé par entreprise.
- [x] Front-end : routeur par rôle, client HTTP, écran de connexion.
- [x] CI GitHub Actions (Pint, migrations et tests sur PostgreSQL, build Vite) ; dépendances mises à jour (0 alerte de sécurité).
- [ ] Environnements de préproduction et de production (hébergement à choisir).
- [ ] **À faire par vous** : lancer la vérification du compte Meta Business (nécessaire en phase 3).

Choix faits pendant la phase 0 :
- **Un compte = une entreprise** : téléphone et e-mail sont uniques sur toute la plateforme, ce qui permet de se connecter sans choisir d'entreprise. Une personne travaillant pour deux entreprises aura deux comptes (deux numéros).
- **Plus d'inscription publique** : les comptes du personnel et des livreurs sont créés par l'admin. L'inscription des e-commerçants arrivera avec le module marchands (phase 1).
- **Jetons d'API valables 30 jours** (`SANCTUM_EXPIRATION`), pour que les livreurs ne se reconnectent pas chaque jour. La suspension d'un compte ou le changement de mot de passe déconnecte tous ses appareils.

### Phase 1 : MVP opérationnel (5 à 6 semaines) ✅ *réalisée : premier jalon utilisable*
- [x] Zones (commune › quartier), grilles tarifaires (matrice, symétrie, grilles négociées), suppléments, calcul de prix testé.
- [x] Marchands et création de leurs comptes ; suspension.
- [x] Livreurs : profil créé avec le compte, zones desservies, disponibilité, position.
- [x] Création de course (marchand et admin) avec devis en direct, carnet de destinataires, code de suivi, étiquette QR 10 × 15 cm.
- [x] Machine à états, journal immuable `order_events`, missions `order_assignments` (ramassage et livraison séparés, acceptation/refus, réassignation, assignation groupée).
- [x] Dashboard admin : files d'attente du dispatch, alertes temps réel (Reverb), fiche colis avec chronologie complète.
- [x] Application livreur (web mobile) : missions, statuts en un geste, motifs d'incident, report daté, notes, photo, code de livraison, appel/WhatsApp/itinéraire, GPS.
- [x] Notifications temps réel (cloche + toasts) pour marchand, dispatchers et livreurs ; page de suivi publique sans données personnelles.
- [x] Point marchand : compteurs par état, montants encaissés, frais retenus, net à reverser.

Choix faits pendant la phase 1 :
- **Échec du ramassage** : la course revient à « Validée » (motif obligatoire) pour être réassignée, sans statut supplémentaire.
- **Code de livraison** : généré pour chaque course et visible du marchand (jamais du livreur). Son contrôle est **activable par entreprise** (`require_delivery_code`, désactivé par défaut) ; depuis la phase 3, le destinataire le reçoit sur WhatsApp quand le colis part.
- **Remontées terrain** (ajoutées après la phase 7) : notes et problèmes des livreurs signalés en direct au dispatch avec son et notification du bureau ; journal consultable et filtrable, suivi « traité par » (`field_report_reviews`, le journal des courses reste immuable). Réponse au livreur (`courier_messages`, accusés « lu » et « compris ») et relance automatique des problèmes sans suite (`field_report_reminders`, délai réglable par l'entreprise).
- **Colis chez les livreurs** (ajoutés après la phase 7) : garde du colis suivie sur la course (`held_by_courier_id`, `held_since`), colis rendus cochés lors du point de caisse (trace `courier_remittances.parcels`), point bloqué tant qu'une course est « En chemin », écran de suivi et alerte au dispatch au-delà de `companies.parcel_hold_alert_hours` (24 h par défaut, `parcels:overdue`).
- **Suite des colis non livrés** (ajoutée après la phase 7) : files « À décider » (échecs sans suite) et « Reportées » (date future) ; décision en un clic `POST /orders/{id}/decision` (relivrer à une date, retour au marchand, remise en stock), marchand prévenu par WhatsApp. Rappel du matin au dispatch des reports du jour (`orders:due-today`).
- **Bon de retour groupé** (ajouté après la phase 7) : `return_slips` (colis d'un marchand rendus ensemble par un livreur), lien WhatsApp au marchand, remise signée à l'écran ou photographiée qui clôture les retours, bon imprimable.
- **Colis perdu** (ajouté après la phase 7) : statut final `lost`, déclaration réservée à l'administrateur (`POST /orders/{id}/lost`), indemnité marchand (`lost_compensation` au grand livre), retenue livreur (`lost_parcel` sur la paie), alerte « peut-être perdu » aux administrateurs à trois fois le délai de garde.
- **Notifications push des livreurs** (ajoutées après la phase 7) : Web Push natif (VAPID ES256 + chiffrement `aes128gcm`, sans dépendance), envoyé pour les nouvelles missions et les consignes de l'agence (`push_subscriptions`, un abonnement par appareil, purgé sur 404/410). Clés générées par `php artisan webpush:vapid`.
- **Notifications des e-commerçants** (ajoutées après la plateforme) : les messages au marchand passent en notification push (canal `push` du journal `outbound_messages`) quand un de ses comptes a un appareil abonné et que l'entreprise l'a gardé activé (`companies.merchant_push_first`) ; une notification reçue par aucun appareil repart sur WhatsApp (`fallback_for_id`), puis SMS. Économise le coût des modèles WhatsApp utilitaires.
- **Carte des livreurs** (ajoutée après la phase 7) : dernière position et missions en cours, en direct ; position envoyée toutes les 30 s pendant le service. **Historique des trajets** (`courier_locations`, 90 jours) : tracé de la journée, étapes des courses, distance ; points imprécis (> 150 m), à l'arrêt ou aberrants (> 130 km/h) écartés.
- **Dépôts (hubs)** : le statut « Au dépôt » existe, mais la gestion de plusieurs dépôts est reportée (une seule entreprise = un dépôt implicite).
- **Adresses** : pas de table `addresses` générique ; l'adresse de ramassage est portée par le marchand et copiée sur chaque course.
- Reportés à la phase 2 avec la caisse : encaissement détaillé (`cash_collections`), versements livreurs et reversements.

### Phase 2 : argent et paiement à la livraison (3 semaines) ✅ *réalisée*
- [x] Montant à encaisser, payeur des frais, encaissement par le livreur (espèces ou mobile money, payé au livreur ou directement sur le compte de l'entreprise).
- [x] Versements livreurs à la caisse (total ou partiel), écarts ; un manque est retenu sur la paie du livreur.
- [x] Grand livre marchand (écritures non modifiables), relevés imprimables / PDF, reversements (à payer → payé), ajustements.
- [x] Gains et paie des livreurs (commission par ramassage, livraison et retour ; primes et retenues).
- [x] Point détaillé complet ; les frais affichés proviennent du grand livre.

Règles retenues en phase 2 :
- **On ne reverse que l'argent arrivé en caisse** : les écritures d'une course dont l'argent est encore chez le livreur restent « en attente » et passent au reversement suivant.
- **Frais retenus sur toute livraison**, quel que soit le payeur : payés par le client, ils sont inclus dans l'encaissement.
- **Retour** : frais facturés au marchand selon un pourcentage paramétrable (`return_fee_percent`, 100 % par défaut).
- Un reversement peut être **négatif** (frais supérieurs aux encaissements, ex. colis prépayés) : le relevé indique alors le montant dû par le marchand.

### Applications mobiles PWA marchand et livreur ✅ *réalisée (avant la phase 4)*
- [x] Deux applications installables (`/marchand` vert, `/livreur` bleu) : manifeste, icônes et couleur propres à chacune, distinctes du back-office.
- [x] Interface mobile : barre d'onglets en bas, gros boutons, choix par pastilles plutôt que listes déroulantes, panneaux du bas pour les actions.
- [x] Marchand : accueil (alertes, chiffres du jour et du mois), nouvelle course en 4 étapes avec récapitulatif chiffré et partage WhatsApp du suivi, suivi de course avec frise, paiements.
- [x] Livreur : disponibilité en un geste, missions par onglets (ramasser, livrer, retours), action principale unique par mission, appel / WhatsApp / itinéraire, incidents guidés, caisse.
- [x] Service worker : l'application s'ouvre sans réseau ; missions, caisse et listes affichent les dernières données connues avec un bandeau « Hors ligne ». Les actions (livrer, incident…) demandent le réseau.

### Zones d'expédition ✅ *réalisée*
- [x] Zone marquée « expédition » (ex. « Expédition Bouaké (gare UTB Adjamé) ») avec des frais habituels indicatifs ; la course jusqu'à la gare se tarifie comme les autres zones.
- [x] Le livreur dépose le colis à la gare et saisit la compagnie, les frais réellement payés et le numéro du ticket (photo possible). Pas de code de livraison : le destinataire n'est pas là.
- [x] Frais facturés au marchand (écriture `shipping_fee` du grand livre), visibles dans son point du jour, son point d'activité WhatsApp, ses relevés et la fiche du colis.
- [x] Frais de gare payés **soit par le livreur** (de sa poche, ou avec une **avance de caisse** remise avant son départ), **soit directement par l'agence**. Ce que le livreur a payé est déduit de son versement ; l'avance non dépensée revient à la caisse.
- [x] **Autres frais d'une course** (transport, emballage, stationnement ou péage, autre) : déclarés par le livreur depuis sa mission, ou saisis par le dispatch ou la caisse qui choisissent qui a payé (livreur ou agence) et qui supporte le coût (marchand ou agence). Facturés au marchand, ils sont déduits de son point et de ses relevés (« Autres frais ») ; une saisie erronée s'annule par contre-écriture tant que le livreur n'a pas été remboursé.
- [x] Messages : « colis expédié » au marchand (avec les frais) et au destinataire (compagnie, ticket) à la place du « colis en route ».

### Phase 3 : WhatsApp sortant (2 à 3 semaines) ✅ *réalisée côté application*
- [ ] Compte Meta Business vérifié, numéro, soumission des templates : **démarche de l'entreprise** chez Meta ; l'application fournit les textes à soumettre et vérifie leur approbation.
- [x] Pipeline de messages (`Messenger`, file `messages`, relances), préférences par marchand, `outbound_messages`, repli SMS (Twilio), webhook des accusés de réception Meta.
- [x] Alertes d'incident au marchand ; message au destinataire quand le colis part (montant, code de livraison, lien de suivi).
- [x] Points d'activité programmés (quotidien, hebdomadaire) envoyés sur WhatsApp.
- [x] Écrans : Paramètres > WhatsApp (numéro, test, modèles), journal des messages avec renvoi, messages sur la fiche colis, préférences du marchand dans son application.

Règles retenues en phase 3 :
- **Mode simulation par défaut** (`WHATSAPP_DRIVER=log`, `SMS_DRIVER=log`) : rien ne part tant que l'entreprise n'a pas son numéro Meta ; les messages restent visibles dans le journal.
- **Par défaut**, le marchand reçoit les livraisons, les incidents et les reversements ; la validation et le ramassage sont désactivés (trop de messages de routine). Il peut tout changer dans son profil.
- **Un rapport vide n'est pas envoyé** ; le premier rapport part à la prochaine échéance après son activation.
- Le rapport PDF joint est reporté : il demande un lien public signé vers le relevé.

### Phase 4 : application mobile native livreur et marchand (3 à 4 semaines, en parallèle des phases 2 et 3)
- [ ] Ionic/Capacitor : push FCM, GPS en arrière-plan, scan QR, caméra, mode hors ligne.
- [ ] Publication Play Store (Android en priorité en Côte d'Ivoire).

### Phase 5 : WhatsApp entrant (3 semaines) ✅ *réalisée côté application*
- [x] Webhook entrant (messages texte et réponses aux boutons), déduplication, identification du marchand, sessions de 30 minutes.
- [x] Création de course par conversation guidée : message libre ou transféré, questions sur ce qui manque, récapitulatif chiffré, confirmation obligatoire.
- [x] Analyse par règles, ou par Claude si une clé est configurée (repli automatique sur les règles).
- [x] Suivi d'un colis par son numéro, point du jour, menu à boutons.
- [x] Réglage « Courses par WhatsApp » (activable par entreprise) et **simulateur** dans le back-office (WhatsApp > Simulateur) pour tout tester sans compte Meta.
- [ ] WhatsApp Flow « Nouvelle course » : demande un Flow publié chez Meta ; la conversation guidée le remplace pour l'instant.
- [ ] Connexion du numéro propre d'un marchand (Embedded Signup).

Règles retenues en phase 5 :
- **Confirmation obligatoire** : le récapitulatif est toujours montré avant la création, même quand le message est complet.
- **L'IA est facultative** : sans clé, les règles couvrent les formats courants ; avec la clé, les numéros, montants et communes proposés par Claude sont revérifiés avant d'être retenus.
- La commune de ramassage du marchand est écartée quand le message en cite plusieurs (« de Cocody à Yopougon »).
- Les réponses du bot sont des messages de session (gratuits dans la fenêtre de 24 h ouverte par le marchand), sans SMS de repli.

### Phase 6 : stock (3 à 4 semaines) ✅
- [x] Produits, emplacements (chez le marchand ou dans un entrepôt), niveaux, journal des mouvements, réservations liées aux courses.
- [x] Entrepôts de l'entreprise, commandes préparées à l'entrepôt (sans ramassage), remise en stock, contrats et facturation mensuelle du stockage.
- [x] Inventaires, retraits, alertes de stock bas (notification au marchand, et aux agents de dépôt pour l'entrepôt).
- [x] Écrans : Stock dans le back-office (produits, à préparer, mouvements, entrepôts, contrats) ; « Mon stock » et choix des articles dans la nouvelle course de l'application marchand.

Règles retenues en phase 6 :
- **Disponible = en stock − réservé.** Une course réserve ses articles à la création ; elle les sort du stock à la livraison ; annulée, refusée ou colis revenu, elle les libère. Les niveaux sont un cache recalculable depuis `stock_movements`.
- **Qui tient quel stock** : le marchand gère ses produits et le stock gardé chez lui ; le stock des entrepôts n'est modifié que par l'entreprise (droit `stock.manage` : administrateur, agent de dépôt).
- **Commande d'entrepôt** : `pickup_hub_id` renseigné, tarif calculé depuis la zone de l'entrepôt. Validée → « À préparer » → « Préparée au dépôt » → livraison. Pas de ramassage ni de retour au marchand : en cas d'échec, le colis revient à l'entrepôt et il est « remis en stock » (les frais de retour habituels s'appliquent).
- **Facturation du stockage** (`storage:bill`, le 1er du mois à 01:10, pour le mois écoulé) : forfait mensuel, par article et par jour (stock de fin de journée), par commande préparée, ou gratuit. Une seule facturation par contrat et par mois ; un contrat facturé ne change plus de tarif (on le termine et on en crée un autre).
- Les articles d'une course ne se modifient pas après sa création : on l'annule et on en crée une nouvelle.

### Phase 7 : API publique et webhooks (2 à 3 semaines) ✅
- [x] Clés API par marchand (portées `orders:read`, `orders:write`, `stock:read`), idempotence, limitation de débit, documentation OpenAPI (`/developpeurs/api`).
- [x] Webhooks sortants signés (HMAC-SHA256) avec relances, journal des envois, test et renvoi.
- [x] Import de courses depuis un fichier CSV ou Excel, avec aperçu contrôlé ligne par ligne.
- [x] Écrans : Intégrations (marchand et back-office), Importer des courses.

Règles retenues en phase 7 :
- **API séparée** `/api/public/v1`, au contrat stable décrit dans `public/docs/openapi.yaml` (un test vérifie que chaque route y figure). La clé agit au nom d'un compte du marchand : mêmes règles que son application (création « en attente », annulation avant ramassage…).
- **Clés** `lv_<préfixe>_<secret>` : seul le hash est conservé, la clé n'est affichée qu'une fois ; révocation et expiration. Créées par le gérant du marchand (`integrations.manage`) ou par l'administration.
- **Idempotence** obligatoire sur `POST /orders` (en-tête `Idempotency-Key`, mémorisé 24 h) ; **quota** de 120 requêtes par minute et par clé (`PUBLIC_API_RATE_LIMIT`).
- **Webhooks** : HTTPS uniquement et jamais vers une adresse privée (contrôle à l'enregistrement et à l'envoi) ; 6 tentatives (immédiate, puis 1 min, 5 min, 30 min, 2 h, 6 h) ; journal conservé 30 jours.
- **Import** : 500 lignes au plus ; en-têtes reconnus sous plusieurs noms ; le 0 initial d'un numéro retiré par Excel est rétabli ; rien n'est créé si une ligne est en erreur, sauf accord explicite pour n'importer que les lignes valides.

### Phase 8 : pilote et lancement (2 semaines)
- [ ] Pilote avec 3 à 5 marchands et 5 à 10 livreurs ; corrections.
- [ ] Formation, documentation, sauvegardes, supervision (Sentry, uptime).

### Phase 9 : application marchand indépendante (8 à 12 semaines, produit séparé)
- [ ] Nouveau dépôt de code ; connecteurs transporteurs ; comparateur de devis ; boutique, CRM et stock avancé.
- [ ] Modèle économique : abonnement SaaS.

**Total pour la plateforme de livraison : environ 5 à 6 mois** jusqu'au lancement complet. Le MVP utilisable arrive après **environ 2 mois**.

---

## 10. Qualité, sécurité, exploitation
- **Tests** : unitaires (tarification, machine à états, grand livre, stock) et tests de fonctionnalité sur chaque endpoint. Objectif : 100 % des transitions de statut couvertes.
- **Sécurité** : autorisation par *Policies* (un marchand ne voit que ses courses, un livreur que ses missions) ; limitation de débit sur la connexion et l'OTP ; secrets chiffrés (`encrypted` cast) pour les jetons WhatsApp et les clés API ; vérification des signatures de webhooks ; jetons Sanctum à expiration.
- **Données** : sauvegardes quotidiennes chiffrées ; positions GPS conservées 90 jours ; pagination partout.
- **Supervision** : Horizon (files), Sentry (erreurs), journaux structurés, alertes si la file WhatsApp prend du retard.
- **Indicateurs à suivre** : taux de livraison au premier passage, délai moyen création → livraison, taux de retour, écarts de caisse, coût WhatsApp par course.

---

## 11. Problèmes relevés dans le code existant

| # | Fichier | Problème | Correction |
|---|---|---|---|
| 1 | `database/migrations/2026_01_27_163757_add_role_to_users_table.php` | Migration vide : la colonne `role` n'existe pas, donc l'inscription, la connexion et le seeder échouent | Colonne ajoutée ✅ |
| 2 | `database/seeders/AdminSeeder.php` | `Hash` utilisé sans `use` : erreur fatale au `db:seed` | Import ajouté ✅ |
| 3 | `bootstrap/app.php` | `routes/api.php` n'est **pas chargé** : toutes les routes API répondent 404, ou sont capturées par la route attrape-tout de `web.php` | Routes API enregistrées (préfixe `/api`) ✅ |
| 4 | `bootstrap/app.php` | Middleware Sanctum ajouté deux fois | Doublon supprimé ✅ |
| 5 | `config/cors` | Fichier sans extension `.php`, donc jamais chargé | Renommé en `config/cors.php` ✅ |
| 6 | `AuthController::register` | Tout le monde peut s'inscrire comme `livreur` | Inscription publique supprimée ; comptes créés par l'admin (phase 0) ✅ |
| 7 | `AuthController::logout` | Supprime **tous** les jetons (déconnecte tous les appareils) | Ne révoque que le jeton courant ✅ |
| 8 | `app/Models/User.php` | Trait Sanctum `HasApiTokens` absent : `createToken()` plante (erreur 500 à la connexion) | Trait ajouté ✅ |
| 9 | `resources/js/app.js`, `stores/auth.js` | Importent `./router` et `../bootstrap/axios`, qui n'existent pas : le build Vite échoue | Routeur et client HTTP créés ✅ |
| 10 | Front-end | Les appels `axios.post('/login')` ne visaient pas l'API | Client HTTP sur `/api/v1` ✅ |
| 11 | `vite.config.js` | Plugin Tailwind absent : aucune classe CSS générée, pages sans style | Plugin ajouté ✅ |
| 12 | `AdminLayout.vue` | Barre latérale cachée sur grand écran ; composants déclarés en templates texte, non compilés par Vite | Classes corrigées, composants extraits en fichiers `.vue` ✅ |
