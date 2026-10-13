<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ordre de mission {{ $odm['libelle'] }}</title>
    {{--
        M12 (RG-M12-23) : ordre de mission sur le modèle papier de Neemba (« ODM .xlsm ») — service concerné, mission,
        prise en charge, passagers, calcul des indemnités, signatures — aux couleurs de l'application (or neemba, marine).
    --}}
    @php
        $fmt = fn ($v) => \App\Support\Format::nombre($v);
        $calcul = $odm['calcul'];
        $exterieur = $odm['type'] === 'exterieur';
        $recap = $calcul['recapitulatif'] ?? ['neemba' => 0, 'client' => 0, 'par_client' => []];
        $participants = collect($calcul['participants']);
        $sommeOm = $participants->every(fn ($c) => $c['frais_om'] !== null) ? $participants->sum('frais_om') : null;
        $sommeVerse = $participants->every(fn ($c) => $c['montant_verse_om'] !== null) ? $participants->sum('montant_verse_om') : null;
        $demiTaux = intdiv((int) $calcul['indemnite_journaliere'], 2);
    @endphp
    <style>
        @page { margin: 26px 32px 40px 32px; }
        body, div, p, h2, table { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 7.9pt; color: #0f172a; line-height: 1.25; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        /* Éléments répétés sur chaque page */
        .bande-or { position: fixed; top: -26px; left: -32px; right: -32px; height: 7px; background: #fdc911; }
        .bande-marine { position: fixed; top: -19px; left: -32px; right: -32px; height: 2px; background: #0f172a; }
        .filigrane { position: fixed; top: 300px; left: -40px; right: -40px; text-align: center; font-size: 76pt; font-weight: bold;
            color: #0f172a; opacity: 0.06; transform: rotate(-32deg); letter-spacing: 6px; }
        .pied { position: fixed; bottom: -28px; left: 0; right: 0; border-top: 1px solid #e5e7eb; padding-top: 5px; font-size: 6.6pt; color: #6b7280; }
        .pied td { vertical-align: middle; }

        /* En-tête */
        .entete td { vertical-align: middle; }
        .entete img { height: 34px; }
        .adresse { text-align: right; font-size: 6.6pt; color: #6b7280; line-height: 1.3; }
        .adresse strong { color: #0f172a; font-size: 7.6pt; }
        .titre { text-align: center; margin-top: 4px; }
        .titre .surtitre { font-size: 13.5pt; font-weight: bold; letter-spacing: 3px; color: #0f172a; }
        .titre .numero { display: inline-block; margin-top: 1px; padding: 1px 6px 2px; font-size: 10pt; font-weight: bold; color: #1e3a8a; border-bottom: 2.5px solid #fdc911; }
        .titre .sous-titre { margin-top: 3px; font-size: 7.6pt; color: #6b7280; font-style: italic; }
        .pastille { display: inline-block; padding: 1px 6px; border-radius: 7px; font-size: 6.6pt; font-weight: bold; }
        .pastille-or { background: #fff3c6; color: #864d08; border: 0.6px solid #ffd34a; }
        .pastille-vise { background: #d1fae5; color: #047857; }
        .pastille-attente { background: #f1f5f9; color: #64748b; }
        .pastille-saute { background: #fef3c7; color: #a36200; }
        .pastille-rejete { background: #fee2e2; color: #b91c1c; }
        .autorisons { margin-top: 7px; padding: 4px 9px; background: #fffbeb; border-left: 3px solid #fdc911; font-size: 8.6pt; }
        .autorisons strong { color: #1e3a8a; }

        /* Rubriques */
        .rubrique { margin-top: 7px; }
        .rubrique h2 { font-size: 7.4pt; font-weight: bold; letter-spacing: 1.3px; text-transform: uppercase; color: #1e3a8a;
            border-bottom: 1.5px solid #fdc911; padding-bottom: 2px; margin-bottom: 3px; }
        .rubrique h2 .note { float: right; letter-spacing: 0; text-transform: none; font-weight: normal; color: #6b7280; font-size: 6.8pt; }

        /* Cases à cocher */
        .case { display: inline-block; width: 9px; height: 9px; border: 0.8px solid #94a3b8; border-radius: 2px; background: #fff;
            text-align: center; font-size: 6.8pt; line-height: 9px; font-weight: bold; color: #0f172a; vertical-align: middle; }
        .case-on { background: #fdc911; border-color: #cc8a00; }
        .choix { white-space: nowrap; margin-right: 10px; font-size: 7.6pt; vertical-align: middle; }
        .choix-on { font-weight: bold; color: #0f172a; }
        .choix-off { color: #94a3b8; }

        /* Grille d'informations */
        .infos td { padding: 2.5px 6px; border-bottom: 0.6px solid #e5e7eb; }
        .infos .lib { width: 15%; white-space: nowrap; background: #f8fafc; color: #64748b; font-size: 6.9pt; text-transform: uppercase; letter-spacing: 0.4px; }
        .infos .val { width: 35%; font-weight: bold; }
        .infos .normal { font-weight: normal; }
        .discret { color: #6b7280; font-weight: normal; font-size: 7pt; }

        /* Tableaux */
        .tableau th { background: #0f172a; color: #fff; font-size: 6.7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;
            padding: 3px 4px; text-align: left; border-right: 0.6px solid #1e3a8a; }
        .tableau th.taux { text-transform: none; letter-spacing: 0; }
        .tableau th small { display: block; color: #fdc911; font-weight: normal; font-size: 6.3pt; text-transform: none; }
        .tableau td { padding: 2.5px 4px; border-bottom: 0.6px solid #e5e7eb; }
        .tableau tbody tr:nth-child(even) td { background: #fffbeb; }
        .tableau .total td { background: #fff3c6; font-weight: bold; border-top: 1.2px solid #fdc911; border-bottom: 1.2px solid #fdc911; }
        .droite { text-align: right; }
        td.droite { white-space: nowrap; }
        .centre { text-align: center; }
        .client { color: #5b21b6; }
        .code-client { display: inline-block; padding: 0 4px; border-radius: 3px; background: #ede9fe; color: #5b21b6; font-size: 6.6pt; font-weight: bold; margin-right: 2px; }

        /* Synthèse */
        .synthese { margin-top: 5px; }
        .synthese .lettres { padding: 5px 8px; border: 0.8px solid #e5e7eb; border-radius: 4px; font-size: 7.6pt; }
        .synthese .lettres em { color: #1e3a8a; font-style: italic; font-weight: bold; }
        .synthese .mention { margin-top: 3px; font-size: 6.7pt; color: #6b7280; }
        .montant-odm { background: #0f172a; color: #fff; border-radius: 4px; padding: 5px 10px; text-align: right; }
        .montant-odm .lib { font-size: 6.6pt; letter-spacing: 1px; text-transform: uppercase; color: #cbd5e1; }
        .montant-odm .valeur { font-size: 13pt; font-weight: bold; color: #fdc911; white-space: nowrap; }
        .montant-odm .detail { font-size: 6.6pt; color: #e2e8f0; margin-top: 2px; }
        .montant-odm table { margin-top: 3px; border-top: 0.6px solid #334155; }
        .montant-odm table td { font-size: 6.6pt; color: #e2e8f0; padding-top: 2px; }
        .montant-odm table td.droite { color: #fff; font-weight: bold; }

        /* Signatures */
        .signatures { page-break-inside: avoid; }
        .signatures td { border-left: 0.8px solid #e5e7eb; border-right: 0.8px solid #e5e7eb; }
        .signatures td.intitule { background: #fffbeb; border-top: 0.8px solid #e5e7eb; border-bottom: 1.2px solid #fdc911; padding: 4px 6px;
            font-size: 6.6pt; font-weight: bold; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.2px; vertical-align: middle; }
        .signatures td.corps { padding: 4px 6px 0; font-size: 7.2pt; }
        .signatures td.bas { padding: 0; border-bottom: 0.8px solid #e5e7eb; }
        .signatures .corps p { margin-bottom: 1.5px; }
        .signatures .corps .etiquette { color: #64748b; }
        .signatures .cadre { margin: 3px 6px 5px; height: 30px; border: 0.8px dashed #cbd5e1; border-radius: 3px; font-size: 6pt; color: #94a3b8; padding: 2px 4px; }
    </style>
</head>
<body>
    <div class="bande-or"></div>
    <div class="bande-marine"></div>
    @if ($odm['filigrane'])
        <div class="filigrane">{{ $odm['filigrane'] }}</div>
    @endif
    <div class="pied">
        <table>
            <tr>
                <td>{{ $odm['entite'] ?: 'Neemba Guinée' }} · Ordre de mission {{ $odm['numero'] ?? '(brouillon)' }} · Visas électroniques horodatés · Document édité le {{ $edite_le }}</td>
            </tr>
        </table>
    </div>

    {{-- En-tête : logo et adresse --}}
    <table class="entete">
        <tr>
            <td style="width: 50%">
                @if (file_exists(public_path('logo.png')))
                    <img src="{{ public_path('logo.png') }}" alt="Neemba CAT">
                @else
                    <strong style="font-size: 14pt">NEEMBA</strong>
                @endif
            </td>
            <td class="adresse">
                @foreach ($odm['adresse'] as $i => $ligne)
                    @if ($i === 0)<strong>{{ $ligne }}</strong>@else{{ $ligne }}@endif<br>
                @endforeach
            </td>
        </tr>
    </table>

    <div class="titre">
        <div class="surtitre">ORDRE DE MISSION</div>
        <div class="numero">INDEMNITÉS {{ $odm['numero'] ?? '— brouillon' }}{{ $odm['date_titre'] ? ' du ' . $odm['date_titre'] : '' }}</div>
        @if ($odm['prolongation'])
            <div class="sous-titre">{{ $odm['libelle_prolongation'] }}{{ $odm['prolongation']['precedent'] ? ' — prolonge l\'ODM N°' . $odm['prolongation']['precedent'] : '' }}</div>
        @endif
        @if ($exterieur)
            <div style="margin-top: 4px"><span class="pastille pastille-or">Mission à l'étranger</span></div>
        @endif
    </div>

    <p class="autorisons">Autorisons la(es) personne(s) suivante(s) à se rendre en mission à : <strong>{{ implode(', ', $odm['destinations']) ?: '—' }}</strong></p>

    {{-- Service concerné --}}
    <div class="rubrique">
        <h2>Service concerné <span class="note">Service émetteur : {{ $odm['service'] }}</span></h2>
        <table>
            <tr>
                <td style="width: 38%; vertical-align: middle">
                    <span class="discret">N° OR :</span>
                    <strong>{{ collect($odm['ordres_reparation'])->map(fn ($or) => $or['numero'] . ' (' . ($or['type'] === 'garantie' ? 'Garantie' : 'Vente') . ')')->implode(', ') ?: '—' }}</strong>
                </td>
                <td class="droite" style="vertical-align: middle">
                    @foreach (\App\Models\OrdreMission::RUBRIQUES_PDF as $rubrique)
                        <span class="choix {{ $odm['rubrique'] === $rubrique ? 'choix-on' : 'choix-off' }}"><span class="case {{ $odm['rubrique'] === $rubrique ? 'case-on' : '' }}">{{ $odm['rubrique'] === $rubrique ? '✓' : '' }}</span> {{ $rubrique }}</span>
                    @endforeach
                </td>
            </tr>
        </table>
    </div>

    {{-- Mission --}}
    <div class="rubrique">
        <h2>Mission</h2>
        <table class="infos">
            <tr>
                <td class="lib">Client(s)</td>
                <td class="val">{{ collect($odm['clients'])->map(fn ($c) => $c['raison_sociale'] . ' (' . $c['code'] . ')')->implode(', ') ?: '—' }}</td>
                <td class="lib">Demandeur</td>
                <td class="val">{{ $odm['demandeur'] }}@if ($odm['initiateur']) <span class="discret">(saisi par {{ $odm['initiateur'] }})</span>@endif</td>
            </tr>
            <tr>
                <td class="lib">Destination(s)</td>
                <td class="val">{{ implode(', ', $odm['destinations']) ?: '—' }}</td>
                <td class="lib">Type</td>
                <td class="val">{{ $odm['type_label'] }}{{ $odm['technique'] ? ' · mission technique' : '' }}</td>
            </tr>
            <tr>
                <td class="lib">But de la mission</td>
                <td class="val normal" colspan="3">{{ $odm['but'] ?: '—' }}</td>
            </tr>
            <tr>
                <td class="lib">Date de départ</td>
                <td class="val">{{ $odm['date_depart_format'] }}</td>
                <td class="lib">Date de retour</td>
                <td class="val">
                    {{ $odm['date_retour_reelle_format'] ?? $odm['date_retour_prevue_format'] }}
                    <span class="discret">{{ $odm['date_retour_reelle_format'] ? '(réelle ; prévue le ' . $odm['date_retour_prevue_format'] . ')' : '(prévue)' }} · {{ $calcul['jours'] }} jour(s)</span>
                </td>
            </tr>
            <tr>
                <td class="lib">Véhicule</td>
                <td class="val">{{ $odm['vehicule'] ?: '—' }}</td>
                <td class="lib">Code analytique</td>
                <td class="val">{{ $odm['code_analytique'] }}@if ($odm['code_analytique_libelle']) <span class="discret">— {{ $odm['code_analytique_libelle'] }}</span>@endif</td>
            </tr>
        </table>
    </div>

    {{-- Prise en charge (spec v2.3 : parts des lignes de frais) --}}
    <div class="rubrique">
        <h2>Prise en charge</h2>
        <table class="tableau">
            <thead>
                <tr>
                    <th style="width: 25%">Frais</th>
                    <th style="width: 23%">À charge de Neemba Guinée</th>
                    <th style="width: 27%">À charge du client</th>
                    <th>Autres à préciser</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($odm['prise_en_charge'] as $r)
                    <tr>
                        <td><strong>{{ $r['libelle'] }}</strong></td>
                        <td>
                            <span class="case {{ $r['neemba'] ? 'case-on' : '' }}">{{ $r['neemba'] ? '✓' : '' }}</span>
                            @if ($r['neemba'])&nbsp;{{ $r['montant_neemba'] > 0 ? $fmt($r['montant_neemba']) . ' GNF' : ($r['a_la_cloture'] ? 'à la clôture' : '') }}@endif
                        </td>
                        <td class="client">
                            <span class="case {{ $r['client'] ? 'case-on' : '' }}">{{ $r['client'] ? '✓' : '' }}</span>
                            @if ($r['client'])
                                &nbsp;{{ $r['montant_client'] > 0 ? $fmt($r['montant_client']) . ' GNF' : ($r['a_la_cloture'] ? 'à la clôture' : '') }}
                                @foreach ($r['clients'] as $code)<span class="code-client">{{ $code }}</span>@endforeach
                            @endif
                        </td>
                        <td>{{ implode(' · ', $r['autres']) ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Passagers --}}
    <div class="rubrique">
        <h2>Passagers <span class="note">{{ count($odm['passagers']) }} personne(s)</span></h2>
        <table class="tableau">
            <thead>
                <tr>
                    <th style="width: 5%" class="centre">N°</th>
                    <th style="width: 22%">Noms</th>
                    <th style="width: 23%">Prénoms</th>
                    <th style="width: 13%">Matricule</th>
                    <th style="width: 19%">Service</th>
                    <th>N° Orange Money</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($odm['passagers'] as $i => $p)
                    <tr>
                        <td class="centre">{{ $i + 1 }}</td>
                        <td><strong>{{ $p['nom'] }}</strong></td>
                        <td>{{ $p['prenoms'] ?: '—' }}</td>
                        <td>{{ $p['matricule'] ?? '—' }}</td>
                        <td>{{ $p['service'] ?? '—' }}</td>
                        <td>{{ $p['numero_om'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Calcul des indemnités de mission --}}
    <div class="rubrique">
        <h2>Calcul des indemnités de mission <span class="note">{{ $calcul['fige'] ? 'Barèmes figés à la validation' : 'Barèmes en vigueur' }} · montants en GNF</span></h2>
        <table class="tableau">
            <thead>
                <tr>
                    <th class="centre" style="width: 3%">N°</th>
                    <th style="width: 19%">Participant</th>
                    <th class="droite" style="width: 6%">Jours</th>
                    @if ($exterieur)
                        <th class="droite taux" style="width: 11%">Indemnité FCFA</th>
                        <th class="droite taux" style="width: 11%">Indemnité GNF</th>
                    @else
                        <th class="droite taux" style="width: 11%">{{ $calcul['libelle_indemnite_1'] }}<small>{{ $fmt($demiTaux) }} / jour</small></th>
                        <th class="droite taux" style="width: 11%">{{ $calcul['libelle_indemnite_2'] }}<small>{{ $fmt($demiTaux) }} / jour</small></th>
                    @endif
                    <th class="droite" style="width: 6%">Nuits</th>
                    <th class="droite taux" style="width: 11%">
                        Hébergement
                        @unless ($exterieur)<small>{{ $fmt($calcul['hebergement_nuit']) }} / nuit</small>@endunless
                    </th>
                    <th class="droite" style="width: 11%">Montant</th>
                    <th class="droite taux" style="width: 10%">Frais de retrait<small>Orange Money</small></th>
                    <th class="droite" style="width: 12%">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($participants as $i => $c)
                    <tr>
                        <td class="centre">{{ $i + 1 }}</td>
                        <td>{{ $c['nom'] }}</td>
                        <td class="droite">{{ $c['jours'] }}</td>
                        @if ($exterieur)
                            <td class="droite">{{ $c['indemnite_fcfa'] !== null ? $fmt($c['indemnite_fcfa']) : '—' }}</td>
                            <td class="droite">{{ $c['indemnite'] !== null ? $fmt($c['indemnite']) : 'au paiement' }}</td>
                        @else
                            <td class="droite">{{ $fmt($c['indemnite_ligne_1']) }}</td>
                            <td class="droite">{{ $fmt($c['indemnite_ligne_2']) }}</td>
                        @endif
                        <td class="droite">{{ $c['nuits'] }}{{ $c['nuit_rattrapage'] ? ' + 1' : '' }}</td>
                        <td class="droite">{{ $c['hebergement'] + $c['rattrapage'] > 0 ? $fmt($c['hebergement'] + $c['rattrapage']) : ($c['base_vie'] ? 'base vie' : '—') }}</td>
                        <td class="droite"><strong>{{ $c['total'] !== null ? $fmt($c['total']) : '—' }}</strong></td>
                        <td class="droite">{{ $c['frais_om'] !== null ? $fmt($c['frais_om']) : '—' }}</td>
                        <td class="droite"><strong>{{ $c['montant_verse_om'] !== null ? $fmt($c['montant_verse_om']) : '—' }}</strong></td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="7" class="droite">TOTAL</td>
                    <td class="droite">{{ $calcul['total'] !== null ? $fmt($calcul['total']) : '—' }}</td>
                    <td class="droite">{{ $sommeOm !== null ? $fmt($sommeOm) : '—' }}</td>
                    <td class="droite">{{ $sommeVerse !== null ? $fmt($sommeVerse) : '—' }}</td>
                </tr>
            </tbody>
        </table>

        <table class="synthese">
            <tr>
                <td style="padding-right: 8px">
                    <div class="lettres">
                        @if ($calcul['total'] !== null)
                            Arrêté à la somme de <em>{{ \App\Support\MontantEnLettres::convertir($calcul['total']) }}</em>.
                        @else
                            Montant arrêté au paiement (taux du jour).
                        @endif
                    </div>
                    @if ($exterieur && $calcul['taux'])
                        <p class="mention">Indemnité estimée au taux 1 FCFA = {{ str_replace('.', ',', (string) $calcul['taux']['taux']) }} GNF ; recalculée au taux du jour du paiement.</p>
                    @endif
                    <p class="mention">Frais de retrait Orange Money indicatifs, ajoutés au montant versé. Indemnités forfaitaires : aucun justificatif d'utilisation n'est exigé au retour (RG-M12-29).</p>
                </td>
                <td style="width: 36%">
                    <div class="montant-odm">
                        <div class="lib">Montant de l'ordre de mission</div>
                        <div class="valeur">{{ $calcul['total'] !== null ? $fmt($calcul['total']) . ' GNF' : '—' }}</div>
                        @if (($recap['client'] ?? 0) > 0)
                            {{-- RG-M12-34 : récapitulatif Neemba / clients --}}
                            <table>
                                <tr><td>dont Neemba Guinée</td><td class="droite">{{ $fmt($recap['neemba']) }}</td></tr>
                                @foreach ($recap['par_client'] as $pc)
                                    <tr>
                                        <td>dont {{ $pc['client']['code'] }}{{ collect($pc['par_or'])->pluck('or')->filter()->isNotEmpty() ? ' · OR ' . collect($pc['par_or'])->pluck('or')->filter()->implode(', ') : '' }}</td>
                                        <td class="droite">{{ $fmt($pc['montant']) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                            <div class="detail">Part client avancée par Neemba et refacturée</div>
                        @else
                            <div class="detail">Entièrement à la charge de Neemba Guinée</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Signatures : demandeur, puis visas électroniques du circuit, avec un espace pour la signature manuscrite --}}
    <div class="rubrique signatures">
        <h2>Visas et signatures</h2>
        {{-- Trois rangées (intitulé, visa, cadre) : les colonnes restent alignées quand un intitulé tient sur deux lignes --}}
        <table>
            <tr>
                @foreach ($odm['signataires'] as $s)
                    <td class="intitule" style="width: {{ round(100 / count($odm['signataires']), 2) }}%">{{ $s['titre'] }}</td>
                @endforeach
            </tr>
            <tr>
                @foreach ($odm['signataires'] as $s)
                    <td class="corps">
                        <p><span class="etiquette">Nom :</span> <strong>{{ $s['nom'] ?? '—' }}</strong></p>
                        @if ($s['au_titre_de'])<p class="discret">{{ $s['au_titre_de'] }}</p>@endif
                        <p><span class="etiquette">Date :</span> {{ $s['date'] ?? '—' }}</p>
                        <p><span class="etiquette">Visa :</span> <span class="pastille pastille-{{ $s['etat'] }}">{{ $s['etat_label'] }}</span></p>
                    </td>
                @endforeach
            </tr>
            <tr>
                @foreach ($odm['signataires'] as $s)
                    <td class="bas"><div class="cadre">Signature / cachet</div></td>
                @endforeach
            </tr>
        </table>
    </div>
</body>
</html>
