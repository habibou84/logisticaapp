# TVA, identité fiscale et avoirs

Préparation de la facture normalisée électronique (FNE) : les factures du mois des clients (distribution, MAD)
portent la TVA, le NCC de l'entreprise et du client, et se corrigent par avoir.

## Réglages › Facturation et TVA
- **Identité fiscale** (droit « Réglages généraux ») : raison sociale, NCC, RCCM, régime d'imposition, centre des
  impôts. Imprimés en tête des factures et des avoirs.
- **TVA** (droit « Calculer, émettre et encaisser les factures ») : activée ou non (désactivée par défaut), taux
  (18 % par défaut), prix saisis **hors taxe** (la TVA s'ajoute) ou **TTC** (la TVA est dedans), mention imprimée
  pour un client exonéré.
- La TVA s'applique aux brouillons recalculés et aux factures émises à partir du réglage ; une facture émise garde
  son taux, ses montants et l'identité du client du jour de l'émission.

## Fiche du client › Facturation
Raison sociale, NCC, RCCM, adresse de facturation ; **Exonéré de TVA** avec le numéro de l'attestation et sa date
de fin (après cette date, la TVA s'applique de nouveau).

## Facture
Total hors taxe, TVA, total TTC (à payer) ; mention d'exonération ; avoirs ; reste à payer ; montant à rembourser.
L'export Excel ajoute le hors taxe et la TVA. Le chiffre d'affaires est compté **hors taxe**.

## Avoirs
Facture émise ou payée › **🧾 Faire un avoir** (droit « Annuler une facture émise ou faire un avoir ») :
- **total** : tout ce qui reste sur la facture ; une facture non payée devient « Annulée par avoir » ;
- **partiel** : des lignes de la facture (jamais plus que facturé, moins ce qui a déjà été repris) et un montant
  libre (geste commercial, erreur).

Même TVA que la facture ; numéro **AV-AAAAMM-0001**. L'avoir réduit le reste à payer ; sur une facture déjà payée,
la différence est **à rembourser** au client. Il vient en moins du chiffre d'affaires du mois où il est fait. Le
client est prévenu et le voit sur sa facture ; chaque avoir s'imprime à part. Une facture qui a un avoir ne
s'annule plus : on fait un avoir pour le reste.

## Facture normalisée électronique (FNE)
Selon la procédure d'interfaçage par API de la DGI (mai 2025). **Réglages › Facturation et TVA › FNE** :
activation, environnement **test** (`http://54.247.95.108/ws`) ou **production** (URL donnée par la DGI), clé API
(espace FNE › Paramétrage ; chiffrée, jamais réaffichée), point de vente, établissement, mode de paiement déclaré
(« à terme » par défaut), message commercial, pied de facture. Stickers restants affichés.

- **Émission** : la facture est envoyée à la FNE (`external/invoices/sign`). Certifiée : référence FNE et QR code
  de vérification imprimés (sticker). Refusée (données, clé) : la facture reste en brouillon, avec le message de la
  FNE. FNE injoignable : la facture est émise « à certifier » et repart toute seule (toutes les 10 minutes) ou
  avec **Certifier maintenant**.
- Avant l'envoi : point de vente et établissement, e-mail et téléphone du client, NCC pour une facture **B2B**,
  taux 18 % (TVA) ou 9 % (TVAB).
- Fiche du client : modèle **B2B** (automatique avec un NCC), **B2C**, **B2G** ; exonération **conventionnelle**
  (TVAC) ou **légale** (TVAD). Entreprise sans TVA : TVAD.
- Lignes envoyées en prix unitaire hors taxe (prix TTC convertis) ; lignes « inclus » à 0 F non envoyées ;
  remises (lignes négatives) en pourcentage du total hors taxe.
- **Avoirs** : certifiés à leur création (`external/invoices/{id}/refund`), article par article (lignes et
  quantités) ; pas de montant libre sur une facture normalisée. Pas d'avoir tant que la facture n'est pas
  certifiée. Une facture normalisée ne s'annule pas.

Mise en service : inscription sur la plateforme de test, réglages en mode test, factures et avoirs d'essai,
spécimens envoyés à support.fne@dgi.gouv.ci, puis URL de production et clé de production.
