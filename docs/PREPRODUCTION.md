# Préproduction (preprod.jibiat.com)

Chaque modification passe d'abord par la préproduction, puis est copiée en production après vos tests.
La préproduction tourne sur le même serveur que la production (LWS, ISPConfig). C'est une installation
séparée, avec son dossier, son utilisateur système, sa base, son PHP-FPM, son hôte virtuel Apache, ses files
d'attente et ses tâches planifiées. Le site de production n'est pas modifié.

| | Préproduction | Production |
|---|---|---|
| Adresses | `preprod.jibiat.com`, `admin.preprod.jibiat.com`, `{entreprise}.preprod.jibiat.com` | `jibiat.com`, `admin.jibiat.com`, `{entreprise}.jibiat.com` |
| Branche suivie | `main` | `production` |
| Installation (`INSTANCE`) | `jibiat-preprod`, dossier `/var/www/jibiat-preprod` | `livraison`, dossier du site ISPConfig |
| Accès | code d'accès de l'équipe, jamais indexée | ouverte à tous, indexée |
| WhatsApp, SMS, e-mails | simulés : aucun vrai message (journal des messages) | réels |
| Données | entreprises de démonstration | vraies données |

## Cycle des mises à jour

```
PR fusionnée ──► main ──► PRÉPRODUCTION : vos tests
                   └──(« Passe en production »)──► production ──► PRODUCTION : vos clients
```

1. Une modification fusionnée arrive sur `main`. La préproduction se met à jour, puis vous testez.
2. Tests concluants : dites « Passe en production ». Une PR de `main` vers `production` est ouverte puis fusionnée.
3. La production se met à jour.

Avec la mise à jour automatique (`docs/MISE-A-JOUR-AUTOMATIQUE.md`), GitHub fait les étapes 1 et 3 seul, après des
tests réussis. Sans elle, ou pour relancer à la main :

- préproduction : `bash /var/www/jibiat-preprod/deploy/update.sh` ;
- production : `bash /var/www/clients/client0/web2/web/livraison/deploy/update.sh`.

Chaque `update.sh` met à jour l'installation du dossier où il se trouve.

---

## 1. DNS (Cloudflare › jibiat.com › DNS › Records)

| Type | Nom | Contenu | Proxy |
|---|---|---|---|
| A | `preprod` | IP du serveur | **DNS only** (nuage gris) |
| A | `*.preprod` | IP du serveur | **DNS only** (nuage gris) |

- Le nuage doit rester **gris**. Le proxy gratuit de Cloudflare ne couvre pas les adresses de second niveau
  (`express-ds.preprod.jibiat.com`). C'est le serveur qui présente lui-même son certificat Let's Encrypt,
  comme pour la préproduction de Jibiat Marchand.
- Les enregistrements `@` et `*` de la production ne changent pas. Plus précis, `*.preprod` l'emporte pour
  les adresses de la préproduction.

Vérification : `nslookup preprod.jibiat.com` et `nslookup essai.preprod.jibiat.com` donnent l'IP du serveur.

## 2. Jeton API Cloudflare (pour le certificat)

1. Cloudflare › profil › **My Profile** › **API Tokens** › **Create Token**.
2. Modèle **Edit zone DNS** › **Use template**.
3. **Zone Resources** : Include › Specific zone › `jibiat.com`.
4. **Continue to summary** › **Create Token**, puis copiez le jeton. Il ne s'affiche qu'une fois.

Le script le range dans `/etc/letsencrypt/cloudflare-jibiat-preprod.ini`, lisible par root seulement. Certbot
s'en sert pour renouveler le certificat tous les 60 jours.

## 3. Installation (une fois)

Prérequis : la production est à jour avec une version qui contient ce guide. Lancez d'abord son `deploy/update.sh`.

En SSH sur le serveur, puis `sudo -i` :

```bash
DOMAIN=preprod.jibiat.com \
EMAIL=vous@exemple.ci \
CLOUDFLARE_API_TOKEN=le_jeton_cloudflare \
bash /chemin/du/site/de/production/livraison/deploy/preprod.sh
```

