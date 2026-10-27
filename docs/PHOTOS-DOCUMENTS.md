# Photos de documents

Le livreur photographie un document (accusé de réception, décharge, ticket, reçu, bon de retour signé) : l'application
le traite comme un scanner avant l'envoi. Côté équipe, la photo d'un papier de véhicule ou d'un permis (Véhicules ›
fiche › Papiers, Échéances › permis) passe aussi par le scanner ; un PDF est gardé tel quel.

## Ce que fait le scanner (application livreur, sur le téléphone)
1. **Feuille trouvée** sur la photo (ses quatre bords). Si elle n'est pas trouvée, ou pour corriger, le livreur place
   les quatre coins au doigt (**✂️ Recadrer**, **Toute la photo**).
2. **Redressée** : remise à plat et à angle droit, même photographiée de biais ; le décor autour est retiré.
3. **Nettoyée** : ombres et éclairage inégal effacés, fond blanc, écriture et tampon nets. Trois rendus :
   **Couleur** (garde le tampon bleu ou rouge), **Gris**, **Noir et blanc**.
4. **Contrôlée** : photo floue, trop sombre, reflet, document pris de trop loin. Selon le réglage, une photo floue ou
   trop sombre est à reprendre (après trois essais, le livreur peut l'envoyer quand même : c'est noté sur la course).
5. **Allégée** : 2 000 pixels sur le grand côté (scan A4 à 200 points par pouce), quelques centaines de Ko au lieu de
   4 à 12 Mo.

Le traitement se fait sur le téléphone (OpenCV.js dans un worker) : environ 3,5 Mo téléchargés au premier scan, puis
gardés par l'application. Premier scan sans réseau : le livreur peut envoyer la photo telle quelle.

Les autres photos (colis, incident, état d'un véhicule) ne passent pas par le scanner : elles sont seulement tournées
dans le bon sens, réduites et recompressées. Le serveur accepte jusqu'à 15 Mo par photo.

## Photo d'origine (valeur de preuve)
Le scanner modifie la photo : l'original est gardé à côté (réglage **Garder aussi la photo d'origine**, actif par
défaut), avec l'heure de la prise et les informations du scanner (rendu, qualité, avertissements).
- Équipe : fiche de la course › historique › **📄 Voir le document** et **Voir l'original** ; bon de retour ›
  **Voir l'original de la photo** ; fiche du véhicule › Papiers › **Voir** et **Original**.
- Client et Jibiat Marchand (API publique, `proof.photos`) : le document net seulement. Contrat inchangé.

## Sans réseau
Avec le mode hors connexion de l'entreprise, une photo prise sans réseau est gardée sur le téléphone (avec son
original) et part toute seule au retour de la connexion (bandeau « 📷 N photos en attente d'envoi »). Renvoyée deux
fois après une coupure, elle n'est gardée qu'une fois. Avant d'envoyer la livraison, les photos en attente partent
d'abord.

## Réglages (Réglages › Courses et livraison › Photos de documents)
- **Scanner utilisé pour** : accusé de réception et décharge ; tickets d'expédition et reçus de frais ; remise des
  retours ; papiers des véhicules et permis, reçus de carburant.
- **Rendu proposé** : couleur, gris ou noir et blanc (le livreur peut changer pour chaque photo).
- **Contrôle de qualité** : bloquant, avertissement seulement, ou aucun.
- **Résolution** : standard (2 000 pixels) ou haute (3 000 pixels).
- **Garder aussi la photo d'origine** : oui ou non.

## API (application)
`POST /api/v1/orders/{id}/attachments` : `file` (document traité), `file_original` (photo d'origine), `file_meta`
(JSON : `scanned`, `mode`, `quality`, `taken_at`…), `client_ref` (identifiant donné par le téléphone).
Original : `GET …/attachments/{id}?original=1` (équipe seulement). Remise des retours : `photo`, `photo_original`,
`photo_meta`. Papiers : `POST /api/v1/vehicles/documents` avec `file`, `file_original`, `file_meta` ; original :
`GET /api/v1/vehicles/documents/{id}/file?original=1`.
