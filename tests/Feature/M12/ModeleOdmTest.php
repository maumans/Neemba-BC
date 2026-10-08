<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\ParticipantOdm;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\CalculOdm;
use App\Services\Odm\NumeroteurOdm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M12-1 — Modèle de données des ODM, numérotation (RG-M12-03) et barèmes en vigueur (RG-M12-07, RG-M12-25).
 */
class ModeleOdmTest extends TestCase
{
    use RefreshDatabase;

    /** RG-M12-03 : N°[séquence]/[préfixe]/[AA], une séquence par préfixe et par année */
    public function test_la_numerotation_suit_une_sequence_par_prefixe_et_par_annee(): void
    {
        $numeros = DB::transaction(fn () => [
            NumeroteurOdm::prochain('AT', 2026)['numero'],
            NumeroteurOdm::prochain('AT', 2026)['numero'],
            NumeroteurOdm::prochain('LOG', 2026)['numero'],
            NumeroteurOdm::prochain('AT', 2027)['numero'],
        ]);

        $this->assertSame(['N°1/AT/26', 'N°2/AT/26', 'N°1/LOG/26', 'N°1/AT/27'], $numeros);
    }

    /** Décision Q28 : reprise des carnets papier (après N°285/AT/26 → 286) */
    public function test_la_sequence_reprend_le_dernier_numero_des_carnets(): void
    {
        NumeroteurOdm::definirDepart('AT', 2026, 285);

        $this->assertSame(['numero' => 'N°286/AT/26', 'prefixe' => 'AT', 'sequence' => 286, 'annee' => 2026], NumeroteurOdm::prochain('AT', 2026));
        $this->assertSame(286, NumeroteurOdm::dernier('AT', 2026));
    }

    public function test_un_numero_deja_attribue_n_est_jamais_reutilise(): void
    {
        OrdreMission::factory()->numerote(300)->create(['statut' => 'SOUMIS']);

        $this->assertSame('N°301/AT/26', NumeroteurOdm::prochain('AT', 2026)['numero']);
    }

    /** Q28 : préfixe du service, « AT » pour Technique ; à défaut, les trois premières lettres du service */
    public function test_le_prefixe_vient_du_service(): void
    {
        Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        Service::create(['nom' => 'Logistique']);

        $this->assertSame('AT', NumeroteurOdm::prefixePour('Technique'));
        $this->assertSame('LOG', NumeroteurOdm::prefixePour('Logistique'));
        $this->assertSame('MOY', NumeroteurOdm::prefixePour('Moyens Généraux'));
        $this->assertSame('ODM', NumeroteurOdm::prefixePour(null));
    }

    /** RG-M12-07, RG-M12-25 : barèmes lus dans les paramètres, à figer avec l'ODM */
    public function test_les_baremes_en_vigueur_viennent_des_parametres(): void
    {
        Parametre::majValeur('odm_indemnite_journaliere', '300000');

        $baremes = CalculOdm::baremesEnVigueur();
        $this->assertSame(300000, $baremes['indemnite_journaliere']);
        $this->assertSame(500000, $baremes['hebergement_nuit']);
        $this->assertSame(34000, $baremes['bareme_fcfa_cadre']);
        $this->assertSame('Indemnité de repas', $baremes['libelle_indemnite_1']);
        $this->assertCount(2, $baremes['frais_om_paliers']);
    }

    public function test_relations_de_l_odm_avec_ses_participants_ses_bons_et_ses_segments(): void
    {
        $odm = OrdreMission::factory()->numerote(285)->create(['statut' => 'VALIDE']);
        $technicien = User::factory()->create(['name' => 'Bah', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $participant = $odm->participants()->create(ParticipantOdm::depuisUtilisateur($technicien) + ['base_vie' => true]);
        $bon = BonCaisse::factory()->create(['odm_id' => $odm->id, 'odm_participant_id' => $participant->id, 'genere_par_odm' => true]);
        $odm->ordresReparation()->create(['numero' => '11022219', 'type' => 'garantie']);

        $this->assertSame('BAH Thierno', $participant->nom);
        $this->assertSame('non_cadre', $participant->statut_cadre);
        $this->assertSame('622334455', $participant->numero_om);
        $this->assertTrue($bon->ordreMission->is($odm));
        $this->assertTrue($bon->participantOdm->is($participant));
        $this->assertTrue($bon->genere_par_odm);
        $this->assertSame(1, $odm->bons()->count());
        $this->assertSame('11022219', $odm->ordresReparation()->sole()->numero);

        /* Segments : l'ODM initial et ses prolongations (Q23) */
        $prolongation = OrdreMission::factory()->numerote(290)->create([
            'mission_id' => $odm->id, 'segment_precedent_id' => $odm->id, 'rang' => 2,
            'date_depart' => '2026-09-27', 'date_retour_prevue' => '2026-10-03',
        ]);
        $this->assertSame('Prolongation 1 de N°285/AT/26', $prolongation->libelle_prolongation);
        $this->assertNull($odm->libelle_prolongation);
        $this->assertSame([$odm->id, $prolongation->id], $prolongation->segmentsDeLaMission()->pluck('id')->all());
        $this->assertTrue($prolongation->initial()->is($odm));
    }

    /** RG-M12-11 : chef d'atelier → DAF → DP ; étape RH seulement si le paramètre est actif ; visa DAF ouvert (Q27) */
    public function test_le_circuit_de_l_odm(): void
    {
        $this->assertSame(['chef_atelier', 'daf', 'directeur_pays'], OrdreMission::niveauxDuCircuit());
        $this->assertSame(['daf', 'daf_adjoint', 'chef_comptable'], OrdreMission::NIVEAUX['daf']['roles']);
        $this->assertSame(['directeur_pays', 'dp_adjoint'], OrdreMission::NIVEAUX['directeur_pays']['roles']);

        Parametre::majValeur('odm_etape_rh', 'true');
        $this->assertSame(['chef_atelier', 'daf', 'rh', 'directeur_pays'], OrdreMission::niveauxDuCircuit());
    }

    public function test_le_journal_de_l_odm(): void
    {
        $odm = OrdreMission::factory()->create();
        HistoriqueOdm::enregistrer($odm, 'creation', null, 'BROUILLON', $odm->demandeur_id, 'ODM créé', ['participants' => 1]);

        $entree = $odm->historique()->sole();
        $this->assertSame('creation', $entree->action);
        $this->assertSame(['participants' => 1], $entree->metadonnees);
        $this->assertNotNull($entree->created_at);
    }

    /** RG-M12-21 : 9 statuts */
    public function test_les_neuf_statuts(): void
    {
        $this->assertSame(
            ['BROUILLON', 'SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE', 'ANNULE'],
            array_keys(OrdreMission::STATUTS),
        );
        $this->assertSame('Brouillon', OrdreMission::factory()->make()->libelle);
    }
}
