# Applications Android et iOS

Deux applications, publiées au nom de Jibiat et communes à toutes les entreprises de livraison :

| Application | Identifiant | Comptes acceptés |
|---|---|---|
| **Jibiat Livreur** | `com.jibiat.livreur` | livreurs |
| **Jibiat E-commerçant** | `com.jibiat.ecommercant` | e-commerçants (propriétaire et employés) |

Elles reprennent les écrans du site (`resources/js`) avec [Capacitor](https://capacitorjs.com) : une évolution
des écrans profite au site et aux applications.

## Fonctionnement

1. **Premier lancement : code de l'entreprise.** L'utilisateur saisit le code de son entreprise de livraison
   (le début de son adresse, ex. `rapide-express` pour `rapide-express.jibiat.com`). L'application le vérifie
   auprès de la plateforme (`GET https://jibiat.com/api/public/apps/companies/{code}`), qui renvoie le nom, le
   logo et l'adresse du site de l'entreprise (domaine personnalisé s'il est actif).
2. **Ensuite, tout passe par le site de l'entreprise** : connexion, API (`/api/v1`), temps réel, liens de suivi.
   Le code est gardé sur le téléphone ; « Changer d'entreprise » sur la page de connexion permet d'en choisir une autre.
3. **Chaque application n'ouvre que son espace** : un e-commerçant qui se connecte à l'application livreur
   (ou l'inverse) est renvoyé vers la bonne application ; les pages de l'administration ne s'y ouvrent pas.

Côté serveur : origines des applications autorisées par CORS (`https://localhost` pour Android,
`capacitor://localhost` pour iOS), dans `config/cors.php`.

## Fichiers

- `resources/js/native/` : application en cours (fixée à la compilation), entreprise choisie, adresses absolues.
- `resources/js/views/auth/NativeCompany.vue` : écran « Code de votre entreprise ».
- `mobile/index.html`, `mobile/main.js` : entrée des applications (bouton retour d'Android).
- `vite.mobile.config.js` : compilation des écrans dans `mobile/<application>/www`.
- `mobile/livreur/`, `mobile/ecommercant/` : projets Capacitor, Android (`android/`) et iOS (`ios/`).
- `mobile/assets.py` : icônes et écrans de démarrage, depuis `public/app-icons/*-maskable-512.png`.

## Obtenir une application Android de test (APK)

À chaque modification fusionnée (ou à la demande : onglet **Actions** → « Applications mobiles » →
**Run workflow**), GitHub compile les deux APK. Dans l'exécution terminée, section **Artifacts** :
`jibiat-livreur-apk` et `jibiat-ecommercant-apk` (fichiers zip contenant `app-debug.apk`, gardés 30 jours).

Installation sur un téléphone Android : copier l'APK sur le téléphone, l'ouvrir, autoriser l'installation
depuis cette source si Android le demande. Ces APK de test ne passent pas par le Play Store.

## Compiler soi-même

```bash
npm ci
npm run mobile:livreur        # écrans + synchronisation des projets Android et iOS
cd mobile/livreur/android && ./gradlew assembleDebug   # APK (Android Studio ou SDK Android requis)
```

`VITE_PLATFORM_URL` (par défaut `https://jibiat.com`) désigne la plateforme qui retrouve les entreprises.

Après avoir changé une icône du site : `python3 mobile/assets.py`.

## iOS

Les projets iOS sont prêts (`mobile/*/ios`). Leur compilation demande un Mac : elle se fera par un service
en ligne (Codemagic ou GitHub Actions macOS) avec le compte Apple Developer de Jibiat SARL.

## Applications installées depuis le navigateur (PWA)

Les deux applications s'installent aussi depuis Chrome, chacune limitée à son espace :

| Application | Lien d'installation | Portée (`scope`) | Connexion |
|---|---|---|---|
| Livreur | `/livreur` | `/livreur` | `/livreur/connexion` |
| E-commerçant | `/marchand` (ou `/`) | `/marchand` | `/marchand/connexion` (même écran que `/`) |

Les portées ne doivent jamais se recouvrir : une application dont la portée est `/` couvre aussi
`/livreur`, et Chrome affiche alors « application déjà installée » sur le lien de l'autre application.
Une application installée avant ce réglage (portée `/`) se met à jour d'elle-même sous quelques jours ;
pour aller plus vite, la désinstaller puis la réinstaller.

## Étapes suivantes

- Notifications natives (Firebase pour Android, Apple pour iOS) branchées sur les notifications existantes.
- Position du livreur en arrière-plan, appareil photo, ouverture des liens de suivi dans l'application.
- Versions signées pour le Play Store et l'App Store, suppression du compte depuis l'application.
