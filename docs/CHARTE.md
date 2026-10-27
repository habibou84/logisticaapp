# Charte Logistica et espace client

## Couleurs (par défaut, modifiables dans Réglages › Apparence)
| Couleur | Code | Usage |
|---|---|---|
| Rouge Logistica | `#EC1D27` | espace client : boutons, onglet actif, dégradés |
| Rouge foncé | `#C02125` | espace livreur (plus lisible en plein soleil), fin des dégradés, textes en couleur |
| Orange | `#F26A20` | accents, icône du colis |
| Graphite | `#1F2937` | espace équipe |
| Alerte | `#E0245E` | pastilles de la cloche, retards : distinct du rouge de la marque |

## Logo
- `public/brand/logistica.png` : logo affiché tant que l'entreprise n'a pas envoyé le sien (connexion,
  menu de l'espace client sur ordinateur, étiquettes).
- `public/brand/anneau.svg` : l'anneau seul (icône carrée, administration).
- Icônes d'application (`public/app-icons/`) : anneau en couleur sur fond blanc (client), en blanc sur rouge
  foncé (livreur). Un logo ou une icône envoyés dans Réglages › Apparence les remplacent.

## Espace client (style « dégradé doux », repris de Jibiat)
- Icônes en couleur : `components/mobile/ColorIcon.vue` et `colorIcons.js` (jeu de Jibiat, plus pli, lot,
  chèque, accusé, facture, département, forfait, livreur MAD, avis, en route, équipe).
- Cadre : `components/mobile/MerchantShell.vue` (en-tête avec la date, cloche qui ne compte que les colis à
  régler, onglets à pastille, bouton ＋ « Que voulez-vous envoyer ? » selon les activités du client, menu blanc à
  gauche sur ordinateur).
- Accueil : carte du jour en dégradé (en cours, livrées, à régler, retours : un appui filtre la liste),
  actions rapides selon les activités, « À faire » et « Mon compte » illustrés.
- Courses et fiche : icône par étape, suivi en 4 étapes illustré.
