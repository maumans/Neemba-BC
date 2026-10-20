<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #fdc911; padding: 15px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 18px; color: #1a1a1a; }
        .content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        td:first-child { font-weight: bold; color: #555; width: 220px; }
        td:last-child { text-align: right; }
        .total td { border-top: 2px solid #fdc911; font-weight: bold; }
        .rouge { color: #b91c1c; font-weight: bold; }
        .footer { padding: 15px 20px; text-align: center; font-size: 11px; color: #999; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    @php($n = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' GNF')
    <div class="container">
        <div class="header"><h1>REPORTING CAISSE — {{ $caisse }}</h1></div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Veuillez trouver ci-joint le rapport de la {{ $caisse }} pour la journée du {{ $date }}, en PDF et en Excel.</p>
            <table>
                <tr><td>Solde initial</td><td>{{ $n($cloture->solde_initial) }}</td></tr>
                <tr><td>+ Encaissements</td><td>{{ $n($cloture->total_encaissements) }}</td></tr>
                <tr><td>− Décaissements</td><td>{{ $n($cloture->total_decaissements) }}</td></tr>
                <tr class="total"><td>Solde final</td><td>{{ $n($cloture->solde_final) }}</td></tr>
                @if ($cloture->ecart_controle !== null && abs((float) $cloture->ecart_controle) > 0.004)
                    <tr><td>Écart avec la veille</td><td class="rouge">{{ $n($cloture->ecart_controle) }}</td></tr>
                @endif
            </table>
            <p>Journée clôturée {{ $cloture->automatique ? 'automatiquement' : 'par ' . ($cloture->cloturePar?->nom_complet ?? 'le gérant') }} le {{ $cloture->cloture_le->format('d/m/Y à H:i') }}.</p>
        </div>
        <div class="footer">Message envoyé automatiquement par la plateforme NEEMBA.</div>
    </div>
</body>
</html>
