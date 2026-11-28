<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Statistiques · {{ $company->name }}</title>
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #0f172a; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .meta { color: #475569; margin: 0 0 12px; font-size: 10px; }
    h2 { font-size: 12px; margin: 14px 0 4px; }
    table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #f1f5f9; text-align: left; padding: 4px 5px; border-bottom: 1px solid #cbd5e1; font-size: 8px; }
    td { padding: 3px 5px; border-bottom: 1px solid #e2e8f0; }
    .n { text-align: right; white-space: nowrap; }
    .note { color: #64748b; font-size: 8px; margin: 3px 0 0; }
</style>
</head>
<body>
    <h1>Statistiques · {{ $company->name }}</h1>
    <p class="meta">Du {{ $period }}@if ($activity) · {{ $activity }}@endif · édité le {{ now($company->timezone ?: config('app.timezone'))->format('d/m/Y à H:i') }}</p>
    @foreach ($tables as $table)
        <h2>{{ $table['name'] }}</h2>
        <table>
            <thead><tr>@foreach ($table['header'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($table['rows'] as $row)
                <tr>@foreach ($row as $v)<td @class(['n' => is_int($v) || is_float($v)])>{{ is_int($v) ? number_format($v, 0, ',', ' ') : (is_float($v) ? number_format($v, 1, ',', ' ') : $v) }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($table['header']) }}">Rien sur la période.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($table['total'] > count($table['rows']))
            <p class="note">{{ count($table['rows']) }} lignes affichées sur {{ $table['total'] }} : exportez en Excel ou en CSV pour la liste complète.</p>
        @endif
    @endforeach
</body>
</html>
