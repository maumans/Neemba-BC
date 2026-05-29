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
        .summary-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .summary-table td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        .summary-table td:first-child { font-weight: bold; color: #555; width: 200px; }
        .summary-table td:last-child { text-align: right; }
        .total-row td { border-top: 2px solid #fdc911; font-weight: bold; font-size: 16px; }
        .footer { padding: 15px 20px; text-align: center; font-size: 11px; color: #999; border-top: 1px solid #eee; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-ok { background: #e8f5e9; color: #2e7d32; }
        .badge-warning { background: #fff3e0; color: #e65100; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Rapport Journalier de Caisse — NEEMBA</h1>
        </div>

        <div class="content">
            <p>Bonjour,</p>

            <p>Veuillez trouver ci-joint le rapport journalier de caisse pour :</p>

            <table class="summary-table">
                <tr>
                    <td>Date</td>
                    <td>{{ $rapport->date_rapport->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Site</td>
                    <td>{{ $rapport->site }}</td>
                </tr>
                <tr>
                    <td>Caissier</td>
                    <td>{{ $rapport->caissier ? $rapport->caissier->prenom . ' ' . $rapport->caissier->name : '-' }}</td>
                </tr>
                <tr>
                    <td>Statut</td>
                    <td>
                        <span class="badge {{ $rapport->cloture ? 'badge-ok' : 'badge-warning' }}">
                            {{ $rapport->cloture ? 'Clôturé' : 'Non clôturé' }}
                        </span>
                    </td>
                </tr>
            </table>

            <h3 style="color: #555; border-bottom: 2px solid #fdc911; padding-bottom: 5px;">Résumé financier</h3>

            <table class="summary-table">
                <tr>
                    <td>Solde d'ouverture</td>
                    <td>{{ number_format($rapport->solde_ouverture, 0, ',', ' ') }} GNF</td>
                </tr>
                <tr>
                    <td>Total entrées</td>
                    <td>{{ number_format($rapport->total_entrees, 0, ',', ' ') }} GNF</td>
                </tr>
                <tr>
                    <td>Total sorties ({{ $rapport->nombre_bons ?? $bonsPaye->count() }} bons)</td>
                    <td>{{ number_format($rapport->total_sorties, 0, ',', ' ') }} GNF</td>
                </tr>
                <tr class="total-row">
                    <td>Solde de clôture</td>
                    <td>{{ number_format($rapport->solde_cloture, 0, ',', ' ') }} GNF</td>
                </tr>
            </table>

            @if(!$rapport->cloture)
                <p style="color: #e65100; font-weight: bold;">
                    ⚠ Ce rapport n'a pas encore été clôturé par le caissier.
                </p>
            @endif

            <p>Les fichiers Excel et PDF sont joints à cet e-mail pour consultation détaillée.</p>

            <p>Cordialement,<br><strong>Système NEEMBA — Gestion de Caisse</strong></p>
        </div>

        <div class="footer">
            Cet e-mail a été généré automatiquement par le système NEEMBA le {{ now()->format('d/m/Y à H:i') }}.
        </div>
    </div>
</body>
</html>
