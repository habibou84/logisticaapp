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

## Suite : FNE
Avec la clé API de la DGI : certification de chaque facture et de chaque avoir à l'émission (référence et QR code
imprimés), en mode test puis en production.
