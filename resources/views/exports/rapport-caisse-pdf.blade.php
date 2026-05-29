<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport Journalier de Caisse — {{ $rapport->date_rapport->format('d/m/Y') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #fdc911; padding-bottom: 15px; }
        .header h1 { font-size: 18px; color: #1a1a1a; margin-bottom: 4px; }
        .header h2 { font-size: 13px; color: #666; font-weight: normal; }
        .meta { display: table; width: 100%; margin-bottom: 15px; }
        .meta-row { display: table-row; }
        .meta-label { display: table-cell; width: 140px; font-weight: bold; padding: 3px 10px 3px 0; color: #555; }
        .meta-value { display: table-cell; padding: 3px 0; }
        .section-title { background: #fdc911; color: #1a1a1a; padding: 6px 10px; font-size: 12px; font-weight: bold; margin: 15px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table th { background: #f5f5f5; border: 1px solid #ddd; padding: 5px 8px; text-align: left; font-size: 10px; text-transform: uppercase; color: #555; }
        table td { border: 1px solid #ddd; padding: 5px 8px; font-size: 10px; }
        table td.number { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .resume-table td:first-child { font-weight: bold; width: 200px; }
        .resume-table td:last-child { text-align: right; font-weight: bold; }
        .total-row { background: #fdf6e3; font-weight: bold; }
        .total-row td { border-top: 2px solid #fdc911; }
        .cloture-row { background: #e8f5e9; }
        .observations { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; margin-top: 10px; font-style: italic; }
        .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
        .visa-box { margin-top: 20px; border: 1px solid #ddd; padding: 10px; }
        .visa-box .visa-title { font-weight: bold; margin-bottom: 5px; }
        .visa-ok { background: #e8f5e9; border-color: #4caf50; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; }
        .badge-cloture { background: #e8f5e9; color: #2e7d32; }
        .badge-ouvert { background: #fff3e0; color: #e65100; }
    </style>
</head>
<body>

    <div class="header">
        <h1>RAPPORT JOURNALIER DE CAISSE</h1>
        <h2>NEEMBA — {{ $rapport->site }}</h2>
    </div>

    <div class="meta">
        <div class="meta-row">
            <span class="meta-label">Date du rapport :</span>
            <span class="meta-value">{{ $rapport->date_rapport->format('d/m/Y') }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Site :</span>
            <span class="meta-value">{{ $rapport->site }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Caissier :</span>
            <span class="meta-value">{{ $rapport->caissier ? $rapport->caissier->prenom . ' ' . $rapport->caissier->name : '-' }}</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Statut :</span>
            <span class="meta-value">
                <span class="badge {{ $rapport->visa_daf_id ? 'badge-cloture' : 'badge-ouvert' }}">
                    {{ $rapport->visa_daf_id ? 'Visé DAF' : 'En cours' }}
                </span>
            </span>
        </div>
    </div>

    {{-- RÉSUMÉ FINANCIER --}}
    <div class="section-title">RÉSUMÉ FINANCIER</div>
    <table class="resume-table">
        <tr>
            <td>Solde d'ouverture</td>
            <td class="number">{{ number_format($rapport->solde_ouverture, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr>
            <td>Total entrées</td>
            <td class="number">{{ number_format($rapport->total_entrees, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr>
            <td>Total sorties ({{ $rapport->nombre_bons ?? $bonsPaye->count() }} bons)</td>
            <td class="number">{{ number_format($rapport->total_sorties, 0, ',', ' ') }} GNF</td>
        </tr>
        <tr class="cloture-row">
            <td>Solde fin de journée</td>
            <td class="number">{{ number_format($rapport->solde_cloture, 0, ',', ' ') }} GNF</td>
        </tr>
    </table>

    {{-- VENTILATION PAR CATÉGORIE --}}
    @if(!empty($rapport->detail_par_categorie))
    <div class="section-title">VENTILATION PAR CATÉGORIE</div>
    <table>
        <thead>
            <tr>
                <th>Catégorie</th>
                <th style="text-align:center">Nombre</th>
                <th style="text-align:right">Montant (GNF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rapport->detail_par_categorie as $item)
            <tr>
                <td>{{ $item['label'] ?? $item['categorie'] }}</td>
                <td style="text-align:center">{{ $item['nombre'] }}</td>
                <td class="number">{{ number_format($item['montant'], 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- VENTILATION PAR MODE DE PAIEMENT --}}
    @if(!empty($rapport->detail_par_mode_paiement))
    <div class="section-title">VENTILATION PAR MODE DE PAIEMENT</div>
    <table>
        <thead>
            <tr>
                <th>Mode</th>
                <th style="text-align:center">Nombre</th>
                <th style="text-align:right">Montant (GNF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rapport->detail_par_mode_paiement as $item)
            <tr>
                <td>{{ $item['label'] ?? $item['mode'] }}</td>
                <td style="text-align:center">{{ $item['nombre'] }}</td>
                <td class="number">{{ number_format($item['montant'], 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- DÉTAIL DES BONS PAYÉS --}}
    @if($bonsPaye->count() > 0)
    <div class="section-title">DÉTAIL DES BONS PAYÉS ({{ $bonsPaye->count() }})</div>
    <table>
        <thead>
            <tr>
                <th>N° Bon</th>
                <th>Type</th>
                <th>Bénéficiaire</th>
                <th>Catégorie</th>
                <th>Mode</th>
                <th style="text-align:right">Montant (GNF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bonsPaye as $bon)
            <tr>
                <td>{{ $bon->numero }}</td>
                <td>{{ $bon->type_bon }}</td>
                <td>{{ $bon->beneficiaire }}</td>
                <td>{{ \App\Models\BonCaisse::CATEGORIES_DEPENSE[$bon->categorie_depense] ?? $bon->categorie_depense }}</td>
                <td>{{ \App\Models\BonCaisse::MODES_PAIEMENT[$bon->mode_paiement_effectif ?? $bon->mode_paiement] ?? ($bon->mode_paiement_effectif ?? $bon->mode_paiement) }}</td>
                <td class="number">{{ number_format($bon->montant, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5" style="text-align:right">TOTAL</td>
                <td class="number">{{ number_format($bonsPaye->sum('montant'), 0, ',', ' ') }} GNF</td>
            </tr>
        </tbody>
    </table>
    @endif

    {{-- OBSERVATIONS --}}
    @if($rapport->observations)
    <div class="section-title">OBSERVATIONS</div>
    <div class="observations">{{ $rapport->observations }}</div>
    @endif

    {{-- VISA DAF --}}
    <div class="visa-box {{ $rapport->visa_daf_id ? 'visa-ok' : '' }}">
        <div class="visa-title">Visa DAF</div>
        @if($rapport->visaDaf)
            <p>Visé par : {{ $rapport->visaDaf->prenom }} {{ $rapport->visaDaf->name }}</p>
            <p>Date : {{ $rapport->date_visa_daf ? $rapport->date_visa_daf->format('d/m/Y à H:i') : '-' }}</p>
        @else
            <p style="color: #999;">En attente de visa</p>
        @endif
    </div>

    <div class="footer">
        Rapport généré le {{ now()->format('d/m/Y à H:i') }} — NEEMBA Gestion de Caisse
    </div>

</body>
</html>
