<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3 — Saisie du bon de caisse (SFD v1.3, module M03).
 *
 * - Numéro attribué à la soumission (ANO-03, RG-BC-27) : `numero` devient facultatif ; les brouillons
 *   existants perdent leur numéro (décision Q9) ; compteur annuel `compteurs_numerotation` verrouillé
 *   à chaque attribution, initialisé au plus grand numéro existant (aucun numéro n'est réutilisé).
 * - Champs de la SFD : version (RG-BC-30), initiateur (US-BC-13), bénéficiaire employé (RG-BC-06),
 *   véhicule / matériel et n° d'OR (RG-BC-13), mission (RG-BC-14), annulation (RG-BC-31),
 *   clé d'idempotence de la soumission (RG-BC-27).
 * - Paramètre delai_abandon_brouillon (30 jours) : un brouillon non modifié est annulé la nuit (RG-BC-26).
 * - Catégories de dépense de la SFD §5.4.3, paramétrables (ANO-14) ; conversion des anciennes (décision Q1).
 * - Mode de paiement « chèque ».
 * - validations.version : l'historique d'un bon resoumis conserve ses versions précédentes.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        /* code, libellé, véhicule obligatoire, véhicule affiché, OR affiché, proposée dans l'assistant */
        ['carburant', 'Carburant', true, true, true, true],
        ['transport', 'Transport et expédition', false, false, true, true],
        ['hebergement', 'Hébergement', false, false, false, true],
        ['reparation', 'Réparation et entretien', true, true, true, true],
        ['consommables', 'Pièces et consommables', false, false, true, true],
        ['frais_admin', 'Frais administratifs', false, true, false, true],
        ['fournitures', 'Fournitures et petits achats', false, false, false, true],
        ['prime', 'Primes et indemnités', false, false, false, true],
        ['mission', 'Mission', false, false, false, false],
        ['autre', 'Autre', false, false, false, true],
    ];

    /** Décision Q1 : anciennes catégories → catégories de la SFD */
    private const CONVERSION = [
        'carburant' => 'carburant',
        'transport' => 'transport',
        'frais_mission' => 'mission',
        'achat_materiel' => 'consommables',
        'fournitures_bureau' => 'fournitures',
        'prestations_externes' => 'autre',
        'entretien_reparation' => 'reparation',
        'telecommunication' => 'autre',
        'formation' => 'autre',
        'restauration' => 'autre',
        'autre' => 'autre',
    ];

    private const MODES_PAIEMENT = "'especes','orange_money','cheque','virement','autre'";
    private const MODES_PAIEMENT_AVANT = "'especes','orange_money','virement','autre'";

    /** Types de pièce : ajout de « Demande d'achat » et « Bon de travail (OR) » (SFD E-03.6) */
    private const TYPES_PIECE_AVANT = "'facture','recu','devis','ordre_mission','proforma','email','recu_carburant','bon_commande','rapport_journalier','justificatif','autre'";
    private const TYPES_PIECE = "'facture','recu','devis','ordre_mission','proforma','email','recu_carburant','bon_commande','rapport_journalier','justificatif','demande_achat','bon_travail','autre'";

    public function up(): void
    {
        /* ---------- Catégories de dépense ---------- */
        Schema::create('categories_depense', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle');
            $table->boolean('vehicule_obligatoire')->default(false);
            $table->boolean('vehicule_affiche')->default(false);
            $table->boolean('or_affiche')->default(false);
            $table->boolean('proposee_assistant')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        $maintenant = now();
        foreach (self::CATEGORIES as $ordre => [$code, $libelle, $vehObl, $vehAff, $orAff, $proposee]) {
            DB::table('categories_depense')->insert([
                'code' => $code, 'libelle' => $libelle, 'vehicule_obligatoire' => $vehObl, 'vehicule_affiche' => $vehAff,
                'or_affiche' => $orAff, 'proposee_assistant' => $proposee, 'ordre' => $ordre + 1, 'actif' => true,
                'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }

        DB::statement("ALTER TABLE bons_caisse MODIFY COLUMN categorie_depense VARCHAR(40) NULL");
        foreach (self::CONVERSION as $ancien => $nouveau) {
            DB::table('bons_caisse')->where('categorie_depense', $ancien)->update(['categorie_depense' => $nouveau]);
            DB::table('codes_analytiques')->where('categorie_depense_defaut', $ancien)->update(['categorie_depense_defaut' => $nouveau]);
        }

        /* ---------- Modes de paiement : chèque ---------- */
        DB::statement('ALTER TABLE bons_caisse MODIFY COLUMN mode_paiement ENUM(' . self::MODES_PAIEMENT . ") NOT NULL DEFAULT 'especes'");
        DB::statement('ALTER TABLE bons_caisse MODIFY COLUMN mode_paiement_effectif ENUM(' . self::MODES_PAIEMENT . ') NULL');

        /* Type facultatif : une pièce déposée est typée ensuite ; obligatoire pour quitter l'étape 4 (RG-BC-18) */
        DB::statement('ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(' . self::TYPES_PIECE . ') NULL');

        /* ---------- Champs du bon ---------- */
        DB::statement('ALTER TABLE bons_caisse MODIFY COLUMN numero VARCHAR(255) NULL');

        /* Un brouillon est créé dès le premier « Suivant » (RG-BC-01) : ces champs sont exigés à la soumission,
         * pas à l'enregistrement (RG-BC-25) */
        DB::statement("ALTER TABLE bons_caisse MODIFY COLUMN type_bon ENUM('BD','BP') NULL");
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('site')->nullable()->change();
            $table->string('service')->nullable()->change();
            $table->string('beneficiaire')->nullable()->change();
            $table->text('motif')->nullable()->change();
            $table->decimal('montant', 15, 2)->nullable()->change();
        });
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('numero');
            $table->foreignId('initiateur_id')->nullable()->after('demandeur_id')->constrained('users')->nullOnDelete();
            $table->foreignId('beneficiaire_id')->nullable()->after('beneficiaire')->constrained('users')->nullOnDelete();
            $table->string('vehicule', 20)->nullable()->after('categorie_depense');
            $table->json('references_or')->nullable()->after('vehicule');
            $table->boolean('lie_mission')->default(false)->after('references_or');
            $table->foreignId('odm_id')->nullable()->after('lie_mission')->constrained('ordres_mission')->nullOnDelete();
            $table->date('date_retour_mission')->nullable()->after('odm_id');
            $table->text('motif_annulation')->nullable()->after('commentaire_rejet');
            $table->dateTime('date_annulation')->nullable()->after('motif_annulation');
            $table->string('cle_soumission', 100)->nullable()->after('date_soumission');
        });

        Schema::table('validations', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('bon_caisse_id');
        });

        /* ---------- Numérotation à la soumission ---------- */
        Schema::create('compteurs_numerotation', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee')->primary();
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();
        });
        /* Compteur de chaque année = plus grand numéro déjà attribué (y compris brouillons et bons supprimés) */
        $maxParAnnee = [];
        foreach (DB::table('bons_caisse')->whereNotNull('numero')->pluck('numero') as $numero) {
            if (preg_match('/^BC-(\d{4})-(\d+)$/', $numero, $m)) {
                $maxParAnnee[(int) $m[1]] = max($maxParAnnee[(int) $m[1]] ?? 0, (int) $m[2]);
            }
        }
        foreach ($maxParAnnee as $annee => $max) {
            DB::table('compteurs_numerotation')->insert([
                'annee' => $annee, 'dernier_numero' => $max, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }
        /* Décision Q9 : un brouillon n'a pas de numéro ; il en recevra un nouveau à sa soumission */
        DB::table('bons_caisse')->where('statut', 'BROUILLON')->update(['numero' => null]);

        /* Bénéficiaire employé : rattachement au référentiel quand le nom correspond exactement à un utilisateur */
        foreach (DB::table('users')->select('id', 'name', 'prenom')->get() as $utilisateur) {
            $noms = array_filter([
                trim("{$utilisateur->prenom} {$utilisateur->name}"),
                trim("{$utilisateur->name} {$utilisateur->prenom}"),
            ]);
            DB::table('bons_caisse')
                ->where('type_beneficiaire', 'employe')
                ->whereNull('beneficiaire_id')
                ->whereIn('beneficiaire', $noms)
                ->update(['beneficiaire_id' => $utilisateur->id]);
        }

        /* Initiateur = demandeur pour les bons existants */
        DB::statement('UPDATE bons_caisse SET initiateur_id = demandeur_id WHERE initiateur_id IS NULL');

        /* RG-BC-26 : délai au-delà duquel un brouillon non modifié est annulé (tâche de nuit) */
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'delai_abandon_brouillon',
            'valeur' => '30',
            'libelle' => "Délai d'abandon d'un brouillon (jours)",
            'description' => 'Un brouillon non modifié depuis ce nombre de jours est annulé automatiquement la nuit',
            'type' => 'number',
            'groupe' => 'delais',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'delai_abandon_brouillon')->delete();

        /* Numéros provisoires pour les brouillons (la colonne redevient obligatoire) */
        foreach (DB::table('bons_caisse')->whereNull('numero')->pluck('id') as $id) {
            DB::table('bons_caisse')->where('id', $id)->update(['numero' => "BROUILLON-{$id}"]);
        }
        Schema::dropIfExists('compteurs_numerotation');

        Schema::table('validations', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initiateur_id');
            $table->dropConstrainedForeignId('beneficiaire_id');
            $table->dropConstrainedForeignId('odm_id');
            $table->dropColumn(['version', 'vehicule', 'references_or', 'lie_mission', 'date_retour_mission',
                'motif_annulation', 'date_annulation', 'cle_soumission']);
        });
        DB::statement("ALTER TABLE bons_caisse MODIFY COLUMN numero VARCHAR(255) NOT NULL");

        DB::table('bons_caisse')->where('mode_paiement', 'cheque')->update(['mode_paiement' => 'autre']);
        DB::table('bons_caisse')->where('mode_paiement_effectif', 'cheque')->update(['mode_paiement_effectif' => 'autre']);
        DB::statement('ALTER TABLE bons_caisse MODIFY COLUMN mode_paiement ENUM(' . self::MODES_PAIEMENT_AVANT . ") NOT NULL DEFAULT 'especes'");
        DB::statement('ALTER TABLE bons_caisse MODIFY COLUMN mode_paiement_effectif ENUM(' . self::MODES_PAIEMENT_AVANT . ') NULL');

        /* Catégories : retour aux anciens codes (la conversion n'est pas bijective : « autre » reste « autre ») */
        $retour = ['mission' => 'frais_mission', 'consommables' => 'achat_materiel', 'fournitures' => 'fournitures_bureau',
            'reparation' => 'entretien_reparation', 'hebergement' => 'autre', 'frais_admin' => 'autre', 'prime' => 'autre'];
        foreach ($retour as $nouveau => $ancien) {
            DB::table('bons_caisse')->where('categorie_depense', $nouveau)->update(['categorie_depense' => $ancien]);
            DB::table('codes_analytiques')->where('categorie_depense_defaut', $nouveau)->update(['categorie_depense_defaut' => $ancien]);
        }
        $anciennes = "'" . implode("','", array_keys(self::CONVERSION)) . "'";
        DB::statement("ALTER TABLE bons_caisse MODIFY COLUMN categorie_depense ENUM({$anciennes}) NULL");
        Schema::dropIfExists('categories_depense');

        DB::table('pieces_jointes')->whereIn('type_document', ['demande_achat', 'bon_travail'])->orWhereNull('type_document')->update(['type_document' => 'autre']);
        DB::statement('ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(' . self::TYPES_PIECE_AVANT . ') NOT NULL');
    }
};
