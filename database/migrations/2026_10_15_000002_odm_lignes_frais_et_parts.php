<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec v2.3 (décision du 09/10) : la prise en charge des frais d'un ODM est portée par des lignes et des parts.
 *
 * - RG-M12-30 : une ligne de frais par participant, par segment et par élément (indemnité, hébergement, nuitée de
 *   rattrapage, hébergement à l'étranger payé en GNF), avec quantité, taux et montant.
 * - RG-M12-31 : chaque ligne se répartit en parts (payeur Neemba ou Client, client, OR, code analytique, pourcentage).
 *   Le pourcentage fait foi (décision Q52) ; le montant de la part en découle.
 * - RG-M12-05 : la prise en charge quitte l'en-tête de l'ODM.
 *
 * Remplace le modèle du devBook §36 (trois états par ligne, « payé directement par le client »), repris ici :
 * Neemba → part Neemba à 100 % ; client (avancé ou direct) → part client à 100 %, au premier client de l'ODM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odm_lignes_frais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('odm_participants')->cascadeOnDelete();
            $table->string('element', 30);
            $table->decimal('quantite', 8, 2)->default(0);
            $table->decimal('taux', 15, 2)->nullable();
            $table->string('devise', 4)->default('GNF');
            $table->decimal('montant', 15, 2)->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'element']);
        });

        Schema::create('odm_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ligne_frais_id')->constrained('odm_lignes_frais')->cascadeOnDelete();
            $table->unsignedTinyInteger('rang')->default(1);
            $table->enum('payeur', ['neemba', 'client'])->default('neemba');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('ordre_reparation_id')->nullable()->constrained('odm_ordres_reparation')->nullOnDelete();
            /* Null : le code analytique de l'ODM (défaut de RG-M12-31) */
            $table->string('code_analytique', 50)->nullable();
            $table->decimal('pourcentage', 7, 4)->default(100);
            $table->decimal('montant', 15, 2)->nullable();
            $table->string('commentaire', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->decimal('montant_neemba', 15, 2)->nullable()->after('a_refacturer');
        });

        $this->reprendre();

        Schema::table('odm_participants', function (Blueprint $table) {
            $table->dropColumn(['prises_en_charge', 'montant_bon', 'montant_refacturable', 'montant_client_direct']);
        });
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->dropColumn(['prise_en_charge', 'mode_client']);
        });

        $maintenant = now();
        DB::table('parametres')->where('cle', 'odm_prise_en_charge_client')->update([
            'libelle' => 'ODM à la charge de clients',
            'description' => 'RG-M12-15 (v2.3) : variante A, bons générés pour le montant total et ODM « à refacturer » ; '
                . 'variante B, aucun bon de caisse, seulement si toutes les parts de toutes les lignes sont à la charge de clients.',
        ]);
        foreach ([
            ['odm_frais_om_repartition', 'prorata', 'Frais Orange Money sur un ODM partagé',
                'RG-M12-36 (PO-24) : frais OM d\'un bon issu d\'un ODM répartis entre les parts au prorata, ou à 100 % sur Neemba', 'choix', 'odm'],
            ['compte_frais_refacturables', '', 'Compte des frais refacturables (part client)',
                'RG-M16-05 (PO-25) : compte comptable des parts à la charge d\'un client ; à renseigner par la Comptabilité', 'compte', 'odm'],
        ] as [$cle, $valeur, $libelle, $description, $type, $groupe]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type', 'groupe') + ['created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
    }

    /** Lignes et parts des ODM existants, à partir du calcul enregistré sur les participants et des choix du §36 */
    private function reprendre(): void
    {
        $maintenant = now();
        $odms = DB::table('ordres_mission')->get(['id', 'type', 'hebergement_exterieur', 'prise_en_charge', 'mode_client', 'statut']);
        foreach ($odms as $odm) {
            $premierClient = DB::table('odm_clients')->where('ordre_mission_id', $odm->id)->orderBy('id')->value('client_id');
            $defaut = $odm->prise_en_charge === 'client' ? 'client' : 'neemba';
            $neemba = 0.0;
            $client = 0.0;

            foreach (DB::table('odm_participants')->where('ordre_mission_id', $odm->id)->get() as $p) {
                $prises = json_decode((string) $p->prises_en_charge, true) ?: [];
                $payeur = fn (array $cles) => collect($cles)->map(fn ($c) => $prises[$c] ?? null)->filter()->first() ?? $defaut;
                $exterieur = $odm->type === 'exterieur';

                $lignes = [];
                if ((int) $p->jours > 0) {
                    $lignes[] = $exterieur
                        ? ['indemnite', $p->jours, $p->jours > 0 && $p->indemnite_fcfa !== null ? round($p->indemnite_fcfa / $p->jours, 2) : null, 'FCFA', $p->indemnite, $payeur(['indemnite'])]
                        : ['indemnite', $p->jours, round($p->indemnite / $p->jours, 2), 'GNF', $p->indemnite, $payeur(['indemnite_1', 'indemnite_2'])];
                }
                if (!$exterieur && (float) $p->hebergement > 0) {
                    $lignes[] = ['hebergement', $p->nuits, $p->nuits > 0 ? round($p->hebergement / $p->nuits, 2) : null, 'GNF', $p->hebergement, $payeur(['hebergement'])];
                }
                if (!$exterieur && (float) $p->rattrapage > 0) {
                    $lignes[] = ['rattrapage', $p->nuit_rattrapage, $p->rattrapage, 'GNF', $p->rattrapage, $payeur(['rattrapage'])];
                }
                if ($exterieur && $odm->hebergement_exterieur === 'avant_depart' && (float) $p->hebergement > 0) {
                    $lignes[] = ['hebergement_exterieur', 1, $p->hebergement, 'GNF', $p->hebergement, $payeur(['hebergement'])];
                }
                if ($exterieur && $odm->hebergement_exterieur === 'au_retour') {
                    $lignes[] = ['hebergement_exterieur', 1, null, 'GNF', null, $payeur(['hebergement_retour'])];
                }

                foreach ($lignes as [$element, $quantite, $taux, $devise, $montant, $prise]) {
                    $ligneId = DB::table('odm_lignes_frais')->insertGetId([
                        'ordre_mission_id' => $odm->id, 'participant_id' => $p->id, 'element' => $element,
                        'quantite' => $quantite, 'taux' => $taux, 'devise' => $devise, 'montant' => $montant,
                        'created_at' => $maintenant, 'updated_at' => $maintenant,
                    ]);
                    $estClient = $prise !== 'neemba';
                    DB::table('odm_parts')->insert([
                        'ligne_frais_id' => $ligneId, 'rang' => 1, 'payeur' => $estClient ? 'client' : 'neemba',
                        'client_id' => $estClient ? $premierClient : null, 'pourcentage' => 100, 'montant' => $montant,
                        'created_at' => $maintenant, 'updated_at' => $maintenant,
                    ]);
                    if (!$p->retire) {
                        $estClient ? $client += (float) $montant : $neemba += (float) $montant;
                    }
                }
            }

            /* RG-M12-37 : un ODM déjà validé avec une part client est « à refacturer » */
            $valide = in_array($odm->statut, ['VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE'], true);
            DB::table('ordres_mission')->where('id', $odm->id)->update(['montant_neemba' => $neemba, 'montant_a_refacturer' => $client]
                + ($valide ? ['a_refacturer' => $client > 0] : []));
        }
    }

    public function down(): void
    {
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->enum('prise_en_charge', ['neemba', 'client', 'mixte'])->default('neemba')->after('motif_depart_passe');
            $table->enum('mode_client', ['avance', 'direct'])->default('avance')->after('prise_en_charge');
        });
        Schema::table('odm_participants', function (Blueprint $table) {
            $table->json('prises_en_charge')->nullable()->after('total');
            $table->decimal('montant_bon', 15, 2)->nullable()->after('prises_en_charge');
            $table->decimal('montant_refacturable', 15, 2)->nullable()->after('montant_bon');
            $table->decimal('montant_client_direct', 15, 2)->nullable()->after('montant_refacturable');
        });

        /* Retour au modèle §36 : une ligne est « client » (avancée) si l'une de ses parts l'est */
        $correspondance = ['indemnite' => ['indemnite_1', 'indemnite_2', 'indemnite'], 'hebergement' => ['hebergement'],
            'rattrapage' => ['rattrapage'], 'hebergement_exterieur' => ['hebergement', 'hebergement_retour']];
        foreach (DB::table('odm_participants')->get(['id', 'ordre_mission_id', 'total']) as $p) {
            $prises = array_fill_keys(['indemnite_1', 'indemnite_2', 'hebergement', 'rattrapage', 'indemnite', 'hebergement_retour'], 'neemba');
            $refacturable = 0.0;
            foreach (DB::table('odm_lignes_frais')->where('participant_id', $p->id)->get(['id', 'element']) as $ligne) {
                $partsClient = DB::table('odm_parts')->where('ligne_frais_id', $ligne->id)->where('payeur', 'client');
                if ($partsClient->exists()) {
                    foreach ($correspondance[$ligne->element] ?? [] as $cle) {
                        $prises[$cle] = 'client_avance';
                    }
                    $refacturable += (float) $partsClient->sum('montant');
                }
            }
            DB::table('odm_participants')->where('id', $p->id)->update([
                'prises_en_charge' => json_encode($prises), 'montant_bon' => $p->total, 'montant_refacturable' => $refacturable, 'montant_client_direct' => 0,
            ]);
        }
        DB::table('ordres_mission')->where('montant_a_refacturer', '>', 0)->update(['prise_en_charge' => 'mixte']);

        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->dropColumn('montant_neemba');
        });
        Schema::dropIfExists('odm_parts');
        Schema::dropIfExists('odm_lignes_frais');
        DB::table('parametres')->whereIn('cle', ['odm_frais_om_repartition', 'compte_frais_refacturables'])->delete();
        DB::table('parametres')->where('cle', 'odm_prise_en_charge_client')->update([
            'libelle' => 'Ligne à la charge du client : mode proposé par défaut',
        ]);
    }
};
