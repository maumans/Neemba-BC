<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de Caisse {{ $bon->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #333; padding: 15px 25px; }

        /* En-tête procédure */
        .procedure-header { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .procedure-header td { border: 1px solid #999; padding: 3px 6px; font-size: 8px; }
        .procedure-header .title { text-align: center; font-weight: bold; font-size: 10px; background: #f5f5f5; }
        .procedure-header .logo-cell { width: 120px; text-align: center; font-weight: bold; font-size: 11px; background: #fdc911; color: #1a1a1a; }

        /* Titre principal */
        .main-title { text-align: center; font-size: 14px; font-weight: bold; margin: 12px 0 4px; text-decoration: underline; }
        .sub-title { text-align: center; font-size: 10px; margin-bottom: 2px; }
        .type-bon { text-align: center; margin-bottom: 10px; font-size: 10px; }
        .type-bon .checkbox { display: inline-block; width: 12px; height: 12px; border: 1px solid #333; margin-right: 4px; vertical-align: middle; text-align: center; font-size: 9px; line-height: 12px; }
        .type-bon .checked { background: #fdc911; font-weight: bold; }

        /* Numéro du bon */
        .numero-bon { text-align: center; margin-bottom: 10px; font-size: 11px; }
        .numero-bon .numero { font-weight: bold; font-size: 14px; letter-spacing: 1px; border: 2px solid #333; padding: 2px 10px; display: inline-block; background: #fffde7; }

        /* Sites et services */
        .sites-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .sites-table td { padding: 2px 4px; font-size: 9px; vertical-align: middle; }
        .sites-table .checkbox { display: inline-block; width: 11px; height: 11px; border: 1px solid #333; margin-right: 3px; vertical-align: middle; text-align: center; font-size: 8px; line-height: 11px; }
        .sites-table .checked { background: #fdc911; font-weight: bold; }

        /* Informations principales */
        .info-section { margin-bottom: 8px; }
        .info-row { margin-bottom: 4px; font-size: 10px; }
        .info-label { font-weight: bold; display: inline-block; }
        .info-value { border-bottom: 1px solid #999; display: inline-block; min-width: 200px; padding: 0 4px; }
        .info-value-box { border: 1px solid #999; display: inline-block; padding: 2px 8px; min-width: 150px; font-weight: bold; background: #fffde7; }

        /* Montant */
        .montant-section { margin: 10px 0; }
        .montant-chiffre { font-size: 11px; margin-bottom: 4px; }
        .montant-lettres { font-size: 10px; font-style: italic; }

        /* Tableau des visas */
        .visa-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .visa-table th, .visa-table td { border: 1px solid #999; padding: 4px 6px; font-size: 9px; text-align: left; }
        .visa-table th { background: #f5f5f5; font-weight: bold; text-align: center; font-size: 8px; text-transform: uppercase; }
        .visa-table .visa-cell { min-height: 50px; vertical-align: top; }
        .visa-label { font-weight: bold; font-size: 8px; color: #555; }
        .visa-value { font-size: 9px; margin-top: 2px; }
        .visa-status { font-size: 8px; padding: 1px 4px; border-radius: 2px; display: inline-block; margin-top: 2px; }
        .visa-approuve { background: #e8f5e9; color: #2e7d32; }
        .visa-attente { background: #fff3e0; color: #e65100; }
        .visa-rejete { background: #ffebee; color: #c62828; }

        /* Champ comptabilité */
        .comptabilite { margin-top: 15px; border: 1px solid #999; }
        .comptabilite-titre { background: #f5f5f5; padding: 4px 8px; font-weight: bold; font-size: 9px; text-align: center; border-bottom: 1px solid #999; }
        .comptabilite-table { width: 100%; border-collapse: collapse; }
        .comptabilite-table th, .comptabilite-table td { border: 1px solid #ddd; padding: 3px 6px; font-size: 9px; }
        .comptabilite-table th { background: #fafafa; font-size: 8px; }

        /* Notes de bas */
        .footnotes { margin-top: 12px; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 6px; }
        .footnotes p { margin-bottom: 2px; }

        /* Signatures */
        .signatures { margin-top: 10px; display: table; width: 100%; }
        .signatures .sig { display: table-cell; width: 50%; font-size: 9px; }

        .footer { margin-top: 15px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 6px; }

        .date-line { text-align: right; font-size: 10px; margin-bottom: 6px; }
    </style>
</head>
<body>

    {{-- EN-TÊTE PROCÉDURE --}}
    <table class="procedure-header">
        <tr>
            <td class="logo-cell" rowspan="3" style="vertical-align: middle; padding: 5px;">
                @if(file_exists(public_path('logo.png')))
                    <img src="{{ public_path('logo.png') }}" alt="NEEMBA CAT" style="max-width: 100px; max-height: 50px;">
                @else
                    NEEMBA &nbsp; CAT
                @endif
            </td>
            <td class="title" colspan="2">PROCÉDURE DE GESTION DE CAISSE</td>
            <td>Processus</td>
            <td>Finance</td>
        </tr>
        <tr>
            <td colspan="2" style="text-align:center;">Système de Management</td>
            <td>Démarche</td>
            <td>Service</td>
        </tr>
        <tr>
            <td>Qualité</td>
            <td>Santé &amp; Sécurité</td>
            <td>Environnement</td>
            <td>Trésorerie</td>
        </tr>
    </table>

    {{-- TITRE --}}
    <div class="main-title">AUTORISATION DE DEPENSES DE CAISSE</div>

    {{-- TYPE BON --}}
    <div class="type-bon">
        <span>(1) BON PROVISOIRE</span>
        <span class="checkbox {{ $bon->type_bon === 'BP' ? 'checked' : '' }}">{{ $bon->type_bon === 'BP' ? '✓' : '' }}</span>
        &nbsp;&nbsp;&nbsp;
        <span>(2) BON DEFINITIF</span>
        <span class="checkbox {{ $bon->type_bon === 'BD' ? 'checked' : '' }}">{{ $bon->type_bon === 'BD' ? '✓' : '' }}</span>
    </div>

    {{-- NUMÉRO --}}
    <div class="numero-bon">
        BON {{ $bon->type_bon === 'BD' ? 'DEFINITIF' : 'PROVISOIRE' }} DE SORTIE DE CAISSE N°
        <span class="numero">{{ $bon->numero }}</span>
    </div>

    {{-- DATE --}}
    <div class="date-line">
        <strong>DATE :</strong> {{ $bon->date_demande ? $bon->date_demande->format('d / m / Y') : now()->format('d / m / Y') }}
    </div>

    {{-- SITES --}}

    <table class="sites-table">
        <tr>
            @foreach($sitesListe as $s)
            <td>
                {{ $s }} :
                <span class="checkbox {{ strtoupper($bon->site ?? '') === strtoupper($s) ? 'checked' : '' }}">{{ strtoupper($bon->site ?? '') === strtoupper($s) ? '✓' : '' }}</span>
            </td>
            @endforeach
        </tr>
        <tr>
            @foreach($servicesListe as $srv)
            <td>
                {{ $srv }} :
                <span class="checkbox {{ strtoupper($bon->service ?? '') === strtoupper($srv) ? 'checked' : '' }}">{{ strtoupper($bon->service ?? '') === strtoupper($srv) ? '✓' : '' }}</span>
            </td>
            @endforeach
            <td>
                AUTRES :
                @if(!in_array(strtoupper($bon->site ?? ''), array_map('strtoupper', $sitesListe)))
                    <span class="checkbox checked">✓</span> {{ $bon->site }}
                @else
                    <span class="checkbox"></span>
                @endif
            </td>
        </tr>
    </table>

    {{-- CODE ANALYTIQUE --}}
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">CODE ANALYTIQUE :</span>
            <span class="info-value">{{ $bon->code_analytique ?? '—' }}</span>
        </div>
    </div>

    {{-- BÉNÉFICIAIRE --}}
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">BENEFICIAIRE :</span>
            <span class="info-value" style="min-width: 350px;">{{ $bon->beneficiaire }}</span>
        </div>
    </div>

    {{-- MOTIF --}}
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">MOTIF DE LA DEPENSE :</span>
        </div>
        <div style="border: 1px solid #999; padding: 6px 8px; min-height: 30px; margin-top: 2px;">
            {{ $bon->motif }}
        </div>
    </div>

    {{-- MONTANT --}}
    <div class="montant-section">
        <div class="montant-chiffre">
            <span class="info-label">MONTANT TOTAL (en chiffre) :</span>
            <span class="info-value-box">{{ number_format((float)$bon->montant, 0, ',', ' ') }} {{ $bon->devise ?? 'GNF' }}</span>
        </div>
        <div class="montant-lettres">
            <span class="info-label">(En lettres) :</span>
            <span class="info-value" style="min-width: 400px; font-style: italic;">{{ $bon->montant_lettres ?? '—' }}</span>
        </div>
    </div>

    {{-- TABLEAU DES VISAS --}}
    <table class="visa-table">
        <thead>
            <tr>
                <th style="width: 20%;">DEMANDEUR</th>
                <th style="width: 20%;">CHEF DE SER</th>
                <th style="width: 20%;">RCDG</th>
                <th style="width: 20%;">DAF</th>
                <th style="width: 20%;">DP (≥ {{ number_format($seuilDP, 0, ',', '.') }})</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                {{-- Demandeur --}}
                <td class="visa-cell">
                    <div class="visa-label">Nom :</div>
                    <div class="visa-value">{{ $bon->demandeur?->nom_complet ?? '—' }}</div>
                    <div class="visa-label" style="margin-top: 4px;">Date :</div>
                    <div class="visa-value">{{ $bon->date_demande?->format('d/m/Y') ?? '—' }}</div>
                    <div class="visa-label" style="margin-top: 4px;">Visa :</div>
                    <div class="visa-value">{{ $bon->date_soumission ? '✓ Soumis' : '—' }}</div>
                </td>

                @php
                    $rolesVisa = [
                        'responsable_service' => 'CHEF DE SER',
                        'controle_gestion' => 'RCDG',
                        'daf' => 'DAF',
                        'directeur_pays' => 'DP',
                    ];
                @endphp

                @foreach($rolesVisa as $roleKey => $roleLabel)
                    @php
                        $validation = $bon->validations->where('role', $roleKey)->first();
                    @endphp
                    <td class="visa-cell">
                        <div class="visa-label">Nom :</div>
                        <div class="visa-value">{{ $validation?->validateur?->nom_complet ?? '—' }}</div>
                        <div class="visa-label" style="margin-top: 4px;">Date :</div>
                        <div class="visa-value">{{ $validation?->date_validation?->format('d/m/Y') ?? '—' }}</div>
                        <div class="visa-label" style="margin-top: 4px;">Visa :</div>
                        @if($validation && $validation->statut === 'approuve')
                            <span class="visa-status visa-approuve">✓ Approuvé</span>
                        @elseif($validation && $validation->statut === 'rejete')
                            <span class="visa-status visa-rejete">✗ Rejeté</span>
                        @elseif($validation)
                            <span class="visa-status visa-attente">En attente</span>
                        @else
                            <div class="visa-value">—</div>
                        @endif
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    {{-- CHAMP RÉSERVÉ À LA COMPTABILITÉ --}}
    <div class="comptabilite">
        <div class="comptabilite-titre">CHAMP RESERVE A LA COMPTABILITE</div>
        <table class="comptabilite-table">
            <thead>
                <tr>
                    <th style="width: 30%;">N° DE COMPTE</th>
                    <th style="width: 45%;">LIBELLE</th>
                    <th style="width: 25%;">MONTANT</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="height: 18px;">{{ $bon->code_analytique ?? '' }}</td>
                    <td>{{ $bon->motif ?? '' }}</td>
                    <td style="text-align: right;">{{ number_format((float)$bon->montant, 0, ',', ' ') }}</td>
                </tr>
                <tr><td style="height: 18px;">&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
                <tr><td style="height: 18px;">&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
                <tr><td style="height: 18px;">&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
            </tbody>
        </table>
    </div>

    {{-- NOTES DE BAS --}}
    <div class="footnotes">
        <p>(1) Autorisation de sortie de caisse sans justificatifs. Justificatifs à retourner impérativement à la caisse dès réception.</p>
        <p>(2) Autorisation de sortie de caisse avec justificatifs.</p>
    </div>

    {{-- SIGNATURES --}}
    <div class="signatures">
        <div class="sig">
            <strong>Reçu par :</strong>
            <span style="border-bottom: 1px solid #999; display: inline-block; min-width: 150px; margin-left: 5px;">
                {{ $bon->beneficiaire }}
            </span>
        </div>
        <div class="sig" style="text-align: right;">
            <strong>La Caisse :</strong>
            <span style="border-bottom: 1px solid #999; display: inline-block; min-width: 150px; margin-left: 5px;">
                @if($bon->caissier)
                    {{ $bon->caissier->nom_complet }}
                @endif
            </span>
        </div>
    </div>

    {{-- Informations paiement si payé --}}
    @if($bon->date_paiement)
    <div style="margin-top: 10px; padding: 6px 8px; background: #e8f5e9; border: 1px solid #a5d6a7; font-size: 9px;">
        <strong>Paiement effectué :</strong>
        {{ $bon->date_paiement->format('d/m/Y à H:i') }}
        — Mode : {{ \App\Models\BonCaisse::MODES_PAIEMENT[$bon->mode_paiement_effectif ?? $bon->mode_paiement] ?? ($bon->mode_paiement_effectif ?? $bon->mode_paiement) }}
        @if($bon->caissier)
            — Par : {{ $bon->caissier->nom_complet }}
        @endif
    </div>
    @endif

    <div class="footer">
        Niveau de confidentialité : Jaune — diffusion limitée dans la société et/ou le Groupe<br>
        Document généré le {{ now()->format('d/m/Y à H:i') }} — NEEMBA Gestion de Caisse — Version : 01
    </div>

</body>
</html>
