<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $pv['numero'] }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111827; margin: 24px 28px; }
        h1 { font-size: 15px; text-align: center; margin: 4px 0 2px; letter-spacing: 1px; }
        .sous-titre { text-align: center; font-size: 9px; color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        .entete td { vertical-align: middle; }
        .infos td { padding: 4px 6px; border: 1px solid #d1d5db; }
        .infos .lib { width: 22%; background: #f3f4f6; font-weight: bold; }
        .billetage { margin-top: 12px; }
        .billetage th, .billetage td { border: 1px solid #d1d5db; padding: 4px 6px; }
        .billetage th { background: #f3f4f6; text-align: left; }
        .droite, .billetage th.droite { text-align: right; }
        .total td { font-weight: bold; background: #f9fafb; }
        .ecart-rouge { color: #b91c1c; font-weight: bold; }
        .signatures { margin-top: 22px; }
        .signatures td { width: 33%; border: 1px solid #d1d5db; padding: 6px; height: 70px; vertical-align: top; }
        .discret { color: #6b7280; font-size: 8.5px; }
        .pied { margin-top: 14px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td style="width: 30%">
                @if ($entite && file_exists($entite->cheminLogo()))
                    <img src="{{ $entite->cheminLogo() }}" alt="{{ $entite->raison_sociale ?? $entite->nom }}" style="max-width: 110px; max-height: 50px;">
                @else
                    <strong>{{ $entite->raison_sociale ?? 'NEEMBA GUINEE' }}</strong>
                @endif
            </td>
            <td class="droite discret">
                {{ $entite->raison_sociale ?? 'NEEMBA GUINEE' }}<br>
                @foreach (($entite?->lignesAdresse() ?? []) as $ligne){{ $ligne }}<br>@endforeach
            </td>
        </tr>
    </table>

    <h1>PROCÈS-VERBAL D'ARRÊTÉ DE CAISSE</h1>
    <p class="sous-titre">Annexe 2 de la procédure de gestion de caisse — {{ $pv['numero'] }}</p>

    <table class="infos">
        <tr><td class="lib">Date</td><td>{{ $pv['date'] }}</td><td class="lib">Heure</td><td>{{ $pv['heure'] }}</td></tr>
        <tr><td class="lib">Caisse</td><td>{{ $pv['caisse'] }}</td><td class="lib">Implantation</td><td>{{ $pv['implantation'] }}</td></tr>
        <tr><td class="lib">Motif</td><td>{{ $pv['motif'] }}</td><td class="lib">Établi par</td><td>{{ $pv['etabli_par'] }}</td></tr>
    </table>

    @if ($pv['type_caisse'] !== 'orange_money')
        <table class="billetage">
            <thead><tr><th>Coupure (GNF)</th><th class="droite">Nombre</th><th class="droite">Montant (GNF)</th></tr></thead>
            <tbody>
                @foreach ($pv['billetage'] as $ligne)
                    <tr>
                        <td>{{ number_format($ligne['coupure'], 0, ',', ' ') }}</td>
                        <td class="droite">{{ number_format($ligne['nombre'], 0, ',', ' ') }}</td>
                        <td class="droite">{{ number_format($ligne['montant'], 0, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="billetage">
        <tr class="total"><td>Solde physique {{ $pv['type_caisse'] === 'orange_money' ? '(lu sur la plateforme Orange Money)' : '(billetage)' }}</td><td class="droite">{{ $pv['solde_physique'] }}</td></tr>
        <tr class="total"><td>Solde comptable</td><td class="droite">{{ $pv['solde_comptable'] }}</td></tr>
        <tr class="total"><td>Écart (physique − comptable)</td><td class="droite {{ $pv['ecart'] != 0 ? 'ecart-rouge' : '' }}">{{ $pv['ecart_format'] }}</td></tr>
    </table>

    @if ($pv['observations'])
        <p><strong>Observations :</strong> {{ $pv['observations'] }}</p>
    @endif
    @if ($pv['ajustement'])
        <p class="discret">Ajustement d'inventaire {{ $pv['ajustement']['reference'] }} : {{ $pv['ajustement']['statut'] }} (validation du DAF avec motif).</p>
    @endif

    <table class="signatures">
        <tr>
            @foreach ($pv['signes'] as $signe)
                <td>
                    <strong>{{ $signe['libelle'] }}</strong><br>
                    @if ($signe['le'])
                        {{ $signe['par'] }}<br><span class="discret">Signé le {{ $signe['le'] }}</span>
                    @else
                        <span class="discret">Non signé</span>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <p class="pied">Édité le {{ $edite_le }} par la plateforme NEEMBA.</p>
</body>
</html>
