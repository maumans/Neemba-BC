<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot 2 — Caisses (SFD v1.3 : caisse payeuse RG-BC-12, plafond de retrait RG-BC-11, caisse Atelier D10,
 * soldes par caisse ANO-09) et registre des écritures de caisse.
 *
 * Avant : deux soldes portés par chaque site (sites.solde_especes / solde_om), plus un plafond et un seuil.
 * Après : une table `caisses` (une caisse espèces par site, la caisse Orange Money unique de Conakry,
 * la caisse Atelier en avance fixe) et un registre `ecritures_caisse` : chaque mouvement d'argent y est
 * inscrit avec le solde avant / après. Le solde d'une caisse à une date se lit dans le registre.
 *
 * Reprise des données :
 * - caisse espèces de chaque site = ancien solde espèces du site, ancien seuil d'alerte ;
 *   plafond de retrait : Conakry 20 000 000, Boké 1 000 000 (décision Q2), sinon aucun ;
 * - caisse Orange Money de Conakry = somme des anciens soldes OM (une seule caisse OM, RG-BC-12) ;
 * - caisse Atelier : avance fixe de 15 000 000, inactive et à 0 tant que son solde réel n'est pas saisi (M07) ;
 * - une écriture « solde initial » par caisse non nulle ;
 * - caisse des bons payés et des mouvements de caisse existants ;
 * - modifications de site en attente de double validation : annulées (les champs n'existent plus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('libelle');
            $table->foreignId('site_id')->constrained('sites');
            $table->enum('type', ['especes', 'orange_money'])->default('especes');
            $table->enum('mode', ['standard', 'avance_fixe'])->default('standard');
            $table->decimal('montant_avance', 15, 2)->nullable();
            $table->decimal('solde', 15, 2)->default(0);
            $table->decimal('plafond_retrait', 15, 2)->nullable();
            $table->decimal('seuil_alerte', 15, 2)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('ecritures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->dateTime('date_ecriture');
            $table->enum('sens', ['entree', 'sortie']);
            $table->string('nature', 40);
            $table->decimal('montant', 15, 2);
            $table->decimal('solde_avant', 15, 2);
            $table->decimal('solde_apres', 15, 2);
            $table->string('libelle')->nullable();
            $table->foreignId('bon_caisse_id')->nullable()->constrained('bons_caisse')->nullOnDelete();
            $table->foreignId('mouvement_caisse_id')->nullable()->constrained('mouvements_caisse')->nullOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['caisse_id', 'date_ecriture']);
        });

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->foreignId('caisse_id')->nullable()->after('mode_paiement_effectif')->constrained('caisses')->nullOnDelete();
        });

        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->foreignId('caisse_id')->nullable()->after('site')->constrained('caisses')->nullOnDelete();
        });

        $this->reprendreLesDonnees();

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['solde_especes', 'solde_om', 'plafond_caisse', 'seuil_minimum_caisse']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('solde_especes', 15, 2)->default(0);
            $table->decimal('solde_om', 15, 2)->default(0);
            $table->decimal('plafond_caisse', 15, 2)->nullable();
            $table->decimal('seuil_minimum_caisse', 15, 2)->nullable();
        });

        /* Soldes remis sur les sites : espèces = caisses espèces du site, OM = caisses OM du site */
        foreach (DB::table('caisses')->get() as $caisse) {
            $colonne = $caisse->type === 'orange_money' ? 'solde_om' : 'solde_especes';
            DB::table('sites')->where('id', $caisse->site_id)->increment($colonne, (float) $caisse->solde);
            if ($caisse->type === 'especes' && $caisse->mode === 'standard') {
                DB::table('sites')->where('id', $caisse->site_id)->update(['seuil_minimum_caisse' => $caisse->seuil_alerte]);
            }
        }

        Schema::table('mouvements_caisse', fn (Blueprint $table) => $table->dropConstrainedForeignId('caisse_id'));
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropConstrainedForeignId('caisse_id'));
        Schema::dropIfExists('ecritures_caisse');
        Schema::dropIfExists('caisses');
    }

    private function reprendreLesDonnees(): void
    {
        $maintenant = now();
        $sites = DB::table('sites')->orderBy('id')->get();
        $conakry = $sites->first(fn ($site) => $site->code === '01' || Str::lower(Str::ascii($site->nom)) === 'conakry');
        $codesPris = [];
        $caisseEspecesDuSite = [];

        foreach ($sites as $site) {
            $prefixe = $this->prefixe($site, $conakry, $codesPris);
            $nomAscii = Str::lower(Str::ascii($site->nom));
            $plafond = match (true) {
                $conakry && $site->id === $conakry->id => 20000000,
                str_starts_with($nomAscii, 'bok') => 1000000,
                default => null,
            };

            $caisseEspecesDuSite[$site->nom] = $this->creerCaisse([
                'code' => "{$prefixe}-ESP",
                'libelle' => "Caisse principale {$site->nom}",
                'site_id' => $site->id,
                'type' => 'especes',
                'solde' => (float) ($site->solde_especes ?? 0),
                'plafond_retrait' => $plafond,
                'seuil_alerte' => $site->seuil_minimum_caisse,
                'actif' => (bool) $site->actif,
            ], $maintenant);
        }

        $caisseOm = null;
        if ($conakry) {
            $caisseOm = $this->creerCaisse([
                'code' => 'CKY-OM',
                'libelle' => 'Caisse Orange Money Conakry',
                'site_id' => $conakry->id,
                'type' => 'orange_money',
                'solde' => (float) DB::table('sites')->sum('solde_om'),
                'actif' => true,
            ], $maintenant);

            $this->creerCaisse([
                'code' => 'CKY-ATL',
                'libelle' => 'Caisse Atelier',
                'site_id' => $conakry->id,
                'type' => 'especes',
                'mode' => 'avance_fixe',
                'montant_avance' => 15000000,
                'solde' => 0,
                'actif' => false,
            ], $maintenant);
        }

        /* Caisse des bons déjà payés et des mouvements de caisse existants */
        foreach ($caisseEspecesDuSite as $nomSite => $idCaisse) {
            DB::table('bons_caisse')->where('site', $nomSite)->where('mode_paiement_effectif', 'especes')->update(['caisse_id' => $idCaisse]);
            DB::table('mouvements_caisse')->where('site', $nomSite)->where(fn ($q) => $q->where('type_caisse', 'especes')->orWhereNull('type_caisse'))->update(['caisse_id' => $idCaisse]);
        }
        if ($caisseOm) {
            DB::table('bons_caisse')->where('mode_paiement_effectif', 'orange_money')->update(['caisse_id' => $caisseOm]);
            DB::table('mouvements_caisse')->where('type_caisse', 'om')->update(['caisse_id' => $caisseOm]);
        }

        /* Les modifications de site en attente portaient sur des champs supprimés */
        DB::table('modifications_en_attente')
            ->where('type_entite', 'site_caisse')
            ->where('statut', 'en_attente')
            ->update([
                'statut' => 'refusee',
                'commentaire' => 'Annulée : soldes, plafonds et seuils sont désormais gérés par caisse (Paramétrage > Caisses).',
                'updated_at' => $maintenant,
            ]);
    }

    private function creerCaisse(array $valeurs, $maintenant): int
    {
        $id = DB::table('caisses')->insertGetId($valeurs + ['created_at' => $maintenant, 'updated_at' => $maintenant]);

        if ((float) $valeurs['solde'] != 0) {
            DB::table('ecritures_caisse')->insert([
                'caisse_id' => $id,
                'date_ecriture' => $maintenant,
                'sens' => $valeurs['solde'] > 0 ? 'entree' : 'sortie',
                'nature' => 'solde_initial',
                'montant' => abs($valeurs['solde']),
                'solde_avant' => 0,
                'solde_apres' => $valeurs['solde'],
                'libelle' => 'Reprise du solde à la création de la caisse',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }

        return $id;
    }

    /** CKY pour Conakry (code de la SFD), sinon les 3 premières lettres du site ; unique */
    private function prefixe(object $site, ?object $conakry, array &$codesPris): string
    {
        $prefixe = $conakry && $site->id === $conakry->id
            ? 'CKY'
            : (Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($site->nom)), 0, 3)) ?: 'SIT');

        if (in_array($prefixe, $codesPris, true)) {
            $prefixe .= $site->id;
        }
        $codesPris[] = $prefixe;

        return $prefixe;
    }
};
