# Vidéos de démonstration

Vidéos courtes de l'application (sous-titrées, sans voix) pour le site, WhatsApp, Facebook, TikTok…
Chaque scénario se rejoue à l'identique après une modification des écrans.

| Scénario | Vidéo | Format |
|---|---|---|
| `ecommercant` | L'e-commerçant crée sa course | 9:16 |
| `livreur` | La journée du livreur | 9:16 |
| `whatsapp` | Les commandes par WhatsApp | 9:16 |
| `caisse` | Le point de caisse en 2 minutes | 16:9 |
| `statistiques` | Vos statistiques | 16:9 |
| `de-a-a-z` | Une course de A à Z (e-commerçant, agence, livreur, caisse) | 16:9 |

## Fichiers

- `scenarios/*.cjs` : textes (titre, étapes, sous-titres) et gestes filmés, avec un repère par étape.
- `lib.cjs` : téléphone (390 × 844) ou ordinateur (1280 × 720) connecté avec un compte de démonstration,
  filmé image par image ; point orange à chaque toucher, flèche de souris sur ordinateur.
- `record.cjs` : lance un scénario, écrit `.work/<scénario>/` (images, minutage, textes).
- `build.py` : montage (fond Jibiat, cadre téléphone ou navigateur, sous-titres, ouverture et fin),
  MP4 H.264 avec piste son muette dans `.work/<scénario>/out/`.
- `demo-data.php` : un mois d'activité réaliste (e-commerçants, livreurs, ~360 courses, versements, reversements).
- `prepare.php` : état de départ d'une prise (`courier` : missions de Koffi ; `az` : Koffi sans mission ni argent).

## Prérequis

Base **locale** uniquement (jamais la production) : PostgreSQL, Redis, PHP, Node, Python 3 (Pillow), ffmpeg,
Chromium et Playwright (`npm i --no-save playwright`, ou `PLAYWRIGHT_MODULES=<dossier node_modules>`).
Les scripts PHP forcent files synchrones, WhatsApp et SMS en journal : aucun vrai message ne part.

## Tourner une vidéo

```bash
# 1. Base de démonstration (une fois)
php artisan migrate:fresh --seed     # APP_ENV=local : comptes de démonstration inclus
php tools/videos/demo-data.php

# 2. Application servie sous *.jibiat.com (résolu vers la machine par le navigateur filmé)
PLATFORM_DOMAIN=jibiat.com PLATFORM_SCHEME=https QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null \
  WHATSAPP_DRIVER=log php artisan serve --port=8124

# 3. Enregistrement puis montage
php tools/videos/prepare.php az          # selon le scénario (voir l'en-tête du fichier)
node tools/videos/record.cjs de-a-a-z
python3 tools/videos/build.py tools/videos/.work/de-a-a-z
```

La vidéo WhatsApp demande le module « Commandes par WhatsApp » dans la formule de l'entreprise de démonstration.
Un enregistrement modifie la base (courses livrées, versements) : sauvegardez-la (`pg_dump -Fc`) après
`demo-data.php` et restaurez-la avant chaque prise pour rejouer exactement la même vidéo.

## Modifier une vidéo

- Textes : `spec` en tête du scénario (`captions` par repère, `steps` pour la pastille « Étape N »).
- Rythme : les `s.wait(...)` entre deux repères ; un sous-titre reste affiché jusqu'au repère suivant.
- Sur ordinateur, viser des sous-titres d'une ligne (environ 45 caractères).
