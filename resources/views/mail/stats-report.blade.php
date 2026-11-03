@php($fmt = fn ($v) => $v === null ? '—' : number_format((int) $v, 0, ',', ' ').' F')
<!doctype html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f3f4f6; padding: 16px;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px;">
    <tr><td style="padding: 20px;">
        <h1 style="font-size: 18px; margin: 0 0 4px;">{{ $title }}</h1>
        <p style="margin: 0 0 16px; color: #6b7280;">{{ $company->name }} · du {{ \Carbon\Carbon::parse($s['period']['from'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($s['period']['to'])->format('d/m/Y') }}</p>
        <table width="100%" cellpadding="6" style="border-collapse: collapse; font-size: 14px;">
            <tr><td>Courses livrées</td><td align="right"><b>{{ $s['delivered'] }}</b></td></tr>
            <tr><td>Livrées à l'heure</td><td align="right"><b>{{ $s['on_time_rate'] === null ? '—' : str_replace('.', ',', $s['on_time_rate']).' %' }}</b></td></tr>
            <tr><td>Échecs de livraison</td><td align="right"><b>{{ $s['failures'] }}</b></td></tr>
            @if($s['money'])
                <tr><td>Revenus</td><td align="right"><b>{{ $fmt($s['money']['revenue']) }}</b></td></tr>
                <tr><td>Coûts directs et frais fixes</td><td align="right">{{ $fmt($s['money']['direct_costs'] + $s['money']['fixed_costs']) }}</td></tr>
                <tr><td>Marge nette</td><td align="right"><b style="color: {{ $s['money']['margin'] < 0 ? '#b91c1c' : '#047857' }}">{{ $fmt($s['money']['margin']) }}</b>{{ $s['money']['margin_rate'] === null ? '' : ' ('.str_replace('.', ',', $s['money']['margin_rate']).' %)' }}</td></tr>
                <tr><td>Coût par course livrée</td><td align="right">{{ $fmt($s['money']['cost_per_delivery']) }}</td></tr>
            @endif
        </table>
        @if(count($s['top_reasons']))
            <p style="margin: 16px 0 4px; font-weight: bold;">Principaux motifs d'échec</p>
            <ul style="margin: 0; padding-left: 18px;">@foreach($s['top_reasons'] as $r)<li>{{ $r['label'] }} : {{ $r['count'] }}</li>@endforeach</ul>
        @endif
        @if(count($s['losing_clients']))
            <p style="margin: 16px 0 4px; font-weight: bold; color: #b91c1c;">Clients à perte</p>
            <ul style="margin: 0; padding-left: 18px;">@foreach($s['losing_clients'] as $c)<li>{{ $c['name'] }} : {{ $fmt($c['margin']) }}</li>@endforeach</ul>
        @endif
        <p style="margin: 20px 0 0;">Le détail complet est dans le fichier Excel joint. <a href="{{ $url }}">Ouvrir les statistiques</a></p>
    </td></tr>
</table>
</body>
</html>
