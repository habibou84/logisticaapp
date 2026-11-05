# Diagnostic de sécurité de Jibiat (octobre 2026)

Audit du code : comptes et accès, isolement entre entreprises, entrées externes (webhooks, imports, fichiers), console
du super administrateur, dépendances. Chaque point a été vérifié dans le code avant d'être corrigé, et chaque
correction a son test. À rejouer chaque trimestre (voir `docs/MAINTENANCE.md`).

## Corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Critique | En production, avec les SMS en mode « journal », l'API renvoyait le code de vérification SMS (`demo_code`) : on pouvait ouvrir n'importe quel compte avec son seul numéro. | Le mode démonstration est coupé en production (`PhoneVerifier::demoMode`). |
| Haute | Webhooks sortants vers le réseau interne (SSRF) : adresses IPv4 cachées dans une IPv6 (`::ffff:127.0.0.1`…), plages CGNAT et réservées, double résolution DNS. | Toutes ces plages sont refusées, les adresses IPv6 du nom sont contrôlées, et l'envoi se fait vers l'adresse vérifiée (pas de seconde résolution). |
| Haute | Un livreur pouvait déclarer des frais (débités au marchand) sur une course qu'il avait refusée ou qui lui avait été retirée, et voir ces courses. | Frais et photos : seulement pour la course en main ou terminée depuis moins de 24 h. Les courses refusées ou retirées ne sont plus visibles. La synchronisation hors connexion ne touche plus que ses courses. |
| Haute | Aucun journal des actions du super administrateur. | Journal des actions (Console › Journal), sans aucun secret ; les sessions d'assistance sont attribuées à leur auteur. |
| Moyenne | Le simulateur WhatsApp des réglages acceptait un numéro choisi librement et validait les codes de connexion de ce numéro. | Le simulateur ne traite plus ni les codes de vérification ni les avis. |
| Moyenne | Un administrateur pouvait changer le mot de passe ou le téléphone d'un autre administrateur, ou s'envoyer son lien d'invitation. | Refusé ; chacun garde la main sur ses propres identifiants. |
| Moyenne | Clé de bascule de l'ancienne application valable sans fin. | La bascule s'arrête 60 jours après sa date ; limite d'appels réduite. |
| Moyenne | Code de livraison à 4 chiffres essayable sans limite. | 5 essais par course et par demi-heure. |
| Moyenne | Aucun en-tête de sécurité HTTP. | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, et HSTS en production. |
| Moyenne | Session de la console valable 30 jours, mot de passe de 8 caractères, réglages Wave modifiables d'un clic. | Session de 12 h, mot de passe de 12 caractères, mot de passe redemandé pour les réglages Wave, code du validateur de 6 chiffres, essais limités par paiement, lien de validation de 48 h. |
| Moyenne | Session d'assistance sans motif et impossible à fermer. | Motif obligatoire, bouton « Fermer maintenant ». |
| Basse | Domaine personnalisé servi avant d'être vérifié. | Avant vérification, seule l'adresse de contrôle répond. |
| Basse | Codes (vérification, livraison) écrits en clair dans les journaux. | Masqués en production. |
| Basse | Listes paginées sans plafond (`per_page`). | Plafond : 500 dans l'application, 100 dans l'API publique. |
| Basse | Export CSV : un nom commençant par `=` était lu comme une formule par Excel. | Neutralisé. |
| Basse | Connexion par WhatsApp d'un compte de la console : erreur 500. | Refusée proprement (mot de passe seulement). |
| Basse | Dépendances JavaScript avec des failles connues (vue, postcss). | Mises à jour (`npm audit` : 0 faille). `composer audit` : 0 faille. |

## Audit n° 2 (31 octobre 2026), lot 1 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Critique | Un livreur pouvait déclarer un paiement Wave ou Mobile Money « reçu par l'entreprise » sans contrôle : l'argent n'était plus à sa charge. | Le paiement reste à sa charge tant que la caisse ne l'a pas confirmé (bouton « Reçu ? Confirmer » au versement). Référence obligatoire et jamais deux fois la même. La clôture compte ces paiements le jour de leur confirmation. |
| Haute | Montant encaissé libre à la livraison. | Jamais plus que le montant à encaisser ; moins seulement avec une explication, et le dispatch est prévenu. |
| Haute | Frais déclarés par un livreur (et frais de gare) appliqués tout de suite, sans plafond. | « À valider » : aucun effet tant que la caisse ou le dispatch ne les a pas validés ; 200 000 F au plus par déclaration du livreur. |
| Haute | Vol de la clé API d'un e-commerçant par une adresse de retour piégée (antislash) dans la connexion en un clic. | Adresses avec antislash, espace ou caractère de contrôle refusées ; hôte limité aux lettres, chiffres, points et tirets. |
| Haute | Inscription : numéro « vérifié » par un code envoyé à un e-mail choisi par l'inscrit. | Pas de vérification par e-mail à l'inscription. |
| Haute | Codes à 6 chiffres essayables en masse (compteur remis à zéro, requêtes simultanées). | Compteur d'essais atomique, non remis à zéro au renvoi ; 10 codes faux par numéro et par heure au plus ; idem pour la console. |
| Haute | Hameçonnage du message WhatsApp de connexion. | Message prérempli explicite (« Je me connecte à mon compte … ») ; connexion et mot de passe oublié demandent une confirmation « OUI » avec un avertissement. |
| Haute | Lien « Ce n'était pas moi » de la console : nouveau mot de passe avec la seule boîte mail. | Le lien ne fait plus que verrouiller le compte ; réouverture par un autre propriétaire. |
| Haute | Un administrateur pouvait rétrograder un autre administrateur puis changer ses identifiants. | Rôle et statut d'un administrateur modifiables par le super administrateur seulement. |

