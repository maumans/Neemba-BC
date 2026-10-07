<?php

namespace Tests\Feature\Socle;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Montant en lettres serveur (RG-BC-08), statut Annulé (RG-BC-31) et journal d'audit (SFD §1.2).
 */
class BonCaisseSocleTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_montant_en_lettres_est_toujours_calcule_par_le_serveur(): void
    {
        $bon = BonCaisse::factory()->create(['montant' => 1200000, 'montant_lettres' => 'Un million deux cents mille']);

        $this->assertSame('un million deux cent mille francs guinéens', $bon->fresh()->montant_lettres);

        $bon->update(['montant' => 80000]);
        $this->assertSame('quatre-vingt mille francs guinéens', $bon->fresh()->montant_lettres);
    }

    public function test_un_bon_peut_passer_au_statut_annule(): void
    {
        $bon = BonCaisse::factory()->statut('REJETE')->create();

        $bon->update(['statut' => 'ANNULE']);

        $this->assertSame('ANNULE', $bon->fresh()->statut);
        $this->assertSame('Annulé', $bon->fresh()->statut_label);
    }

    public function test_une_modification_est_journalisee_avec_ancienne_et_nouvelle_valeur(): void
    {
        $demandeur = User::factory()->create();
        $bon = BonCaisse::factory()->create(['demandeur_id' => $demandeur->id, 'montant' => 500000, 'motif' => 'Achat carburant mission Kankan']);

        $this->actingAs($demandeur);
        $bon->update(['montant' => 650000, 'motif' => 'Achat carburant mission Siguiri']);

        $ligne = HistoriqueAction::where('bon_caisse_id', $bon->id)->where('action', HistoriqueAction::ACTION_MODIFICATION)->sole();
        $this->assertSame($demandeur->id, $ligne->utilisateur_id);
        $this->assertEqualsCanonicalizing([
            ['champ' => 'montant', 'libelle' => 'Montant', 'avant' => '500000.00', 'apres' => '650000.00'],
            ['champ' => 'motif', 'libelle' => 'Motif', 'avant' => 'Achat carburant mission Kankan', 'apres' => 'Achat carburant mission Siguiri'],
        ], $ligne->metadata['changements']);
    }

    public function test_un_changement_de_statut_seul_n_ecrit_pas_de_ligne_de_modification(): void
    {
        $bon = BonCaisse::factory()->create();

        $bon->update(['statut' => 'EN_ATTENTE_CHEF_SERVICE']);

        $this->assertFalse(HistoriqueAction::where('bon_caisse_id', $bon->id)->exists());
    }

    public function test_la_correction_du_code_analytique_garde_son_action_propre(): void
    {
        $bon = BonCaisse::factory()->create(['code_analytique' => 'DAFZZZ']);

        $bon->modifierAvecJournal(['code_analytique' => 'ADAZZZ'], HistoriqueAction::ACTION_MODIFICATION_CODE_ANALYTIQUE, 'Code analytique modifié par CDG');

        $lignes = HistoriqueAction::where('bon_caisse_id', $bon->id)->get();
        $this->assertCount(1, $lignes);
        $this->assertSame(HistoriqueAction::ACTION_MODIFICATION_CODE_ANALYTIQUE, $lignes[0]->action);
        /* assertEquals : MySQL réordonne les clés des objets JSON */
        $this->assertEquals(
            [['champ' => 'code_analytique', 'libelle' => 'Code analytique', 'avant' => 'DAFZZZ', 'apres' => 'ADAZZZ']],
            $lignes[0]->metadata['changements'],
        );
    }
}
