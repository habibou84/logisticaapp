# Guide vidéo « Bien démarrer »

Neuf courtes vidéos (45 à 60 s) : une par étape essentielle de la **Mise en route** (entreprise, zones, tarifs,
règles des courses, livreurs, paie, clients, course d'essai) et le **planning des livreurs** (étape conseillée
« L'assignation automatique »). Voix off en français, sous-titres affichés d'office.

## Où on les voit
- **Tableau de bord** des administrateurs : carte « 🎬 Bien démarrer », un aperçu par chapitre, « ✓ fait » quand
  l'étape de la mise en route est faite, « Vidéo vue » quand le chapitre a été regardé. **Masquer** la retire pour
  cet administrateur (il la réaffiche depuis la Mise en route).
- **Mise en route** : « ▶ Regarder » (commence au premier chapitre dont l'étape n'est pas faite) et
  « ▶ Voir comment faire » sur chaque étape qui a sa vidéo.
- Le lecteur : liste des chapitres, **Ouvrir ce réglage** (la page de l'étape) et **Chapitre suivant**.
- **Réglages › Entreprise › Guide vidéo** : afficher ou non la carte sur le tableau de bord de tous les
  administrateurs.

## Fichiers
`public/videos/guide/<clé>.mp4` (H.264 + AAC, 1280×720), `<clé>.jpg` (aperçu) et `<clé>.vtt` (sous-titres). Ils
font partie de l'image `web` ; Nginx les sert avec un cache de 7 jours.

## Refaire une vidéo (après un changement d'écran)
Tout est dans `tests/browser/tutorials` :
- `chapters.mjs` : pour chaque chapitre, les phrases de la voix off et ce qui se passe à l'écran pendant chacune.
- Voix off : le texte de `narration(chapitre)` (phrases séparées par une pause de 1,6 s), lu par une voix
  française (ElevenLabs, voix « Martial », modèle multilingual v2), enregistré en MP3.
- `split.mjs <mp3> <nombre de phrases>` : durée de chaque phrase (coupure au milieu des pauses).
- `record.mjs <clé> <durées> <dossier>` : filme l'application réelle (données de démonstration, serveur sur
  `http://127.0.0.1:8000`, compte administrateur `0700000001`), curseur animé, éléments entourés, écran titre ;
  chaque geste dure le temps de sa phrase.
- `mux.mjs <clé> <mp3> <dossier>` : pose chaque phrase au début de son geste, encode la vidéo, l'aperçu et les
  sous-titres dans `public/videos/guide`.

Si un sélecteur ne trouve plus son élément, `record.mjs` l'écrit (« introuvable ») : corriger `chapters.mjs`.
