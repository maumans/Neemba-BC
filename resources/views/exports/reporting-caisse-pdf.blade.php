<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>REPORTING CAISSE</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111827; margin: 22px 26px; }
        h1 { font-size: 15px; text-align: center; margin: 2px 0 10px; letter-spacing: 1px; }
        h2 { font-size: 10.5px; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .infos td, .bloc td, .lignes td, .lignes th { border: 1px solid #d1d5db; padding: 3px 5px; }
        .infos .lib, .bloc .lib { width: 34%; background: #f3f4f6; font-weight: bold; }
        .lignes th { background: #f3f4f6; text-align: left; }
        .droite { text-align: right; }
        .total td { font-weight: bold; background: #f9fafb; }
        .rouge { color: #b91c1c; font-weight: bold; }
        .discret { color: #6b7280; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .pied { margin-top: 10px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
@php($n = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' '))
@foreach ($journees as $j)
    <div class="page">
        <h1>REPORTING CAISSE</h1>
        <table class="infos">
            <tr><td class="lib">Société</td><td>{{ $societe['nom'] }}</td></tr>
            <tr><td class="lib">Adresse</td><td>{{ $societe['adresse'] }}</td></tr>
            <tr><td class="lib">Période</td><td>{{ $j['date'] }}</td></tr>
            <tr><td class="lib">Caisse</td><td>{{ $j['caisse'] }}{{ $j['implantation'] ? ' — ' . $j['implantation'] : '' }}</td></tr>
        </table>

        <h2>Contrôle</h2>
        <table class="bloc">
            <tr><td class="lib">Solde final de la veille{{ $j['date_veille'] ? ' (' . $j['date_veille'] . ')' : '' }}</td><td class="droite">{{ $j['solde_veille'] === null ? 'Aucune clôture précédente' : $n($j['solde_veille']) }}</td></tr>
            <tr><td class="lib">Solde initial du jour</td><td class="droite">{{ $n($j['solde_initial']) }}</td></tr>
            <tr><td class="lib">Écart</td><td class="droite {{ $j['ecart'] !== null && abs($j['ecart']) > 0.004 ? 'rouge' : '' }}">{{ $n($j['ecart']) }}</td></tr>
        </table>

        <h2>Synthèse</h2>
        <table class="bloc">
            <tr><td class="lib">Solde initial</td><td class="droite">{{ $n($j['solde_initial']) }}</td></tr>
            <tr><td class="lib">+ Encaissements</td><td class="droite">{{ $n($j['total_encaissements']) }}</td></tr>
            <tr><td class="lib">− Décaissements</td><td class="droite">{{ $n($j['total_decaissements']) }}</td></tr>
            <tr class="total"><td class="lib">Solde final</td><td class="droite">{{ $n($j['solde_final']) }}</td></tr>
        </table>

        <h2>Encaissements</h2>
        <table class="lignes">
            <thead><tr><th style="width: 16%">Date</th><th style="width: 20%">N° pièce</th><th>Libellé</th><th class="droite" style="width: 16%">Montant (GNF)</th></tr></thead>
            <tbody>
                @forelse ($j['encaissements'] as $e)
                    <tr><td>{{ $e['date'] }}</td><td>{{ $e['piece'] }}</td><td>{{ $e['libelle'] }}</td><td class="droite">{{ $n($e['montant']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="discret">Aucun encaissement.</td></tr>
                @endforelse
                <tr class="total"><td colspan="3">Total encaissements</td><td class="droite">{{ $n($j['total_encaissements']) }}</td></tr>
            </tbody>
        </table>

        <h2>Décaissements</h2>
        <table class="lignes">
            <thead><tr><th style="width: 16%">Date</th><th>Libellé</th><th style="width: 20%">N° pièce</th><th class="droite" style="width: 16%">Montant (GNF)</th></tr></thead>
            <tbody>
                @forelse ($j['decaissements'] as $d)
                    <tr><td>{{ $d['date'] }}</td><td>{{ $d['libelle'] }}</td><td>{{ $d['piece'] }}</td><td class="droite">{{ $n($d['montant']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="discret">Aucun décaissement.</td></tr>
                @endforelse
                <tr class="total"><td colspan="3">Total décaissements</td><td class="droite">{{ $n($j['total_decaissements']) }}</td></tr>
            </tbody>
        </table>

        @if (count($j['detail']) > 0)
            <h2>Détail des bons décaissés</h2>
            <table class="lignes">
                <thead>
                    <tr><th>N° de bon</th><th>Bénéficiaire</th><th>Service</th><th>Mode</th><th class="droite">Montant</th>
                        @foreach ($etapes as $libelle)<th>{{ $libelle }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($j['detail'] as $b)
                        <tr>
                            <td>{{ $b['numero'] }}</td><td>{{ $b['beneficiaire'] }}</td><td>{{ $b['service'] }}</td><td>{{ $b['mode'] }}</td><td class="droite">{{ $n($b['montant']) }}</td>
                            @foreach (array_keys($etapes) as $role)
                                <td>@if ($b['etapes'][$role]){{ $b['etapes'][$role]['valideur'] }}@if ($b['etapes'][$role]['delai'] !== null)<br><span class="discret">{{ str_replace('.', ',', (string) $b['etapes'][$role]['delai']) }} h</span>@endif @else <span class="discret">—</span> @endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="pied">Édité le {{ $edite_le }} — {{ $j['caisse'] }}, journée du {{ $j['date'] }}</p>
    </div>
@endforeach
</body>
</html>
