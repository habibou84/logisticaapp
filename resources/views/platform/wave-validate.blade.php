<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Valider un paiement Wave · Jibiat</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.5; }
        main { max-width: 28rem; margin: 0 auto; padding: 2.5rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 1.25rem; }
        dl { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); margin: 0; }
        dl div { display: flex; justify-content: space-between; gap: 1rem; padding: .35rem 0; }
        dt { color: #64748b; } dd { margin: 0; font-weight: 600; text-align: right; }
        .note { margin-top: 1.25rem; padding: 1rem; border-radius: .75rem; font-weight: 500; }
        .ok { background: #ecfdf5; color: #065f46; } .warn { background: #fffbeb; color: #92400e; } .err { background: #fef2f2; color: #b91c1c; }
        label { display: block; margin-top: 1.25rem; font-weight: 500; }
        input { display: block; width: 100%; box-sizing: border-box; margin-top: .35rem; padding: .7rem; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: .5rem; }
        button { display: block; width: 100%; margin-top: .75rem; padding: .85rem; font-size: 1rem; font-weight: 600; border-radius: .5rem; border: 0; cursor: pointer; }
        .yes { background: #047857; color: #fff; } .no { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
        p.help { color: #475569; font-size: .95rem; margin-top: 1.25rem; }
    </style>
</head>
<body>
<main>
    <h1>Paiement Wave à vérifier</h1>
    <dl>
        <div><dt>Montant</dt><dd>{{ $amount }}</dd></div>
        <div><dt>Numéro Wave</dt><dd>{{ $payment->payer_phone }}</dd></div>
        <div><dt>Entreprise</dt><dd>{{ $payment->company?->name }}</dd></div>
        <div><dt>Facture</dt><dd>{{ $payment->invoice?->number }} · {{ $payment->invoice?->period }}</dd></div>
        <div><dt>Annoncé le</dt><dd>{{ $payment->created_at->timezone('Africa/Abidjan')->format('d/m/Y H:i') }}</dd></div>
    </dl>

    @if ($payment->status === 'received')
        <p class="note ok">✓ Paiement confirmé : il est enregistré sur la facture.</p>
    @elseif ($payment->status === 'missing')
        <p class="note warn">Paiement marqué non reçu.</p>
    @else
        <p class="help">Vérifiez dans Wave que ce montant est bien arrivé depuis ce numéro, puis confirmez avec votre code.</p>
        @if ($error)
            <p class="note err">{{ $error }}</p>
        @endif
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label>Code de validation
                <input name="code" type="password" inputmode="numeric" autocomplete="one-time-code" required>
            </label>
            <button class="yes" name="decision" value="received">Paiement reçu : enregistrer</button>
            <button class="no" name="decision" value="missing">Paiement non reçu</button>
        </form>
    @endif
</main>
</body>
</html>
