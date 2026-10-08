<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ordre de mission {{ $odm['libelle'] }}</title>
    {{-- M12 (RG-M12-23) : ordre de mission (autorisation de circuler) et fiche d'indemnités, avec les visas horodatés --}}
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #222; padding: 18px 26px; }
        .entete { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .entete td { border: 1px solid #999; padding: 4px 6px; font-size: 9px; }
        .entete .logo { width: 130px; text-align: center; font-weight: bold; font-size: 12px; background: #fdc911; }
        .entete .titre { text-align: center; font-weight: bold; font-size: 11px; background: #f5f5f5; }
        h1 { text-align: center; font-size: 15px; margin: 10px 0 2px; text-decoration: underline; }
        .numero { text-align: center; margin: 4px 0 12px; }
        .numero span { font-weight: bold; font-size: 14px; border: 2px solid #222; padding: 2px 12px; background: #fffde7; }
        .prolongation { text-align: center; font-style: italic; margin-bottom: 8px; }
        table.infos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.infos td { padding: 3px 4px; vertical-align: top; }
        table.infos td.libelle { width: 30%; font-weight: bold; color: #444; }
        table.grille { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.grille th, table.grille td { border: 1px solid #999; padding: 4px 5px; font-size: 9px; }
        table.grille th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; }
        .droite { text-align: right; }
        .total { font-weight: bold; background: #fffde7; }
        h2 { font-size: 11px; margin: 12px 0 5px; border-bottom: 1px solid #999; padding-bottom: 2px; }
        .mention { font-size: 8px; color: #555; margin-top: 6px; }
        .saut { page-break-before: always; }
        .vise { color: #2e7d32; font-weight: bold; }
        .attente { color: #e65100; }
    </style>
</head>
<body>
@foreach (['ordre', 'fiche'] as $page)
    @if ($page === 'fiche')<div class="saut"></div>@endif

    <table class="entete">
        <tr>
            <td class="logo" rowspan="2">NEEMBA<br>Guinée</td>
            <td class="titre">{{ $page === 'ordre' ? 'ORDRE DE MISSION — AUTORISATION DE CIRCULER' : "FICHE D'INDEMNITÉS DE MISSION" }}</td>
        </tr>
        <tr><td style="text-align:center">{{ $odm['entite'] ?? 'Neemba Guinée' }} · {{ $odm['site'] }} · Service {{ $odm['service'] }}</td></tr>
    </table>

    <h1>{{ $page === 'ordre' ? 'ORDRE DE MISSION' : "FICHE D'INDEMNITÉS" }}</h1>
    <p class="numero"><span>{{ $odm['libelle'] }}</span></p>
    @if ($odm['libelle_prolongation'])
        <p class="prolongation">{{ $odm['libelle_prolongation'] }}</p>
    @endif

    <table class="infos">
        <tr><td class="libelle">Type</td><td>{{ $odm['type_label'] }}{{ $odm['technique'] ? ' — mission technique' : '' }}</td></tr>
        <tr><td class="libelle">Destination(s)</td><td>{{ implode(', ', $odm['destinations']) }}</td></tr>
        @if ($odm['clients'])<tr><td class="libelle">Client(s)</td><td>{{ implode(', ', $odm['clients']) }}</td></tr>@endif
        <tr><td class="libelle">But de la mission</td><td>{{ $odm['but'] }}</td></tr>
        <tr><td class="libelle">Période</td><td>du {{ $odm['date_depart_format'] }} au {{ $odm['date_retour_reelle_format'] ?? $odm['date_retour_prevue_format'] }}{{ $odm['date_retour_reelle_format'] ? ' (retour réel)' : '' }} — {{ $odm['calcul']['jours'] }} jour(s)</td></tr>
        @if ($odm['vehicule'])<tr><td class="libelle">Véhicule</td><td>{{ $odm['vehicule'] }}</td></tr>@endif
        @if ($odm['ordres_reparation'])<tr><td class="libelle">OR</td><td>{{ collect($odm['ordres_reparation'])->map(fn ($or) => $or['numero'] . ' (' . ($or['type'] === 'garantie' ? 'Garantie' : 'Vente') . ')')->implode(', ') }}</td></tr>@endif
        <tr><td class="libelle">Frais à la charge de</td><td>{{ $odm['prise_en_charge_label'] }}{{ $odm['a_refacturer'] ? ' — à refacturer' : '' }}</td></tr>
        @if ($odm['type'] === 'exterieur')
            <tr><td class="libelle">Hébergement</td><td>{{ $odm['hebergement_exterieur_label'] }}</td></tr>
            @if ($odm['reference_billet'])<tr><td class="libelle">Billet / bon de commande Wanda</td><td>{{ $odm['reference_billet'] }}</td></tr>@endif
        @endif
        <tr><td class="libelle">Code analytique</td><td>{{ $odm['code_analytique'] }}{{ $odm['code_analytique_libelle'] ? ' — ' . $odm['code_analytique_libelle'] : '' }}</td></tr>
        <tr><td class="libelle">Demandeur</td><td>{{ $odm['demandeur'] }}</td></tr>
    </table>

    @if ($page === 'ordre')
        <h2>Personnel autorisé à circuler</h2>
        <table class="grille">
            <thead><tr><th>Nom et prénom</th><th>Matricule</th><th>Service</th><th>Statut</th><th>Base vie</th></tr></thead>
            <tbody>
                @foreach ($odm['participants'] as $p)
                    <tr>
                        <td>{{ $p['nom'] }}</td><td>{{ $p['matricule'] ?? '—' }}</td><td>{{ $p['service'] ?? '—' }}</td>
                        <td>{{ ['cadre' => 'Cadre', 'non_cadre' => 'Non-cadre'][$p['statut_cadre']] ?? '—' }}</td>
                        <td>{{ $p['base_vie'] ? 'Oui' : 'Non' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <h2>Calcul par participant{{ $odm['calcul']['fige'] ? ' (figé à la validation)' : '' }}</h2>
        <table class="grille">
            <thead>
                <tr>
                    <th>Participant</th><th>Jours</th><th>Nuits</th>
                    @if ($odm['type'] === 'exterieur')<th>Indemnité (FCFA)</th><th>Indemnité (GNF)</th>
                    @else<th>{{ $odm['calcul']['libelle_indemnite_1'] }}</th><th>{{ $odm['calcul']['libelle_indemnite_2'] }}</th>@endif
                    <th>Hébergement</th><th>Rattrapage</th><th>Total (GNF)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($odm['calcul']['participants'] as $c)
                    <tr>
                        <td>{{ $c['nom'] }}</td><td class="droite">{{ $c['jours'] }}</td>
                        <td class="droite">{{ $c['nuits'] }}{{ $c['nuit_rattrapage'] ? ' + 1' : '' }}</td>
                        @if ($odm['type'] === 'exterieur')
                            <td class="droite">{{ $c['indemnite_fcfa'] !== null ? \App\Support\Format::nombre($c['indemnite_fcfa']) : '—' }}</td>
                            <td class="droite">{{ $c['indemnite'] !== null ? \App\Support\Format::nombre($c['indemnite']) : 'au paiement' }}</td>
                        @else
                            <td class="droite">{{ \App\Support\Format::nombre($c['indemnite_ligne_1']) }}</td>
                            <td class="droite">{{ \App\Support\Format::nombre($c['indemnite_ligne_2']) }}</td>
                        @endif
                        <td class="droite">{{ \App\Support\Format::nombre($c['hebergement']) }}</td>
                        <td class="droite">{{ $c['rattrapage'] ? \App\Support\Format::nombre($c['rattrapage']) : '—' }}</td>
                        <td class="droite total">{{ $c['total'] !== null ? \App\Support\Format::nombre($c['total']) : '—' }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="7" class="droite total">Total de l'ordre de mission</td>
                    <td class="droite total">{{ $odm['calcul']['total'] !== null ? \App\Support\Format::nombre($odm['calcul']['total']) : '—' }}</td>
                </tr>
            </tbody>
        </table>
        @if ($odm['calcul']['total'] !== null)
            <p class="mention">Arrêté à la somme de {{ \App\Support\MontantEnLettres::convertir($odm['calcul']['total']) }}.</p>
        @endif
        @if ($odm['type'] === 'exterieur' && $odm['calcul']['taux'])
            <p class="mention">Indemnité estimée au taux 1 FCFA = {{ str_replace('.', ',', (string) $odm['calcul']['taux']['taux']) }} GNF ; recalculée au taux du jour du paiement.</p>
        @endif
        <p class="mention">Indemnités forfaitaires : aucun justificatif d'utilisation n'est exigé au retour (RG-M12-29).</p>
    @endif

    <h2>Visas</h2>
    <table class="grille">
        <thead><tr><th>Niveau</th><th>Valideur</th><th>Décision</th><th>Date et heure</th></tr></thead>
        <tbody>
            @forelse (collect($etapes)->where('version', $odm['version']) as $e)
                <tr>
                    <td>{{ $e['libelle'] }}</td>
                    <td>{{ $e['valideur'] ?? '—' }}{{ $e['au_titre_de'] ? ' (au titre de ' . $e['au_titre_de'] . ')' : '' }}</td>
                    <td class="{{ $e['statut'] === 'validee' ? 'vise' : 'attente' }}">{{ $e['statut_label'] }}</td>
                    <td>{{ $e['date_decision'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Ordre de mission non soumis.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="mention">Visas électroniques horodatés. Document édité le {{ $edite_le }}.</p>
@endforeach
</body>
</html>
