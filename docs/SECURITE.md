# Diagnostic de sécurité (repris de Jibiat Livraison)

> Logistica reprend les corrections de sécurité de Jibiat Livraison qui s'appliquent à une seule entreprise.
> Les points propres à la plateforme Jibiat (console du super administrateur, assistance, domaines personnalisés,
> ancienne application) ne concernent pas Logistica et ont été retirés de ce document.

Audit du code : comptes et accès, entrées externes (webhooks, imports, fichiers), dépendances. Chaque point a été vérifié dans le code avant d'être corrigé, et chaque
correction a son test. À rejouer chaque trimestre (voir `docs/MAINTENANCE.md`).

## Corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Critique | En production, avec les SMS en mode « journal », l'API renvoyait le code de vérification SMS (`demo_code`) : on pouvait ouvrir n'importe quel compte avec son seul numéro. | Le mode démonstration est coupé en production (`PhoneVerifier::demoMode`). |
| Haute | Webhooks sortants vers le réseau interne (SSRF) : adresses IPv4 cachées dans une IPv6 (`::ffff:127.0.0.1`…), plages CGNAT et réservées, double résolution DNS. | Toutes ces plages sont refusées, les adresses IPv6 du nom sont contrôlées, et l'envoi se fait vers l'adresse vérifiée (pas de seconde résolution). |
| Haute | Un livreur pouvait déclarer des frais (débités au marchand) sur une course qu'il avait refusée ou qui lui avait été retirée, et voir ces courses. | Frais et photos : seulement pour la course en main ou terminée depuis moins de 24 h. Les courses refusées ou retirées ne sont plus visibles. La synchronisation hors connexion ne touche plus que ses courses. |
| Moyenne | Le simulateur WhatsApp des réglages acceptait un numéro choisi librement et validait les codes de connexion de ce numéro. | Le simulateur ne traite plus ni les codes de vérification ni les avis. |
| Moyenne | Un administrateur pouvait changer le mot de passe ou le téléphone d'un autre administrateur, ou s'envoyer son lien d'invitation. | Refusé ; chacun garde la main sur ses propres identifiants. |
| Moyenne | Code de livraison à 4 chiffres essayable sans limite. | 5 essais par course et par demi-heure. |
| Moyenne | Aucun en-tête de sécurité HTTP. | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, et HSTS en production. |
| Basse | Codes (vérification, livraison) écrits en clair dans les journaux. | Masqués en production. |
| Basse | Listes paginées sans plafond (`per_page`). | Plafond : 500 dans l'application, 100 dans l'API publique. |
| Basse | Export CSV : un nom commençant par `=` était lu comme une formule par Excel. | Neutralisé. |
| Basse | Dépendances JavaScript avec des failles connues (vue, postcss). | Mises à jour (`npm audit` : 0 faille). `composer audit` : 0 faille. |

## Audit n° 2 (31 octobre 2026), lot 1 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Haute | Montant encaissé libre à la livraison. | Jamais plus que le montant à encaisser ; moins seulement avec une explication, et le dispatch est prévenu. |
| Haute | Frais déclarés par un livreur (et frais de gare) appliqués tout de suite, sans plafond. | « À valider » : aucun effet tant que la caisse ou le dispatch ne les a pas validés ; 200 000 F au plus par déclaration du livreur. |
| Haute | Vol de la clé API d'un e-commerçant par une adresse de retour piégée (antislash) dans la connexion en un clic. | Adresses avec antislash, espace ou caractère de contrôle refusées ; hôte limité aux lettres, chiffres, points et tirets. |
| Haute | Inscription : numéro « vérifié » par un code envoyé à un e-mail choisi par l'inscrit. | Pas de vérification par e-mail à l'inscription. |
| Haute | Hameçonnage du message WhatsApp de connexion. | Message prérempli explicite (« Je me connecte à mon compte … ») ; connexion et mot de passe oublié demandent une confirmation « OUI » avec un avertissement. |

