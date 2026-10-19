<?php

namespace Tests\Feature\LotE3;

use App\Models\BaremeOdm;
use App\Models\CodeAnalytique;
use App\Models\Entite;
use App\Models\ModificationEnAttente;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\TypeOr;
use App\Models\User;
use App\Services\Odm\CalculOdm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot E3 (spec v2.4, RG-M01-09, RG-M02-10, RG-M02-01, RG-M03-13, §6.5, §6.6) : gestionnaire des référentiels, barèmes
 * ODM datés sans double validation, types d'OR en référentiel.
 */
class ReferentielsMetierTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00');
        $this->admin = User::factory()->role('administrateur')->create();
        $this->gestionnaire = User::factory()->create(['prenom' => 'Maïmouna', 'name' => 'BARRY']);
        $this->gestionnaire->ajouterRoles(['gestionnaire_referentiels']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** RG-M01-09 : l'administrateur garde comptes, entités, sites, caisses, paramètres et sécurité ; le gestionnaire tient le reste */
    public function test_les_onglets_et_les_droits_sont_repartis(): void
    {
        $this->actingAs($this->admin)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('onglets', ['entites', 'sites', 'caisses', 'parametres', 'securite'])
            ->where('services', [])->where('baremesOdm', null));
        $this->actingAs($this->gestionnaire)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('onglets', ['services', 'codes', 'types', 'motifs', 'motifs-rejet', 'motifs-visiteur', 'types-or', 'baremes-odm'])
            ->where('caisses', [])->where('securite', null)->has('baremesOdm.historique'));

        $this->actingAs($this->admin)->post(route('parametrage.services.store'), ['nom' => 'Atelier'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('parametrage.types-or.store'), ['code' => 'interne', 'libelle' => 'Interne'])->assertForbidden();
        $this->actingAs($this->gestionnaire)->post(route('parametrage.sites.store'), ['nom' => 'Kamsar'])->assertForbidden();
        $this->actingAs($this->gestionnaire)->put(route('parametrage.securite.update'), [])->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('parametrage.index'))->assertForbidden();

        /* §6.5 : clients ajoutés par le gestionnaire ; plus par l'administrateur */
        $this->actingAs($this->gestionnaire)->get(route('clients.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('clients.index'))->assertForbidden();
    }

    /** Reprise : les barèmes de la spec sont dans le référentiel daté, les paramètres correspondants ont disparu */
    public function test_les_baremes_sont_repris_dans_le_referentiel(): void
    {
        $this->assertSame([
            'indemnite_journaliere' => 250000, 'libelle_indemnite_1' => 'Indemnité de repas', 'libelle_indemnite_2' => 'Indemnité de déplacement',
            'hebergement_nuit' => 500000, 'bareme_fcfa_non_cadre' => 22000, 'bareme_fcfa_cadre' => 34000,
        ], BaremeOdm::tous());
        $this->assertFalse(Parametre::where('cle', 'odm_indemnite_journaliere')->exists());
        $this->assertSame(6, BaremeOdm::whereNull('entite_id')->count());
    }

    /** RG-M02-10, RG-M02-01 : nouvelle valeur datée, sans double validation ; appliquée aux ODM soumis à partir de sa date */
    public function test_un_bareme_date_s_applique_a_partir_de_sa_date(): void
    {
        $this->actingAs($this->gestionnaire)->post(route('parametrage.baremes-odm.store'), [
            'cle' => 'indemnite_journaliere', 'valeur' => '300 000', 'date_effet' => '2026-11-01', 'commentaire' => 'Note de la direction',
        ])->assertSessionHasErrors(['valeur' => 'Saisissez un montant entier.']);
        $this->actingAs($this->gestionnaire)->post(route('parametrage.baremes-odm.store'), [
            'cle' => 'indemnite_journaliere', 'valeur' => '300000', 'date_effet' => '2026-11-01', 'commentaire' => 'Note de la direction',
        ])->assertSessionHas('success', 'Barème enregistré : « Indemnité journalière, ODM intérieur (GNF par jour) » = 300 000, à partir du 01/11/2026.');
        $this->assertSame(0, ModificationEnAttente::count());

        $this->assertSame(250000, CalculOdm::baremesEnVigueur()['indemnite_journaliere']);
        $this->assertSame(300000, CalculOdm::baremesEnVigueur(Carbon::parse('2026-11-01'))['indemnite_journaliere']);

        $this->actingAs($this->gestionnaire)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('baremesOdm.enVigueur.indemnite_journaliere.valeur', 250000)
            ->where('baremesOdm.enVigueur.indemnite_journaliere.prochaine', ['valeur' => '300000', 'date' => '01/11/2026'])
            ->where('baremesOdm.historique.0.a_venir', true)
            ->where('baremesOdm.historique.0.auteur', 'Maïmouna BARRY'));

        /* Un ODM soumis avant la date garde l'ancien barème ; un ODM soumis après prend le nouveau */
        [$demandeur, $participant] = $this->preparerOdm();
        $avant = $this->odmSoumis($demandeur, $participant, '2026-11-10');
        $this->assertSame(250000, $avant->parametres_figes['baremes']['indemnite_journaliere']);

        Carbon::setTestNow('2026-11-02 09:00');
        $apres = $this->odmSoumis($demandeur, $participant, '2026-11-20');
        $this->assertSame(300000, $apres->parametres_figes['baremes']['indemnite_journaliere']);
        $this->assertSame(250000, $avant->fresh()->parametres_figes['baremes']['indemnite_journaliere']);
    }

    public function test_date_passee_libelle_et_retrait_d_un_bareme_a_venir(): void
    {
        $this->actingAs($this->gestionnaire)->post(route('parametrage.baremes-odm.store'), ['cle' => 'hebergement_nuit', 'valeur' => '450000', 'date_effet' => '2026-10-01'])
            ->assertSessionHasErrors(['date_effet' => "La date d'effet ne peut pas être passée : un barème ne réécrit pas le passé."]);

        $this->actingAs($this->gestionnaire)->post(route('parametrage.baremes-odm.store'), ['cle' => 'libelle_indemnite_1', 'valeur' => 'Indemnité de restauration', 'date_effet' => '2026-10-20'])
            ->assertSessionHas('success');
        $this->assertSame('Indemnité de restauration', BaremeOdm::enVigueur('libelle_indemnite_1'));

        $enVigueur = BaremeOdm::where('cle', 'libelle_indemnite_1')->latest('id')->first();
        $this->actingAs($this->gestionnaire)->delete(route('parametrage.baremes-odm.destroy', $enVigueur))->assertSessionHas('error');
        $this->assertTrue($enVigueur->exists());

        $this->actingAs($this->gestionnaire)->post(route('parametrage.baremes-odm.store'), ['cle' => 'hebergement_nuit', 'valeur' => '450000', 'date_effet' => '2026-12-01']);
        $aVenir = BaremeOdm::where('cle', 'hebergement_nuit')->latest('id')->first();
        $this->actingAs($this->gestionnaire)->delete(route('parametrage.baremes-odm.destroy', $aVenir))->assertSessionHas('success', 'Barème à venir retiré.');
        $this->assertNull($aVenir->fresh());
    }

    /** RG-M01-08 : un barème propre à une entité l'emporte sur le barème commun */
    public function test_un_bareme_d_entite_l_emporte_sur_le_commun(): void
    {
        $mining = Entite::where('code', 'NMI')->value('id');
        BaremeOdm::create(['entite_id' => $mining, 'cle' => 'hebergement_nuit', 'valeur' => '600000', 'date_effet' => '2026-10-01']);

        $this->assertSame(600000, BaremeOdm::enVigueur('hebergement_nuit', null, $mining));
        $this->assertSame(500000, BaremeOdm::enVigueur('hebergement_nuit', null, Entite::idParDefaut()));
    }

    /** §6.5, RG-M03-13 : type d'OR ajouté, proposé à la saisie de l'ODM ; désactivé, il est refusé mais les OR saisis le gardent */
    public function test_les_types_d_or_viennent_du_referentiel(): void
    {
        $this->assertSame(['vente' => 'Vente', 'garantie' => 'Garantie'], TypeOr::options());

        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.store'), ['code' => 'Interne!', 'libelle' => 'Interne'])
            ->assertSessionHasErrors('code');
        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.store'), ['code' => 'interne', 'libelle' => 'Interne'])->assertSessionHas('success');
        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.store'), ['code' => 'interne', 'libelle' => 'Doublon'])->assertSessionHasErrors('code');

        [$demandeur, $participant] = $this->preparerOdm();
        $odm = $this->odmCree($demandeur, $participant, [['numero' => '11022219', 'type' => 'interne']]);
        $this->assertSame('interne', $odm->ordresReparation()->value('type'));
        $this->actingAs($demandeur)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('odm.ordres_reparation.0.type_label', 'Interne'));
        $this->actingAs($demandeur)->get(route('odm.edit', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('typesOr', ['vente' => 'Vente', 'garantie' => 'Garantie', 'interne' => 'Interne']));

        $interne = TypeOr::where('code', 'interne')->sole();
        $this->actingAs($this->gestionnaire)->put(route('parametrage.types-or.update', $interne), ['code' => 'autre', 'libelle' => 'Interne Neemba'])->assertSessionHas('success');
        $this->assertSame(['interne', 'Interne Neemba'], [$interne->fresh()->code, $interne->fresh()->libelle]);
        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.toggle', $interne))->assertSessionHas('success');

        $this->actingAs($demandeur)->putJson(route('api.odm.enregistrer', $odm), ['ordres_reparation' => [['numero' => '11022220', 'type' => 'interne']]])
            ->assertStatus(422);
        $this->assertSame('Interne Neemba', TypeOr::libelle('interne'));
        $this->assertSame('11022219 (Interne Neemba), 11022221', TypeOr::decrire(['11022219', '11022221'], ['11022219' => 'interne']));
    }

    public function test_au_moins_un_type_d_or_reste_actif(): void
    {
        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.toggle', TypeOr::where('code', 'garantie')->sole()))->assertSessionHas('success');
        $this->actingAs($this->gestionnaire)->post(route('parametrage.types-or.toggle', TypeOr::where('code', 'vente')->sole()))
            ->assertSessionHas('error', "Au moins un type d'OR doit rester actif.");
        $this->assertSame(['vente'], TypeOr::codesActifs());
    }

    /* ------------------------------------------------------------------ */

    private function preparerOdm(): array
    {
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);

        return [
            User::factory()->create(['service' => 'Technique']),
            User::factory()->create(['service' => 'Technique', 'statut_cadre' => 'non_cadre']),
        ];
    }

    private function odmCree(User $demandeur, User $participant, array $ors = [], string $depart = '2026-11-10'): OrdreMission
    {
        $reponse = $this->actingAs($demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => $depart, 'date_retour_prevue' => Carbon::parse($depart)->addDays(3)->toDateString(),
            'prise_en_charge' => 'neemba', 'participants' => [['user_id' => $participant->id]], 'ordres_reparation' => $ors,
        ])->assertCreated();

        return OrdreMission::findOrFail($reponse->json('odm.id'));
    }

    private function odmSoumis(User $demandeur, User $participant, string $depart): OrdreMission
    {
        $odm = $this->odmCree($demandeur, $participant, [], $depart);
        $this->actingAs($demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();

        return $odm->fresh();
    }
}
