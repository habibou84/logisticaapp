# Page Utilisateurs

Menu **Équipe › Utilisateurs** (droit « Gérer les utilisateurs »). Elle répond à deux questions : qui accède à quoi,
et qui n'utilise plus son accès.

## Compteurs et onglets
- **Équipe** : comptes sans rôle livreur ni client, répartis par rôle.
- **Livreurs** : actifs, suspendus, et livreurs mis à disposition (MAD).
- **Comptes clients** : propriétaires, gestionnaires et agents des clients.
- **Suspendus** : accès coupé ; bouton « Réactiver » sur la ligne.
- **À vérifier** : comptes actifs jamais connectés, ou sans connexion depuis **30 jours**.

L'onglet ouvert est gardé dans l'adresse (`?onglet=`). L'équipe se filtre par rôle et par activité.

## Menu ⋯ d'un compte
Modifier · Voir comme ce compte · Fiche livreur ou fiche client · Comptes du client · Lien de connexion par WhatsApp ·
Historique du compte · Déconnecter de tous ses appareils · Suspendre.

## Lien de connexion par WhatsApp
Pour l'équipe et les livreurs (les comptes clients se gèrent dans Clients › Comptes).
- Un compte créé **sans mot de passe** reçoit directement un lien.
- Le lien ouvre `/invitation/{code}` : la personne voit son nom, son rôle, et choisit un mot de passe (conseillé)
  avant d'ouvrir son espace. Le lien sert une seule fois et expire après 7 jours.
- Ouvrir le lien ferme les sessions ouvertes ailleurs sur ce compte.

## Historique du compte
Table `user_account_events` : création, rôle, périmètre, droits ajustés, nom, accès (téléphone, e-mail, mot de passe),
suspension, réactivation, lien préparé et ouvert, déconnexion forcée et « Voir comme ce compte ».

## API
| Méthode | Adresse | Rôle |
|---|---|---|
| GET | `/api/v1/users?scope=team\|couriers\|merchants\|suspended\|inactive` | liste par onglet |
| GET | `/api/v1/users/summary` | compteurs |
| GET | `/api/v1/users/{id}/history` | historique |
| POST | `/api/v1/users/{id}/logout` | déconnecter partout |
| POST | `/api/v1/users/{id}/invitation` | préparer le lien WhatsApp |
| GET / POST | `/api/v1/invitations/{code}` · `/accept` | page publique du lien |
