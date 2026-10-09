<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\OrdreMission;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\TauxChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-4 — Génération des bons d'un ODM validé et paiement (RG-M12-10, RG-M12-13 à RG-M12-15, RG-M12-21, RG-M12-29,
 * RG-M03-15, RG-M03-22) ; scénarios SC-23, SC-28, SC-29 et SC-37.
 */
class GenerationBonsOdmTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
    private User $dp;
    private User $caissier;
    private Caisse $om;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');
        Parametre::majValeur('seuil_validation_dp', '1500000');   // spec v2.2 §6.6 (valeur du référentiel de Neemba)

        $conakry = Site::factory()->conakry()->create();
        $this->om = Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id]);
        $this->om->crediter(50000000, 'approvisionnement');
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);

        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $this->yacouba = User::factory()->create(['name' => 'BARRY', 'prenom' => 'Yacouba', 'service' => 'Technique', 'statut_cadre' => 'cadre', 'numero_om' => '622112233']);
        $this->chefAtelier = User::factory()->create(['service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        /* Valideurs des bons générés : sans eux, leurs étapes seraient sautées (RG-M04-09, Q48) */
        User::factory()->role('responsable_service')->create(['service' => 'Technique']);
        User::factory()->role('controle_gestion')->create();
        $this->daf = User::factory()->role('daf')->create();
        $this->dp = User::factory()->role('directeur_pays')->create();
        $this->caissier = User::factory()->role('caissier')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ODM soumis puis visé par le chef d'atelier, le DAF et le DP */
    private function odmValide(array $autres = [], ?array $participants = null): OrdreMission
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => true, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]],
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente']],
        ])->assertCreated()->json('odm.id'));
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }
        $this->assertSame('VALIDE', $odm->fresh()->statut);

        return $odm->fresh();
    }

    private function generer(OrdreMission $odm, array $options = [])
    {
        return $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm), $options);
    }

    private function payer(BonCaisse $bon, string $mode = 'orange_money')
    {
        OtpValidation::create([
            'bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false,
        ]);

        return $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => $mode]);
    }

    /* ------------------------------------------------------------------ */

    /** SC-23 (B.2) : un BD par participant, champs repris de l'ODM, circuit complet, sans justificatif */
    public function test_un_bon_par_participant(): void
    {
        $odm = $this->odmValide();

        $this->generer($odm)->assertRedirect(route('odm.show', $odm))->assertSessionHas('success');

        $bons = BonCaisse::orderBy('id')->get();
        $this->assertCount(2, $bons);
        $bon = $bons->first();
        $this->assertSame('BD', $bon->type_bon);
        $this->assertTrue($bon->genere_par_odm);
        $this->assertEquals(3250000, (float) $bon->montant);
        $this->assertSame($this->thierno->id, $bon->beneficiaire_id);
        $this->assertSame('Thierno BAH', $bon->beneficiaire);
        $this->assertSame('+224622334455', $bon->telephone_beneficiaire);   // n° OM du participant
        $this->assertSame('mission', $bon->categorie_depense);
        $this->assertSame('orange_money', $bon->mode_paiement);
        $this->assertSame(['11022219'], $bon->references_or);
        $this->assertSame('TECZZZ', $bon->code_analytique);
        $this->assertSame('Indemnités de mission — ODM N°1/AT/26, Kouroussa, du 22/09/2026 au 26/09/2026 — BAH Thierno', $bon->motif);
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->statut);
        $this->assertSame(0, $bon->piecesJointes()->count());
        /* 3 250 000 GNF > seuil : visa du DP sur le bon, en plus de celui donné sur l'ODM (annexe B.1) */
        $this->assertSame(['responsable_service', 'controle_gestion', 'daf', 'directeur_pays'], $bon->validations()->orderBy('niveau')->pluck('role')->all());

        $odm->refresh();
        $this->assertSame('BONS_GENERES', $odm->statut);
        $this->assertSame([$bons[0]->id, $bons[1]->id], $odm->participants()->orderBy('id')->pluck('bon_caisse_id')->all());
        $this->assertStringContainsString($bon->numero, $odm->historique()->where('action', 'generation_bons')->value('commentaire'));

        /* Rien à regénérer */
        $this->generer($odm)->assertSessionHas('error', 'Tous les bons de cet ordre de mission sont déjà générés.');
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->has('bons', 2)->where('generation.reste_a_generer', 0));
    }

    /** RG-M03-22 : les champs d'un bon généré sont verrouillés */
    public function test_un_bon_genere_ne_se_modifie_pas(): void
    {
        $odm = $this->odmValide();
        $this->generer($odm);
        $bon = BonCaisse::first();
        $bon->update(['statut' => 'REJETE']);

        $this->actingAs($this->demandeur)->get(route('bons-caisse.edit', $bon))->assertForbidden();
        $this->actingAs($this->demandeur)->patchJson(route('api.bons.enregistrer', $bon), ['montant' => 5000000])->assertForbidden();
        $this->assertEquals(3250000, (float) $bon->fresh()->montant);
    }

    /** Un participant dont le bon a été annulé reçoit un nouveau bon à la génération suivante */
    public function test_un_bon_annule_est_regenere(): void
    {
        $odm = $this->odmValide();
        $this->generer($odm);
        $annule = BonCaisse::where('beneficiaire_id', $this->thierno->id)->sole();
        $annule->update(['statut' => 'ANNULE']);

        $this->generer($odm)->assertSessionHas('success');

        $this->assertSame(2, BonCaisse::where('beneficiaire_id', $this->thierno->id)->count());
        $this->assertSame(1, BonCaisse::where('beneficiaire_id', $this->yacouba->id)->count());
    }

    /** SC-23 (B.2) : bon groupé, versé au participant désigné ; frais OM 0,8 % sur 6 500 000 */
    public function test_un_bon_groupe(): void
    {
        Parametre::majValeur('odm_mode_generation', 'groupe');
        $odm = $this->odmValide();

        $this->generer($odm, ['beneficiaire_groupe_id' => $this->yacouba->id])->assertSessionHas('success');

        $bon = BonCaisse::sole();
        $this->assertEquals(6500000, (float) $bon->montant);
        $this->assertSame($this->yacouba->id, $bon->beneficiaire_id);
        $this->assertSame('+224622112233', $bon->telephone_beneficiaire);
        $this->assertNull($bon->odm_participant_id);
        $this->assertSame([$bon->id, $bon->id], $odm->participants()->pluck('bon_caisse_id')->all());
        $this->assertStringContainsString('bon groupé de 2 participant(s), versé à BARRY Yacouba', $bon->motif);

        $bon->update(['statut' => 'APPROUVE']);
        $this->payer($bon)->assertSessionHas('success');
        $bon->refresh();
        $this->assertEquals(52000, (float) $bon->frais_om);
        $this->assertEquals(6552000, (float) $bon->montant_verse);
    }

    /** SC-37 : BP « avance pour frais réels », lié à la mission ; un seul ; paramètre « ODM générant un BP » */
    public function test_le_bp_d_avance_pour_frais_reels(): void
    {
        $odm = $this->odmValide();

        $this->generer($odm, ['bp' => ['montant' => '1 000 000', 'motif' => 'court', 'beneficiaire_id' => $this->thierno->id]])
            ->assertSessionHasErrors(['bp_motif' => 'Le motif doit comporter au moins 10 caractères.']);
        $this->assertSame(0, BonCaisse::count());   // tout ou rien

        $this->generer($odm, ['bp' => ['montant' => '1 000 000', 'motif' => 'Carburant et péages de la mission', 'beneficiaire_id' => $this->thierno->id]])
            ->assertSessionHas('success');

        $bp = BonCaisse::where('type_bon', 'BP')->sole();
        $this->assertEquals(1000000, (float) $bp->montant);
        $this->assertTrue($bp->lie_mission);
        $this->assertSame('2026-09-26', $bp->date_retour_mission->toDateString());
        $this->assertSame($odm->id, $bp->odm_id);
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bp->statut);
        $this->assertSame(3, BonCaisse::count());   // 2 BD + 1 BP
        /* RG-M07-02 : 3 jours ouvrés après le retour (samedi 26/09 → mercredi 30/09) */
        $this->assertSame('2026-09-30', $bp->dateLimiteRegularisation(Carbon::parse('2026-09-20'))->toDateString());

        $this->generer($odm, ['bp' => ['montant' => '500000', 'motif' => 'Second bon provisoire']])
            ->assertSessionHas('error', 'Un bon provisoire est déjà généré pour cet ordre de mission.');
    }

    public function test_le_bp_peut_etre_desactive(): void
    {
        Parametre::majValeur('odm_genere_bp', 'false');
        $odm = $this->odmValide();

        $this->generer($odm, ['bp' => ['montant' => '1000000', 'motif' => 'Carburant et péages de la mission']])
            ->assertSessionHas('error', 'Le paramétrage ne permet pas de générer un bon provisoire depuis un ordre de mission.');
        $this->assertSame(0, BonCaisse::count());
    }

    /** RG-M12-13 : seul un ODM validé, par son demandeur */
    public function test_la_generation_exige_un_odm_valide_et_son_demandeur(): void
    {
        $odm = $this->odmValide();
        $this->actingAs($this->thierno)->post(route('odm.generer-bons', $odm))
            ->assertSessionHas('error', 'Vous ne pouvez pas modifier cet ordre de mission.');

        $brouillon = OrdreMission::factory()->create(['demandeur_id' => $this->demandeur->id, 'initiateur_id' => $this->demandeur->id]);
        $this->generer($brouillon)->assertSessionHas('error', 'Seul un ordre de mission validé permet de générer des bons de caisse.');
        $this->assertSame(0, BonCaisse::count());
    }

    /** SC-28, RG-M12-15, Q49 : à la charge du client ; avancé par Neemba (bons, « à refacturer ») ou payé directement (aucun bon) */
    public function test_odm_a_la_charge_du_client(): void
    {
        $odm = $this->odmValide(['prise_en_charge' => 'client', 'mode_client' => 'avance', 'clients' => ['SMD']]);
        $this->assertTrue($odm->a_refacturer);
        $this->assertEquals(6500000, (float) $odm->montant_a_refacturer);   // 2 × 3 250 000 (annexe B.1)
        $this->generer($odm)->assertSessionHas('success');
        $this->assertSame(2, BonCaisse::count());

        $autre = $this->odmValide(['prise_en_charge' => 'client', 'mode_client' => 'direct', 'clients' => ['SMD'],
            'date_depart' => '2026-10-05', 'date_retour_prevue' => '2026-10-06']);
        $this->generer($autre)->assertSessionHas('error', 'Tous les frais de cet ordre de mission sont payés directement par le client : aucun bon de caisse n\'est généré.');
        $this->actingAs($this->demandeur)->get(route('odm.show', $autre))
            ->assertInertia(fn (Assert $page) => $page->where('sansBon', true)->where('generation', null)->where('odm.a_refacturer', false));
    }

    /** SC-29, RG-M12-10 : ODM extérieur estimé au dernier taux, recalculé au taux du jour du paiement */
    public function test_odm_exterieur_estime_puis_recalcule_au_paiement(): void
    {
        $odm = $this->odmValide(
            ['type' => 'exterieur', 'hebergement_exterieur' => 'filiale', 'destinations' => ['Abidjan'], 'technique' => false, 'ordres_reparation' => []],
            [['user_id' => $this->thierno->id]],
        );

        $this->generer($odm)->assertSessionHas('error', 'Aucun taux FCFA → GNF n\'a encore été saisi : le montant des bons ne peut pas être estimé. Contactez la Trésorerie.');

        TauxChange::create(['date_taux' => today()->subDay(), 'taux' => 14.5]);
        $this->generer($odm)->assertSessionHas('success');
        $bon = BonCaisse::sole();
        $this->assertEquals(110000, (float) $bon->montant_fcfa);           // 5 jours × 22 000 FCFA
        $this->assertEquals(1595000, (float) $bon->montant);               // × 14,5
        $this->assertEquals(14.5, (float) $bon->taux_change_estime);
        $this->assertEquals(1595000, (float) $bon->montant_estime);

        /* Paiement sans taux du jour : bloqué (MSG-M12-05), l'OTP n'est pas consommé */
        $bon->update(['statut' => 'APPROUVE']);
        $this->payer($bon)->assertSessionHas('error', 'Aucun taux de change FCFA → GNF saisi aujourd\'hui : paiement impossible. Contactez la Trésorerie.');
        $this->assertSame('APPROUVE', $bon->fresh()->statut);

        TauxChange::create(['date_taux' => today(), 'taux' => 15]);
        $this->payer($bon)->assertSessionHas('success');
        $bon->refresh();
        $this->assertEquals(1650000, (float) $bon->montant);                // × 15, le jour du paiement
        $this->assertEquals(15, (float) $bon->taux_change_applique);
        $this->assertEquals(16500, (float) $bon->frais_om);
        $this->assertEquals(1666500, (float) $bon->montant_verse);
        $journal = HistoriqueAction::where('bon_caisse_id', $bon->id)->where('commentaire', 'like', 'Montant recalculé%')->sole();
        $this->assertStringContainsString('estimé 1' . self::NBSP . '595' . self::NBSP . '000' . self::NBSP . 'GNF au taux 14,5, payé 1' . self::NBSP . '650' . self::NBSP . '000' . self::NBSP . 'GNF (écart +55' . self::NBSP . '000' . self::NBSP . 'GNF)', $journal->commentaire);
    }

    /** RG-M12-21 : l'ODM passe « Payé » quand tous ses bons sont payés */
    public function test_l_odm_est_paye_quand_tous_ses_bons_le_sont(): void
    {
        $odm = $this->odmValide();
        $this->generer($odm);
        [$premier, $second] = BonCaisse::orderBy('id')->get()->all();
        $premier->update(['statut' => 'APPROUVE']);
        $second->update(['statut' => 'APPROUVE']);

        $this->payer($premier)->assertSessionHas('success');
        $this->assertSame('BONS_GENERES', $odm->fresh()->statut);

        $this->payer($second)->assertSessionHas('success');
        $this->assertSame('PAYE', $odm->fresh()->statut);
        $this->assertSame('paiement', $odm->historique()->reorder('id', 'desc')->value('action'));
    }
}
