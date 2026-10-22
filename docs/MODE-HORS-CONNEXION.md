# Mode hors connexion des livreurs

Réglage par entreprise : **Paramètres › Courses › Mode hors connexion des livreurs** (désactivé par défaut).

## Ce que le livreur peut faire sans réseau

Il peut enregistrer :
- « je pars chercher », « récupéré », « je pars livrer » ;
- « livré » avec l'encaissement, ou « expédié » ;
- un échec, un report ;
- un retour au marchand ;
- une note.

Chaque action est gardée sur le téléphone, dans l'ordre, puis l'application l'envoie seule :
- au retour du réseau ;
- quand le livreur revient dans l'application ;
- ou toutes les 30 secondes.

Ce que le livreur voit :
- un bandeau bleu « ⏳ N actions en attente d'envoi », sur lequel il peut toucher pour envoyer tout de suite ;
- un badge « ⏳ À envoyer » sur la mission ;
- un rappel s'il veut se déconnecter avec des actions pas encore envoyées.

Ces actions demandent toujours le réseau :
- accepter ou refuser une mission ;
- ajouter des frais ;
- envoyer une photo.

## Au retour du réseau

- **Une seule fois.** Chaque action porte un identifiant unique : envoyée deux fois (coupure pendant la réponse), elle
  n'est appliquée qu'une fois.
- **À son heure réelle.** L'heure retenue est celle du téléphone au moment de l'action, dans le journal et pour l'heure de
  livraison. Une heure invraisemblable (plus de 3 jours en arrière, ou dans le futur) est remplacée par l'heure de
  l'envoi.
- **Code client faux.** Si l'entreprise exige le code et que le code saisi hors connexion est faux, la livraison est
  gardée : le client est déjà parti. Elle est alors signalée :
  - par une alerte aux administrateurs et aux dispatchers ;
  - dans la file **⚠️ Codes à vérifier** de la liste des courses ;
  - par un bandeau sur la fiche de la course.

  Après avoir vérifié auprès du destinataire, cliquez sur « Livraison vérifiée ».
- **Action devenue impossible.** C'est le cas si la course a été annulée, réattribuée ou modifiée par l'agence entre-temps.
  - Le livreur voit l'action dans « ⚠️ Actions refusées », avec le motif.
  - Le refus est noté sur la course (note interne).
- **Messages au client et au marchand.** Les WhatsApp, notifications et webhooks partent à l'envoi. La caisse voit
  l'encaissement à ce moment-là.

## Limites

- **iPhone.** L'envoi en arrière-plan n'existe pas en mode site web. L'envoi se fait quand le livreur rouvre
  l'application.
- **Données du téléphone.** Les actions en attente sont stockées sur le téléphone. Si le livreur vide les données de
  l'application ou change de téléphone avant l'envoi, elles sont perdues.
- **Missions.** Elles s'affichent telles qu'elles étaient à la dernière connexion. Une mission attribuée pendant la
  coupure n'apparaît qu'au retour du réseau.

## Technique

- **Application** : `resources/js/composables/useOfflineQueue.js`, avec le bandeau `components/mobile/OfflineQueueBanner.vue`.
- **Serveur** : `POST /api/v1/courier/sync`, une action à la fois. La réponse est toujours 200, avec le résultat `ok` ou
  `rejected`. Le traitement est fait par `app/Services/Couriers/OfflineSync.php`, et chaque action est enregistrée dans
  la table `offline_actions`.
- **Données ajoutées** : `companies.offline_courier_actions` et `orders.delivery_code_unverified_at`.
- **Vérification d'une livraison** : `POST /orders/{id}/verify-delivery-code`.