Utilisez le dossier de l'application de production, celui où vous lancez `update.sh`. Le script clone
lui-même la préproduction dans `/var/www/jibiat-preprod`. La durée est de 5 à 10 minutes. Le script est
relançable sans risque.

À la fin, le script affiche :
- les adresses de la préproduction ;
- le **code d'accès**, à donner à votre équipe ;
- la vérification `https://…/up` des adresses : préproduction et production doivent répondre `200`.

Options :
- `ACCESS_CODE=mon-code` choisit le code d'accès ;
- `DEMO=0` installe une base vide, sans entreprise de démonstration.

## 4. Accès de l'équipe

- Chaque page de la préproduction demande le code d'accès, sur la présentation, la console et les entreprises
  de test. Il est saisi une fois, puis mémorisé 30 jours sur l'appareil.
- Restent ouverts : la sonde `/up` et les webhooks entrants (`/api/webhooks/…`). Ces webhooks vérifient
  la signature de Meta.
- **Changer le code**, par exemple quand quelqu'un quitte l'équipe : modifiez `PREPROD_ACCESS_CODE` dans
  `/var/www/jibiat-preprod/.env`, puis `cd /var/www/jibiat-preprod && sudo -u jibiat_preprod php artisan optimize`.
  Tout le monde doit alors saisir le nouveau code.
- **Comptes de démonstration**, au mot de passe `password` :
  - super administrateur `07 00 00 00 00` sur `admin.preprod.jibiat.com` ;
  - entreprise `livraison-express-ci.preprod.jibiat.com`, avec administrateur `07 00 00 00 01` et livreurs `07 00 00 00 04`.

## 5. Moteurs de recherche

Hors production, toutes les pages répondent `X-Robots-Tag: noindex, nofollow`, et `robots.txt` interdit tout.
L'hôte virtuel Apache ajoute aussi cet en-tête aux fichiers statiques. Rien à régler.

En production, `robots.txt` autorise tout, comme avant. Le réglage `SEARCH_INDEXING` force l'un ou l'autre.

Un bandeau « Préproduction » s'affiche en bas à gauche de chaque page, pour ne jamais confondre les deux sites.

## 6. Passer la production sur la branche `production` (une fois)

1. La branche `production` est créée sur GitHub à partir de `main`.
2. Sur le serveur, une seule fois :
   ```bash
   BRANCH=production bash /chemin/du/site/de/production/livraison/deploy/update.sh
   ```
3. Ensuite, `update.sh` suit toujours `production` en production, et `main` en préproduction.

## 7. Points d'attention

- **Ordre des hôtes Apache.** Le fichier s'appelle `000-jibiat-preprod.conf`, ce qui le fait lire avant les
  sites d'ISPConfig (`100-….vhost`). Le site de production déclare `*.jibiat.com`, qui couvrirait sinon aussi
  `*.preprod.jibiat.com`. Ne renommez pas ce fichier, et ne créez pas de site `preprod.jibiat.com` dans ISPConfig.
- **WhatsApp.** La préproduction n'envoie rien (`WHATSAPP_DRIVER=log`). Les messages simulés se lisent dans
  le journal des messages de l'application. Le webhook de l'application Meta reste celui de la production.
- **Ressources.** Plusieurs applications partagent le serveur. Surveillez la mémoire (`free -h`) et la charge (`uptime`).

## 8. Où regarder en cas de problème

| Quoi | Où |
|---|---|
| Erreurs de l'application | `/var/www/jibiat-preprod/storage/logs/` |
| Apache | `/var/log/apache2/jibiat-preprod-error.log` |
| Files d'attente, temps réel | `supervisorctl status jibiat-preprod-horizon jibiat-preprod-reverb` |
| Certificat | `certbot certificates` ; essai de renouvellement : `certbot renew --dry-run` |
| Réglages | `/var/www/jibiat-preprod/.env`, puis `sudo -u jibiat_preprod php artisan optimize` |

Si `apache2ctl configtest` refuse la configuration, le script désactive la préproduction et rien d'autre ne
change. Vérifiez les modules (`a2enmod ssl proxy_fcgi proxy_wstunnel`) et qu'aucun site ISPConfig ne déclare
déjà `preprod.jibiat.com`.
