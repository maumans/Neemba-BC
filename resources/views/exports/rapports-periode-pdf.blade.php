<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Synthèse Rapports de Caisse — {{ $dateDebut }} au {{ $dateFin }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #fdc911; padding-bottom: 15px; }
        .header h1 { font-size: 16px; color: #1a1a1a; margin-bottom: 4px; }
        .header h2 { font-size: 12px; color: #666; font-weight: normal; }
        .meta { margin-bottom: 15px; }
        .meta p { margin: 3px 0; }
        .meta strong { color: #555; }
        .section-title { background: #fdc911; color: #1a1a1a; padding: 6px 10px; font-size: 11px; font-weight: bold; margin: 15px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table th { background: #f5f5f5; border: 1px solid #ddd; padding: 4px 6px; text-align: left; font-size: 9px; text-transform: uppercase; color: #555; }
        table td { border: 1px solid #ddd; padding: 4px 6px; font-size: 9px; }
        table td.number { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .resume-table td:first-child { font-weight: bold; width: 220px; }
        .resume-table td:last-child { text-align: right; font-weight: bold; }
        .total-row { background: #fdf6e3; font-weight: bold; }
        .total-row td { border-top: 2px solid #fdc911; }
        .cloture { color: #2e7d32; font-weight: bold; }
        .ouvert { color: #e65100; }
        .footer { margin-top: 30px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>SYNTHÈSE DES RAPPORTS DE CAISSE</h1>
        <h2>NEEMBA — Période du {{ $dateDebut }} au {{ $dateFin }}</h2>
    </div>

    <div class="meta">
        <p><strong>Nombre de rapports :</strong> {{ $rapports->count() }}</p>
        <p><strong>Rapport généré le :</strong> {{ now()->format('d/m/Y à H:i') }}</p>
    </div>

    {{-- RÉSUMÉ GLOBAL --}}
    <div class="section-title">RÉSUMÉ GLOBAL DE LA PÉRIODE</div>
    <table class="resume-table">
        <tr>
            <td>Total entrées</td>
            <td class="number">{{ number_format($rapports->sum('total_entrees'), 0, ',', ' ') }} GNF</td>
        </tr>
        <tr>
            <td>Total sorties</td>
            <td class="number">{{ number_format($rapports->sum('total_sorties'), 0, ',', ' ') }} GNF</td>
        </tr>
        <tr>
            <td>Nombre total de bons payés</td>
            <td class="number">{{ $rapports->sum('nombre_bons') }}</td>
        </tr>
        @if($rapports->count() > 0)
        @php
            $premier = $rapports->sortBy('date_rapport')->first();
            $dernier = $rapports->sortByDesc('date_rapport')->first();
        @endphp
        <tr>
            <td>Solde ouverture (début période)</td>
            <td class="number">{{ number_format($premier->solde_ouverture, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr style="background: #e8f5e9;">
            <td>Solde clôture (fin période)</td>
            <td class="number">{{ number_format($dernier->solde_cloture, 0, ',', ' ') }} GNF</td>
        </tr>
        @endif
    </table>

    {{-- DÉTAIL PAR RAPPORT --}}
    <div class="section-title">DÉTAIL PAR RAPPORT</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Site</th>
                <th>Caissier</th>
                <th style="text-align:right">Solde ouv.</th>
                <th style="text-align:right">Entrées</th>
                <th style="text-align:right">Sorties</th>
                <th style="text-align:right">Solde clôt.</th>
                <th style="text-align:center">Bons</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rapports->sortBy('date_rapport') as $rapport)
            <tr>
                <td>{{ $rapport->date_rapport->format('d/m/Y') }}</td>
                <td>{{ $rapport->site }}</td>
                <td>{{ $rapport->caissier ? $rapport->caissier->prenom . ' ' . $rapport->caissier->name : '-' }}</td>
                <td class="number">{{ number_format($rapport->solde_ouverture, 0, ',', ' ') }}</td>
                <td class="number">{{ number_format($rapport->total_entrees, 0, ',', ' ') }}</td>
                <td class="number">{{ number_format($rapport->total_sorties, 0, ',', ' ') }}</td>
                <td class="number">{{ number_format($rapport->solde_cloture, 0, ',', ' ') }}</td>
                <td style="text-align:center">{{ $rapport->nombre_bons ?? 0 }}</td>
                <td>
                    <span class="{{ $rapport->cloture ? 'cloture' : 'ouvert' }}">
                        {{ $rapport->cloture ? 'Clôturé' : 'En cours' }}
                    </span>
                </td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4" style="text-align:right">TOTAL</td>
                <td class="number">{{ number_format($rapports->sum('total_entrees'), 0, ',', ' ') }} GNF</td>
                <td class="number">{{ number_format($rapports->sum('total_sorties'), 0, ',', ' ') }} GNF</td>
                <td></td>
                <td style="text-align:center">{{ $rapports->sum('nombre_bons') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Synthèse générée le {{ now()->format('d/m/Y à H:i') }} — NEEMBA Gestion de Caisse
    </div>

</body>
</html>
