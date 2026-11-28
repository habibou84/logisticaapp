<!doctype html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f3f4f6; padding: 16px;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px;">
    <tr><td style="padding: 20px;">
        <h1 style="font-size: 18px; margin: 0 0 4px;">{{ count($alerts) === 1 ? 'Une alerte à regarder' : count($alerts).' alertes à regarder' }}</h1>
        <p style="margin: 0 0 16px; color: #6b7280;">{{ $company->name }} · {{ now($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}</p>
        @foreach($alerts as $a)
            <div style="border-left: 4px solid #d97706; padding: 4px 0 4px 12px; margin: 0 0 14px;">
                <p style="margin: 0; font-weight: bold;">{{ $a['title'] }}</p>
                <p style="margin: 2px 0 4px;">{{ ucfirst($a['body']) }}.</p>
                <a href="{{ $a['url'] }}">Ouvrir dans l'application</a>
            </div>
        @endforeach
        <p style="margin: 16px 0 0; color: #6b7280; font-size: 12px;">Une même alerte n'est pas renvoyée avant 7 jours. Les alertes se règlent dans Statistiques › Outils › Alertes.</p>
    </td></tr>
</table>
</body>
</html>
