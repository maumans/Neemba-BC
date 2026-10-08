<?php

namespace Tests\Feature\M12;

use App\Models\CodeAnalytique;
use App\Models\EtapeOdm;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\NumeroteurOdm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-2 — Saisie et soumission d'un ordre de mission (spec v2.2 §7.3, US-01 à US-06, US-11 ;
 * scénarios SC-20, SC-21, SC-22, SC-26, SC-27).
 */
class SaisieOdmTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $assistante;
    private Service $technique;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');   // les dates de l'annexe B sont à venir

        $this->technique = Service::create(['nom' => 'Technique', 'code' => '300', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'libelle' => 'Atelier', 'service_id' => $this->technique->id]);
        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique', 'site' => 'Conakry']);
        $this->thierno = User::factory()->create(['name' => 'Bah', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $this->yacouba = User::factory()->create(['name' => 'Barry', 'prenom' => 'Yacouba', 'service' => 'Technique', 'statut_cadre' => 'cadre']);
        $this->chefAtelier = User::factory()->create(['name' => 'Bangoura', 'prenom' => 'Thomas', 'service' => 'Technique', 'site' => 'Conakry']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        $this->assistante = User::factory()->create(['service' => 'Technique']);
        $this->technique->update(['diffusion_odm' => [$this->assistante->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Exemple B.1 : Conakry → Kouroussa, du 22/09 au 26/09 */
    private function saisieB1(?array $participants = null, array $autres = []): array
    {
        return $autres + [
            'type' => 'interieur',
            'service' => 'Technique',
            'code_analytique' => 'TECZZZ',
            'technique' => true,
            'but' => 'Dépannage d\'une chargeuse chez le client',
            'destinations' => ['Kouroussa'],
            'clients' => ['SMD'],
            'date_depart' => '2026-09-22',
            'date_retour_prevue' => '2026-09-26',
            'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id, 'base_vie' => false]],
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente']],
        ];
    }

    private function creer(array $saisie, ?User $auteur = null): OrdreMission
    {
        $reponse = $this->actingAs($auteur ?? $this->demandeur)->postJson(route('api.odm.creer'), $saisie)->assertCreated();

        return OrdreMission::findOrFail($reponse->json('odm.id'));
    }

    private function soumettre(OrdreMission $odm, ?string $cle = null, ?User $auteur = null)
    {
        return $this->actingAs($auteur ?? $this->demandeur)->postJson(route('api.odm.soumettre', $odm), ['cle_soumission' => $cle]);
    }

    /* ------------------------------------------------------------------
     * Brouillon et calcul
     * ------------------------------------------------------------------ */

    /** US-01 : brouillon pré-rempli ; RG-M12-02 : nature technique pré-cochée pour Technique */
    public function test_le_brouillon_reprend_le_service_du_demandeur(): void
    {
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [])->assertCreated();

        $reponse->assertJsonPath('odm.statut', 'BROUILLON')
            ->assertJsonPath('odm.service', 'Technique')
            ->assertJsonPath('odm.technique', true)
            ->assertJsonPath('odm.numero', null);
        $this->assertSame('creation', OrdreMission::sole()->historique()->sole()->action);
    }

    /** SC-20 (B.1) : calcul renvoyé par le serveur ; RG-M12-04 : informations reprises du référentiel */
    public function test_le_calcul_d_un_participant_est_renvoye_par_le_serveur(): void
    {
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $this->saisieB1())->assertCreated();

        $reponse->assertJsonPath('odm.participants.0.nom', 'BAH Thierno')
            ->assertJsonPath('odm.participants.0.statut_cadre', 'non_cadre')
            ->assertJsonPath('odm.participants.0.numero_om', '622334455')
            ->assertJsonPath('odm.calcul.participants.0.jours', 5)
            ->assertJsonPath('odm.calcul.participants.0.nuits', 4)
            ->assertJsonPath('odm.calcul.participants.0.indemnite_ligne_1', 625000)
            ->assertJsonPath('odm.calcul.participants.0.hebergement', 2000000)
            ->assertJsonPath('odm.calcul.participants.0.total', 3250000)
            ->assertJsonPath('odm.calcul.participants.0.frais_om', 32500)
            ->assertJsonPath('odm.calcul.total', 3250000)
            ->assertJsonPath('odm.calcul.libelle_indemnite_1', 'Indemnité de repas')
            ->assertJsonPath('odm.ordres_reparation.0', ['numero' => '11022219', 'type' => 'vente']);
    }

    /** SC-21 (B.2) et SC-27 (base vie) */
    public function test_plusieurs_participants_dont_un_loge_sur_base_vie(): void
    {
        $odm = $this->creer($this->saisieB1([
            ['user_id' => $this->thierno->id, 'base_vie' => false],
            ['user_id' => $this->yacouba->id, 'base_vie' => true],
        ]));

        $this->assertEquals(3250000 + 1250000, (float) $odm->total);
        $yacouba = $odm->participants()->where('user_id', $this->yacouba->id)->sole();
        $this->assertSame(0, $yacouba->nuits);
        $this->assertEquals(0, (float) $yacouba->hebergement);

        /* Retrait d'un participant au brouillon */
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['participants' => [['user_id' => $this->thierno->id]]])
            ->assertOk()->assertJsonCount(1, 'odm.participants');
        $this->assertEquals(3250000, (float) $odm->fresh()->total);
    }

    /* ------------------------------------------------------------------
     * Soumission
     * ------------------------------------------------------------------ */

    /** SC-20 : numéro, circuit, notifications ; idempotence (RG-M12-03, RG-M12-11, RG-M12-24) */
    public function test_la_soumission_numerote_ouvre_le_circuit_et_notifie(): void
    {
        $odm = $this->creer($this->saisieB1());

        $this->soumettre($odm, 'cle-1')->assertOk()
            ->assertJsonPath('odm.numero', 'N°1/AT/26')
            ->assertJsonPath('message', 'Ordre de mission N°1/AT/26 soumis pour validation.');

        $odm->refresh();
        $this->assertSame('SOUMIS', $odm->statut);
        $this->assertSame(['chef_atelier' => 'en_attente', 'daf' => 'a_venir', 'directeur_pays' => 'a_venir'],
            $odm->etapes()->pluck('statut', 'role')->all());
        $this->assertTrue(Notification::where('destinataire_id', $this->assistante->id)->where('type', 'odm_soumis')->exists());
        $this->assertTrue(Notification::where('destinataire_id', $this->chefAtelier->id)->where('type', 'odm_a_viser')->exists());
        $this->assertSame($odm->id, Notification::where('destinataire_id', $this->chefAtelier->id)->sole()->metadata['odm_id']);

        /* Double clic : même clé, même résultat, aucun nouveau numéro */
        $this->soumettre($odm, 'cle-1')->assertOk()->assertJsonPath('odm.numero', 'N°1/AT/26');
        $this->assertSame(3, EtapeOdm::count());
        $this->soumettre($odm, 'cle-2')->assertStatus(409)->assertJsonPath('message_cle', 'MSG-APP-019');
    }

    /** RG-M12-05 : champs obligatoires ; aucun numéro consommé par un échec */
    public function test_les_champs_obligatoires_bloquent_la_soumission(): void
    {
        $odm = $this->creer(['service' => 'Technique', 'technique' => false]);

        $erreurs = collect($this->soumettre($odm)->assertStatus(422)->json('erreurs'));

        $this->assertSame(
            ['CODE_ANALYTIQUE_OBLIGATOIRE', 'DESTINATION_OBLIGATOIRE', 'BUT_TROP_COURT', 'DEPART_OBLIGATOIRE', 'RETOUR_OBLIGATOIRE', 'PARTICIPANT_OBLIGATOIRE'],
            $erreurs->pluck('code')->all(),
        );
        $this->assertSame('Indiquez au moins une destination.', $erreurs->firstWhere('code', 'DESTINATION_OBLIGATOIRE')['message']);
        $this->assertSame('but', $erreurs->firstWhere('code', 'BUT_TROP_COURT')['champ']);
        $this->assertSame('BROUILLON', $odm->fresh()->statut);
        $this->assertSame(0, NumeroteurOdm::dernier('AT', 2026));
    }

    /** SC-22, RG-M12-02 : une mission technique exige un OR valide (MSG-M12-01, MSG-M03-07) */
    public function test_une_mission_technique_exige_un_or(): void
    {
        $odm = $this->creer($this->saisieB1(null, ['ordres_reparation' => []]));
        $this->soumettre($odm)->assertStatus(422)->assertJsonPath('message', 'Mission technique : rattachez au moins un OR.')
            ->assertJsonPath('champ', 'ordres_reparation');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['ordres_reparation' => [['numero' => '12345678']]]);
        $this->soumettre($odm)->assertStatus(422)->assertJsonPath('message', 'Numéro d\'OR invalide : 8 chiffres commençant par 110.');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['ordres_reparation' => [['numero' => '11022219', 'type' => 'garantie']]]);
        $this->soumettre($odm)->assertOk();
        $this->assertSame('garantie', $odm->ordresReparation()->sole()->type);
    }

    /** RG-M12-06 : retour avant le départ (MSG-M12-02) ; départ passé admis avec un motif */
    public function test_les_dates_de_la_mission(): void
    {
        $odm = $this->creer($this->saisieB1(null, ['date_depart' => '2026-09-26', 'date_retour_prevue' => '2026-09-22']));
        $this->soumettre($odm)->assertStatus(422)
            ->assertJsonPath('message', 'La date de retour doit être postérieure ou égale à la date de départ.');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['date_depart' => '2026-08-25', 'date_retour_prevue' => '2026-08-28']);
        $this->soumettre($odm)->assertStatus(422)->assertJsonPath('message', 'Départ dans le passé : indiquez le motif de la régularisation.');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['motif_depart_passe' => 'Mission urgente partie avant la saisie']);
        $this->soumettre($odm)->assertOk();
    }

    /** RG-M12-04 : nombre maximal de participants (paramètre) */
    public function test_le_nombre_de_participants_est_limite(): void
    {
        Parametre::majValeur('odm_participants_max', '1');
        $odm = $this->creer($this->saisieB1([['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]]));

        $this->soumettre($odm)->assertStatus(422)->assertJsonPath('message', 'Un ordre de mission compte au plus 1 participants.');
    }

    /** SC-29, RG-M02-04 : un ODM extérieur exige le statut cadre (MSG-M12-04), relu dans le référentiel à la soumission */
    public function test_un_odm_exterieur_exige_le_statut_cadre_et_le_mode_d_hebergement(): void
    {
        $this->thierno->update(['statut_cadre' => null]);
        $odm = $this->creer($this->saisieB1(null, ['type' => 'exterieur', 'destinations' => ['Abidjan']]));

        $erreurs = collect($this->soumettre($odm)->assertStatus(422)->json('erreurs'));
        $this->assertSame('Choisissez le mode d\'hébergement à l\'étranger.', $erreurs->firstWhere('code', 'HEBERGEMENT_EXTERIEUR_OBLIGATOIRE')['message']);
        $this->assertSame('BAH Thierno n\'a pas de statut cadre / non-cadre : contactez les RH.', $erreurs->firstWhere('code', 'STATUT_CADRE_MANQUANT')['message']);

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['hebergement_exterieur' => 'avant_depart']);
        $this->thierno->update(['statut_cadre' => 'cadre']);   // renseigné par les RH entre-temps
        $this->soumettre($odm)->assertStatus(422)->assertJsonPath('message', 'Indiquez le montant de la facture d\'hébergement de BAH Thierno.');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), [
            'participants' => [['user_id' => $this->thierno->id, 'hebergement_facture' => 1200000]],
        ]);
        $this->soumettre($odm)->assertOk();
        $this->assertSame('cadre', $odm->participants()->sole()->statut_cadre);
    }

    /* ------------------------------------------------------------------
     * Chevauchement (RG-M12-16, SC-26)
     * ------------------------------------------------------------------ */

    public function test_un_chevauchement_bloque_la_soumission(): void
    {
        $premier = $this->creer($this->saisieB1());
        $this->soumettre($premier)->assertOk();

        /* Un brouillon ou un ODM annulé ne compte pas (Q25) */
        $brouillon = $this->creer($this->saisieB1());
        $annule = $this->creer($this->saisieB1());
        $annule->update(['statut' => 'ANNULE']);

        $second = $this->creer($this->saisieB1(null, ['date_depart' => '2026-09-26', 'date_retour_prevue' => '2026-09-30']));
        $this->soumettre($second)->assertStatus(422)
            ->assertJsonPath('code', 'CHEVAUCHEMENT')
            ->assertJsonPath('regle', 'RG-M12-16')
            ->assertJsonPath('chevauchement', true)
            ->assertJsonPath('message', 'BAH Thierno est déjà en mission du 22/09/2026 au 26/09/2026 (ODM N°1/AT/26).');

        /* Missions qui se suivent (retour le 26, départ le 27) : pas de chevauchement */
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $second), ['date_depart' => '2026-09-27']);
        $this->soumettre($second)->assertOk();
        $this->assertSame('BROUILLON', $brouillon->fresh()->statut);
    }

    /** RG-M12-16 : dérogation demandée par le demandeur, accordée par le DAF, retirée si les dates changent */
    public function test_la_derogation_du_daf(): void
    {
        $daf = User::factory()->role('daf')->create();
        $this->soumettre($this->creer($this->saisieB1()))->assertOk();
        $second = $this->creer($this->saisieB1());

        $this->actingAs($this->demandeur)->postJson(route('api.odm.derogation', $second), ['motif' => 'court'])
            ->assertStatus(422)->assertJsonPath('message', 'Indiquez le motif de la dérogation (10 caractères minimum).');
        $this->actingAs($this->demandeur)->postJson(route('api.odm.derogation', $second), ['motif' => 'Deux équipes sur deux sites voisins, une seule indemnité'])
            ->assertOk()->assertJsonPath('odm.derogation.statut', 'demandee');
        $this->assertTrue(Notification::where('destinataire_id', $daf->id)->where('type', 'odm_derogation')->exists());

        /* Le DAF voit la demande, même sur un brouillon */
        $this->actingAs($daf)->get(route('odm.index'))->assertInertia(fn (Assert $page) => $page->has('derogations', 1));
        $this->actingAs($daf)->get(route('odm.show', $second))->assertOk();
        $this->actingAs($this->demandeur)->post(route('odm.derogation', $second), ['decision' => 'accorder', 'motif' => 'Accord donné'])->assertForbidden();
        $this->actingAs($daf)->post(route('odm.derogation', $second), ['decision' => 'accorder', 'motif' => 'Une seule indemnité sera payée'])
            ->assertSessionHasNoErrors();
        $this->assertSame('accordee', $second->fresh()->derogation_statut);
        $this->assertSame('derogation_accordee', $second->historique()->reorder('id', 'desc')->value('action'));

        /* Modifier les dates retire la dérogation */
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $second), ['date_retour_prevue' => '2026-09-27'])
            ->assertJsonPath('odm.derogation', null);
        $this->soumettre($second)->assertStatus(422)->assertJsonPath('code', 'CHEVAUCHEMENT');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $second), ['date_retour_prevue' => '2026-09-26']);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.derogation', $second), ['motif' => 'Deux équipes sur deux sites voisins']);
        $this->actingAs($daf)->post(route('odm.derogation', $second), ['decision' => 'accorder', 'motif' => 'Une seule indemnité sera payée']);
        $this->soumettre($second)->assertOk()->assertJsonPath('odm.numero', 'N°2/AT/26');
    }

    /* ------------------------------------------------------------------
     * Droits, annulation, écrans
     * ------------------------------------------------------------------ */

    public function test_seuls_les_auteurs_modifient_un_brouillon(): void
    {
        $odm = $this->creer($this->saisieB1());

        $this->actingAs($this->thierno)->putJson(route('api.odm.enregistrer', $odm), ['but' => 'Autre but de mission'])
            ->assertForbidden()->assertJsonPath('message_cle', 'MSG-APP-020');
        $this->soumettre($odm)->assertOk();
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['but' => 'Autre but de mission'])
            ->assertStatus(409)->assertJsonPath('message', 'Cet ordre de mission a déjà été soumis : il ne peut plus être modifié ni soumis à nouveau.');
    }

    public function test_la_liste_montre_a_chacun_ses_ordres_de_mission(): void
    {
        $soumis = $this->creer($this->saisieB1());
        $this->soumettre($soumis)->assertOk();
        $brouillon = $this->creer($this->saisieB1([['user_id' => $this->yacouba->id]]));
        $autre = User::factory()->create(['service' => 'Logistique']);
        $dp = User::factory()->role('directeur_pays')->create();

        $ids = fn (User $u) => collect($this->actingAs($u)->get(route('odm.index'))->viewData('page')['props']['odms']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$soumis->id, $brouillon->id], $ids($this->demandeur));
        $this->assertSame([$soumis->id], $ids($this->thierno));        // participant, ODM soumis
        $this->assertSame([], $ids($this->yacouba));                     // participant d'un brouillon : pas encore
        $this->assertSame([$soumis->id], $ids($this->chefAtelier));    // chef d'atelier du service
        $this->assertSame([$soumis->id], $ids($dp));
        $this->assertSame([], $ids($autre));
        $this->actingAs($autre)->get(route('odm.show', $soumis))->assertForbidden();
    }

    /** RG-M12-22 : le demandeur annule tant qu'aucun bon n'est généré */
    public function test_le_demandeur_annule_son_odm(): void
    {
        $odm = $this->creer($this->saisieB1());
        $this->soumettre($odm)->assertOk();

        $this->actingAs($this->thierno)->postJson(route('api.odm.annuler', $odm), ['motif' => 'Mission reportée'])->assertForbidden();
        $this->actingAs($this->demandeur)->postJson(route('api.odm.annuler', $odm), ['motif' => 'Mission reportée'])
            ->assertOk()->assertJsonPath('redirection', route('odm.index'));

        $odm->refresh();
        $this->assertSame('ANNULE', $odm->statut);
        $this->assertSame('Mission reportée', $odm->motif_annulation);
        $this->assertSame(0, $odm->etapes()->whereIn('statut', ['en_attente', 'a_venir'])->count());
    }

    public function test_les_ecrans_des_ordres_de_mission(): void
    {
        $odm = $this->creer($this->saisieB1());

        $this->actingAs($this->demandeur)->get(route('odm.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Odm/Formulaire')->where('odm', null)->where('defauts.service', 'Technique')
                ->where('maxParticipants', 10)->has('codesAnalytiques', 1));
        $this->actingAs($this->demandeur)->get(route('odm.edit', $odm))
            ->assertInertia(fn (Assert $page) => $page->component('Odm/Formulaire')->where('odm.id', $odm->id)->where('odm.calcul.total', 3250000));
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->component('Odm/Show')->where('odm.libelle', 'Brouillon')->where('peutModifier', true)->has('odm.historique', 1));

        $this->soumettre($odm)->assertOk();
        $this->actingAs($this->demandeur)->get(route('odm.edit', $odm))->assertForbidden();
    }

    /** Q28 : préfixe et reprise du carnet papier depuis le paramétrage des services */
    public function test_le_parametrage_des_services_reprend_le_carnet_papier(): void
    {
        $administrateur = User::factory()->role('administrateur')->create();
        $this->actingAs($administrateur)->put(route('parametrage.services.update', $this->technique), [
            'nom' => 'Technique', 'code' => '300', 'prefixe_odm' => 'at', 'diffusion_odm' => [$this->assistante->id], 'reprise_carnet' => 285,
        ])->assertSessionHasNoErrors();

        $this->assertSame('AT', $this->technique->fresh()->prefixe_odm);
        $odm = $this->creer($this->saisieB1());
        $this->soumettre($odm)->assertOk()->assertJsonPath('odm.numero', 'N°286/AT/26');

        $this->actingAs($administrateur)->put(route('parametrage.services.update', $this->technique), [
            'nom' => 'Technique', 'prefixe_odm' => 'AT', 'reprise_carnet' => 100,
        ])->assertSessionHasErrors(['reprise_carnet' => 'Le compteur AT est déjà au n° 286 : il ne peut pas reculer.']);
    }
}