## Audit n° 2, lot 2 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Moyenne | Hors connexion : livraison avec un code client faux sans limite, action antidatée jusqu'à 72 h. | 3 livraisons « code à vérifier » par livreur et par jour au plus ; rien n'est reversé à l'e-commerçant pour elles avant la vérification du dispatch ; l'heure déclarée ne remonte jamais avant la dernière étape de la course. |
| Moyenne | API publique : modification du montant ou de la zone sans la validation demandée par le réglage « Modifications des e-commerçants ». | Même validation que dans l'application. |
| Moyenne | Versement ou avance annulable après la clôture de caisse du jour. | Annulation refusée après la clôture : faire un mouvement contraire daté du jour. |
| Moyenne | Un caissier pouvait tout faire seul (ajustement sans plafond, moyen de paiement saisi et vérifié par lui). | Ajustement plafonné à 5 000 000 F, et au-delà de 500 000 F par un administrateur ; moyen de paiement saisi par l'équipe « à vérifier » (sauf administrateur), jamais vérifié par celui qui l'a saisi. |
| Moyenne | Console : si l'envoi du code échouait, la session s'ouvrait avec le seul mot de passe. | Connexion bloquée ; procédure de secours sur le serveur : `php artisan console:code {identifiant}`. |
| Moyenne | Limite des essais de mot de passe contournable en écrivant le numéro autrement. | Limite sur le numéro normalisé, et par compte toutes adresses IP confondues (10 par minute, 60 par heure). |
| Moyenne | Session d'assistance : création possible de clés, invitations, comptes ou mots de passe ; jetons gardés après suspension du membre. | Ces actions sont refusées pendant l'assistance ; suspendre un membre de l'équipe Jibiat coupe ses sessions d'assistance. |
| Moyenne | Adresse de l'ancienne application contrôlée une seule fois (redirection, changement DNS). | Contrôle à chaque appel, adresse IP fixée, sans redirection (comme les webhooks). |
| Moyenne | Notifications push : adresse d'envoi libre. | Seulement les services push des navigateurs (Google, Mozilla, Apple, Microsoft), sans redirection. |
| Moyenne | WhatsApp : identifiant du numéro d'une autre entreprise réservable. | Un numéro vérifié ne peut pas être repris ; un compte non vérifié auprès de Meta ne reçoit aucun message, et son identifiant est libéré pour le vrai titulaire. |
| Moyenne | `symfony/mime` avec une faille connue. | Mise à jour (`composer audit` : 0 faille). |

## Vérifié et sain

Isolement entre entreprises (scope global, jeton lié à l'adresse de l'entreprise), isolement entre marchands d'une
même entreprise, API publique (clés hachées, idempotence), webhooks entrants signés (Meta, service WhatsApp),
fichiers téléversés (privés, types contrôlés, pas de SVG), requêtes SQL (aucune entrée utilisateur interpolée),
pages publiques (suivi, avis, boutique), secrets chiffrés ou hachés en base, `.env` hors du dépôt.

## Reste à faire (à décider)

1. **Double authentification du super administrateur** (code par application d'authentification ou par WhatsApp)
   avant d'ouvrir la console. C'est le risque principal restant : un mot de passe volé donne toute la plateforme.
2. **Politique de sécurité du contenu (CSP)** : à mettre d'abord en mode « rapport seulement », puis à imposer.
3. **Rejeu des événements du service WhatsApp** : ajouter un horodatage signé (risque faible, le service est local).
4. **Liste des appareils connectés** avec « tout déconnecter », pour chaque compte.

## À vérifier sur le serveur de production

- `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `SMS_DRIVER` réglé sur le vrai fournisseur (ou `none`).
- Sauvegarde quotidienne de la base active (Console › Santé l'indique), et une copie hors du serveur.
- Ports ouverts : seulement 22, 80 et 443 depuis l'extérieur.