## Audit n° 2, lot 2 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Moyenne | Hors connexion : livraison avec un code client faux sans limite, action antidatée jusqu'à 72 h. | 3 livraisons « code à vérifier » par livreur et par jour au plus ; rien n'est reversé à l'e-commerçant pour elles avant la vérification du dispatch ; l'heure déclarée ne remonte jamais avant la dernière étape de la course. |
| Moyenne | API publique : modification du montant ou de la zone sans la validation demandée par le réglage « Modifications des e-commerçants ». | Même validation que dans l'application. |
| Moyenne | Versement ou avance annulable après la clôture de caisse du jour. | Annulation refusée après la clôture : faire un mouvement contraire daté du jour. |
| Moyenne | Un caissier pouvait tout faire seul (ajustement sans plafond, moyen de paiement saisi et vérifié par lui). | Ajustement plafonné à 5 000 000 F, et au-delà de 500 000 F par un administrateur ; moyen de paiement saisi par l'équipe « à vérifier » (sauf administrateur), jamais vérifié par celui qui l'a saisi. |
| Moyenne | Limite des essais de mot de passe contournable en écrivant le numéro autrement. | Limite sur le numéro normalisé, et par compte toutes adresses IP confondues (10 par minute, 60 par heure). |
| Moyenne | Notifications push : adresse d'envoi libre. | Seulement les services push des navigateurs (Google, Mozilla, Apple, Microsoft), sans redirection. |
| Moyenne | WhatsApp : identifiant du numéro d'une autre entreprise réservable. | Un numéro vérifié ne peut pas être repris ; un compte non vérifié auprès de Meta ne reçoit aucun message, et son identifiant est libéré pour le vrai titulaire. |
| Moyenne | `symfony/mime` avec une faille connue. | Mise à jour (`composer audit` : 0 faille). |

## Audit n° 2, lot 3 corrigé

| Gravité | Problème | Correction |
|---|---|---|
| Basse | Connexion WhatsApp et mot de passe oublié d'un numéro bloqués par 5 demandes. | 5 demandes par heure par numéro et par adresse IP ; 20 toutes adresses confondues. |
| Basse | On pouvait savoir si un numéro avait un compte, et de quel type. | Connexion par WhatsApp : même réponse dans tous les cas ; la raison (aucun compte, autre espace, compte suspendu) n'est donnée qu'après l'envoi du message depuis le numéro. |
| Basse | Accepter une invitation laissait ouvertes les autres sessions du compte. | Les autres sessions et appareils retenus sont fermés. |
| Basse | Le robot WhatsApp donnait le numéro lié à un code en attente. | Jamais de numéro dans la réponse ; 10 codes non reconnus par heure et par numéro. |
| Basse | Fichier Excel piégé pouvant bloquer l'import. | Taille décompressée (50 Mo), nombre de fichiers, lignes (2 000) et colonnes (100) limités avant et pendant la lecture. |
| Basse | Page boutique noyable sous de fausses commandes en changeant d'adresse IP. | Limite par boutique, toutes adresses IP confondues (20 par minute, 200 par heure). |
| Basse | Paiement à l'unité d'un e-commerçant sans contrôle du moyen de paiement. | Refusé si le moyen de paiement attend d'être vérifié, comme le paiement groupé. |
| Basse | Deux contrôles d'argent hors verrou (annulation de versement, paie gardée sur l'encaissé). | Contrôles faits sous verrou en base. |
| Basse | Pièces jointes internes lisibles par l'e-commerçant ; historique interne visible du livreur ; photos de produits par numéro. | Pièce jointe d'une étape interne refusée ; le livreur voit l'historique partagé et ses propres notes ; photo d'un produit non publié seulement avec le lien de l'application. |

## Vérifié et sain

Isolement entre entreprises (scope global, jeton lié à l'adresse de l'entreprise), isolement entre clients d'une
même entreprise, API publique (clés hachées, idempotence), webhooks entrants signés (Meta, service WhatsApp),
fichiers téléversés (privés, types contrôlés, pas de SVG), requêtes SQL (aucune entrée utilisateur interpolée),
pages publiques (suivi, avis, boutique), secrets chiffrés ou hachés en base, `.env` hors du dépôt.

## Reste à faire (à décider)

1. **Double authentification des administrateurs** (code par application d'authentification ou par WhatsApp).
2. **Politique de sécurité du contenu (CSP)** : à mettre d'abord en mode « rapport seulement », puis à imposer.
3. **Rejeu des événements du service WhatsApp** : ajouter un horodatage signé (risque faible, le service est local).
4. **Liste des appareils connectés** (« Déconnecter de tous ses appareils » existe déjà dans la page Utilisateurs).

## À vérifier sur le serveur de production

- `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `SMS_DRIVER` réglé sur le vrai fournisseur (ou `none`).
- Sauvegarde quotidienne de la base active (Réglages › bilan de santé), et une copie hors du serveur.
- Ports ouverts : seulement 22, 80 et 443 depuis l'extérieur.
