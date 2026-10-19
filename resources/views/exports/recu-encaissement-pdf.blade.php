<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $e->numero }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111827; margin: 20px 26px; }
        h1 { font-size: 14px; text-align: center; margin: 6px 0 2px; letter-spacing: 1px; }
        .sous-titre { text-align: center; font-size: 9px; color: #6b7280; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        .infos td { padding: 5px 6px; border: 1px solid #d1d5db; }
        .infos .lib { width: 26%; background: #f3f4f6; font-weight: bold; }
        .montant { font-size: 13px; font-weight: bold; }
        .signatures { margin-top: 18px; }
        .signatures td { width: 50%; border: 1px solid #d1d5db; padding: 6px; height: 55px; vertical-align: top; }
        .discret { color: #6b7280; font-size: 8.5px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 35%">
                @if ($entite && file_exists($entite->cheminLogo()))
                    <img src="{{ $entite->cheminLogo() }}" alt="{{ $entite->raison_sociale ?? $entite->nom }}" style="max-width: 100px; max-height: 44px;">
                @else
                    <strong>{{ $entite->raison_sociale ?? 'NEEMBA GUINEE' }}</strong>
                @endif
            </td>
            <td style="text-align: right" class="discret">
                {{ $entite->raison_sociale ?? 'NEEMBA GUINEE' }}<br>
                @foreach (($entite?->lignesAdresse() ?? []) as $ligne){{ $ligne }}<br>@endforeach
            </td>
        </tr>
    </table>

    <h1>REÇU D'ENCAISSEMENT CLIENT</h1>
    <p class="sous-titre">N° {{ $e->numero }} — {{ $e->caisse->libelle }}</p>

    <table class="infos">
        <tr><td class="lib">Date</td><td>{{ \App\Support\Format::dateHeure($e->encaisse_le) }}</td></tr>
        <tr><td class="lib">Client</td><td>{{ $e->client->raison_sociale }}@if ($e->client->code) <span class="discret">({{ $e->client->code }})</span>@endif</td></tr>
        <tr><td class="lib">N° de pièce (OR ou facture)</td><td>{{ $e->numero_piece }}</td></tr>
        <tr><td class="lib">Mode</td><td>{{ \App\Models\EncaissementClient::MODES[$e->mode] ?? $e->mode }}</td></tr>
        <tr><td class="lib">Montant</td><td class="montant">{{ \App\Support\Format::montant($e->montant) }}</td></tr>
        <tr><td class="lib">En lettres</td><td>{{ ucfirst($montant_lettres) }}</td></tr>
        @if ($e->observations)
            <tr><td class="lib">Observations</td><td>{{ $e->observations }}</td></tr>
        @endif
    </table>

    <table class="signatures">
        <tr>
            <td><strong>Reçu par (caisse)</strong><br>{{ $e->encaissePar?->nom_complet }}</td>
            <td><strong>Versé par (client)</strong><br><span class="discret">Nom et signature</span></td>
        </tr>
    </table>
    <p class="discret" style="text-align: center; margin-top: 10px">Édité le {{ $edite_le }} par la plateforme NEEMBA.</p>
</body>
</html>
