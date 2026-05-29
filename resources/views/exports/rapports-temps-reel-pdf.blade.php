<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de Caisse — {{ $dateDebut }} au {{ $dateFin }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 15px; border-bottom: 3px solid #fdc911; padding-bottom: 12px; }
        .header h1 { font-size: 16px; color: #1a1a1a; margin-bottom: 4px; }
        .header h2 { font-size: 12px; color: #666; font-weight: normal; }
        .meta { margin-bottom: 12px; }
        .meta p { margin: 2px 0; font-size: 9px; }
        .meta strong { color: #555; }
        .section-title { background: #fdc911; color: #1a1a1a; padding: 5px 10px; font-size: 11px; font-weight: bold; margin: 12px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table th { background: #f5f5f5; border: 1px solid #ddd; padding: 4px 6px; text-align: left; font-size: 9px; text-transform: uppercase; color: #555; }
        table td { border: 1px solid #ddd; padding: 3px 6px; font-size: 9px; }
        table td.number { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .resume-table { margin-bottom: 10px; }
        .resume-table td { padding: 4px 8px; }
        .resume-table td:first-child { font-weight: bold; width: 200px; }
        .resume-table td:last-child { text-align: right; font-weight: bold; }
        .total-row { background: #fdf6e3; font-weight: bold; }
        .total-row td { border-top: 2px solid #fdc911; }
        .positive { color: #2e7d32; }
        .negative { color: #c62828; }
        .muted { color: #999; }
        .footer { margin-top: 20px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>RAPPORT DE CAISSE — NEEMBA</h1>
        <h2>Période du {{ $dateDebut }} au {{ $dateFin }}</h2>
    </div>

    <div class="meta">
        @if($site)
        <p><strong>Site :</strong> {{ $site }}</p>
        @endif
        <p><strong>Édité le :</strong> {{ now()->format('d/m/Y à H:i') }}</p>
    </div>

    {{-- RÉSUMÉ DE LA PÉRIODE --}}
    <div class="section-title">RÉSUMÉ DE LA PÉRIODE</div>
    <table class="resume-table">
        <tr>
            <td>Bons payés</td>
            <td class="number">{{ $statsResume['total_bons'] ?? 0 }}</td>
        </tr>
        <tr>
            <td>Total entrées (approvisionnements)</td>
            <td class="number positive">{{ number_format($statsResume['total_entrees'] ?? 0, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr>
            <td>Total sorties (décaissements)</td>
            <td class="number negative">{{ number_format($statsResume['total_sorties'] ?? 0, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr style="background: #e8f5e9;">
            <td>Solde de clôture</td>
            <td class="number" style="font-size: 11px;">{{ number_format($statsResume['solde_actuel'] ?? 0, 0, ',', ' ') }} GNF</td>
        </tr>
    </table>

    {{-- DÉTAIL PAR PÉRIODE --}}
    <div class="section-title">DÉTAIL {{ $granularite === 'jour' ? 'JOURNALIER' : ($granularite === 'mois' ? 'MENSUEL' : 'ANNUEL') }}</div>
    <table>
        <thead>
            <tr>
                <th>Période</th>
                @if($granularite === 'jour')
                <th>Jour</th>
                @endif
                <th style="text-align:center">Bons</th>
                <th style="text-align:right">Entrées (GNF)</th>
                <th style="text-align:right">Sorties (GNF)</th>
                <th style="text-align:right">Solde (GNF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $ligne)
            <tr>
                <td>{{ $ligne['label'] ?? $ligne['periode'] }}</td>
                @if($granularite === 'jour')
                <td>{{ $ligne['jour_semaine'] ?? '' }}</td>
                @endif
                <td style="text-align:center">
                    @if(($ligne['nombre_bons'] ?? 0) > 0)
                        {{ $ligne['nombre_bons'] }}
                    @else
                        <span class="muted">-</span>
                    @endif
                </td>
                <td class="number">
                    @if(($ligne['entrees'] ?? 0) > 0)
                        <span class="positive">{{ number_format($ligne['entrees'], 0, ',', ' ') }}</span>
                    @else
                        <span class="muted">-</span>
                    @endif
                </td>
                <td class="number">
                    @if(($ligne['sorties'] ?? 0) > 0)
                        <span class="negative">{{ number_format($ligne['sorties'], 0, ',', ' ') }}</span>
                    @else
                        <span class="muted">-</span>
                    @endif
                </td>
                <td class="number" style="font-weight: bold;">
                    <span class="{{ ($ligne['solde'] ?? 0) < 0 ? 'negative' : '' }}">
                        {{ number_format($ligne['solde'] ?? 0, 0, ',', ' ') }}
                    </span>
                </td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td @if($granularite === 'jour') colspan="2" @endif style="text-align:right">TOTAL</td>
                <td style="text-align:center">{{ $statsResume['total_bons'] ?? 0 }}</td>
                <td class="number positive">{{ number_format($statsResume['total_entrees'] ?? 0, 0, ',', ' ') }}</td>
                <td class="number negative">{{ number_format($statsResume['total_sorties'] ?? 0, 0, ',', ' ') }}</td>
                <td class="number" style="font-weight:bold;">{{ number_format($statsResume['solde_actuel'] ?? 0, 0, ',', ' ') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        NEEMBA Gestion de Caisse — Rapport édité le {{ now()->format('d/m/Y à H:i') }}
    </div>

</body>
</html>
