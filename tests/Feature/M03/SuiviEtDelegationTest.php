<?php

namespace Tests\Feature\M03;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Delegation;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Models\Validation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Module M03 — délégation d'initiation, liste et fiche du bon (US-BC-13, US-BC-14, US-BC-16, RG-BC-29, RG-BC-32, RG-BC-33).
 */
class SuiviEtDelegationTest extends TestCase
{
    use RefreshDatabase;

    private User $mamadou;      // titulaire, en congé
    private User $backup;       // back-up désigné
    private User $chefTechnique;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $conakry = Site::factory()->conakry()->create();
        Site::factory()->create(['nom' => 'Boke', 'code' => '31']);
        Caisse::factory()->create(['code' => 'CKY-ESP', 'site_id' => $conakry->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $technique = Service::factory()->create(['nom' => 'Technique']);
        Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'MPRZZZ', 'service_id' => $technique->id]);

        $this->mamadou = User::factory()->create(['name' => 'BAH', 'prenom' => 'Mamadou', 'site' => 'Boke', 'service' => 'Technique']);
        $this->backup = User::factory()->create(['name' => 'BARRY', 'prenom' => 'Souadou', 'site' => 'Conakry', 'service' => 'Aftermarket']);
        $this->chefTechnique = User::factory()->role('responsable_service')->create(['name' => 'BANGOURA', 'prenom' => 'Thomas', 'service' => 'Technique']);
        User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);
        User::factory()->role('controle_gestion')->create();
        User::factory()->role('daf')->create();
    }

    /* ------------------------------------------------------------------
     * US-BC-13 — pour le compte d'un collègue
     * ------------------------------------------------------------------ */

    /** TC-BC-027 */
    public function test_le_backup_cree_un_bon_pour_le_compte_du_titulaire(): void
    {
        $this->delegation();

        $this->actingAs($this->backup)->get(route('bons-caisse.create'))
            ->assertInertia(fn (Assert $page) => $page->where('titulaires.0.nom_complet', 'Mamadou BAH'));

        $bon = $this->brouillon(['demandeur_id' => $this->mamadou->id]);

        $this->assertSame($this->mamadou->id, $bon->demandeur_id);
        $this->assertSame($this->backup->id, $bon->initiateur_id);
        $this->assertSame('Boke', $bon->site);              // valeurs par défaut du titulaire
        $this->assertSame('Technique', $bon->service);
        $this->assertSame($this->mamadou->id, $bon->beneficiaire_id);

        $this->actingAs($this->backup)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('bonCaisse.initiateur.name', 'BARRY'));
    }

    public function test_a_la_soumission_le_chef_de_service_et_le_titulaire_sont_prevenus(): void
    {
        $this->delegation();
        $bon = $this->bonComplet(['demandeur_id' => $this->mamadou->id]);

        $this->actingAs($this->backup)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-d1'])->assertOk();

        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->chefTechnique->id, 'bon_caisse_id' => $bon->id, 'type' => 'soumission']);
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->mamadou->id, 'bon_caisse_id' => $bon->id, 'titre' => 'Bon soumis pour votre compte']);
    }

    /** TC-BC-028 : la délégation expire pendant la saisie → MSG-BC-033, le bon reste en brouillon */
    public function test_une_delegation_expiree_bloque_la_soumission(): void
    {
        $delegation = $this->delegation();
        $bon = $this->bonComplet(['demandeur_id' => $this->mamadou->id]);
        $delegation->update(['statut' => 'terminee']);

        $this->actingAs($this->backup)->getJson(route('api.bons.controles', $bon))
            ->assertJsonPath('controles.11.niveau', 'bloquant')
            ->assertJsonPath('controles.11.message', "Votre délégation pour Mamadou BAH n'est plus active. Le bon reste en brouillon.");

        $this->actingAs($this->backup)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-d2'])
            ->assertStatus(422)
            ->assertJsonPath('message_cle', 'MSG-BC-033')
            ->assertJsonPath('regle', 'RG-BC-29');
        $this->assertSame('BROUILLON', $bon->fresh()->statut);
        $this->assertNull($bon->fresh()->numero);
    }

    public function test_sans_delegation_on_ne_cree_pas_de_bon_pour_un_collegue(): void
    {
        $this->actingAs($this->backup)->postJson(route('api.bons.creer'), ['type_bon' => 'BD', 'demandeur_id' => $this->mamadou->id])
            ->assertStatus(422)
            ->assertJsonPath('message_cle', 'MSG-BC-033')
            ->assertJsonPath('champ', 'demandeur_id');
    }

    /** Une délégation d'initiation suffit pour ouvrir l'assistant, mais pas pour un bon à son propre nom */
    public function test_un_backup_sans_role_demandeur_cree_seulement_pour_le_titulaire(): void
    {
        $caissier = $this->utilisateurAvecRoles(['caissier']);
        $this->delegation($caissier);

        $this->assertTrue($caissier->peutInitierBon());
        $this->actingAs($caissier)->postJson(route('api.bons.creer'), ['type_bon' => 'BD'])
            ->assertStatus(422)
            ->assertJsonPath('champ', 'demandeur_id');
        $this->actingAs($caissier)->postJson(route('api.bons.creer'), ['type_bon' => 'BD', 'demandeur_id' => $this->mamadou->id])
            ->assertCreated();
    }

    /** Une délégation d'initiation ne donne pas les droits de validation du titulaire */
    public function test_une_delegation_d_initiation_ne_donne_pas_la_validation(): void
    {
        $chefAftermarket = User::where('role', 'responsable_service')->where('service', 'Aftermarket')->first();
        $this->delegation($chefAftermarket, $this->chefTechnique, ['initiation']);

        $this->assertNotContains('responsable_service', array_diff($chefAftermarket->rolesValidationEffectifs(), $chefAftermarket->listeRoles()));
        $this->assertTrue(Delegation::delegantsActifsPour($chefAftermarket->id)->isEmpty());
        $this->assertTrue(Delegation::initiationsActivesPour($chefAftermarket->id)->isNotEmpty());
    }

    public function test_par_defaut_une_delegation_ne_comprend_pas_l_initiation(): void
    {
        $this->actingAs($this->chefTechnique)->post(route('delegations.store'), [
            'delegue_id' => $this->backup->id, 'date_debut' => today()->toDateString(), 'date_fin' => today()->addDays(5)->toDateString(),
        ])->assertRedirect();

        $this->assertSame(['validation', 'archivage'], Delegation::firstOrFail()->fonctionnalites);
    }

    /** E-03.8 : un suppléant valide « au titre de » son titulaire */
    public function test_un_suppleant_valide_au_titre_du_titulaire(): void
    {
        $cdg = User::where('role', 'controle_gestion')->first();
        $this->delegation($this->backup, $cdg, ['validation']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->mamadou->id]);
        $validation = Validation::create(['bon_caisse_id' => $bon->id, 'niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente', 'date_attribution' => now()]);

        $validation->approuver($this->backup, 'Conforme');

        $this->assertSame($cdg->id, $validation->fresh()->au_titre_de_id);
    }

    /* ------------------------------------------------------------------
     * US-BC-16 — liste
     * ------------------------------------------------------------------ */

    /** RG-BC-32 : demandeur, initiateur, bénéficiaire (dès la soumission) */
    public function test_la_liste_montre_les_bons_du_demandeur_de_l_initiateur_et_du_beneficiaire(): void
    {
        $initie = BonCaisse::factory()->statut('BROUILLON')->create(['demandeur_id' => $this->mamadou->id, 'initiateur_id' => $this->backup->id]);
        $beneficiaire = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->mamadou->id, 'beneficiaire_id' => $this->backup->id]);
        $brouillonBeneficiaire = BonCaisse::factory()->statut('BROUILLON')->create(['demandeur_id' => $this->mamadou->id, 'beneficiaire_id' => $this->backup->id]);

        $ids = collect($this->liste()->viewData('page')['props']['bonsCaisse']['data'])->pluck('id');

        $this->assertTrue($ids->contains($initie->id));
        $this->assertTrue($ids->contains($beneficiaire->id));
        $this->assertFalse($ids->contains($brouillonBeneficiaire->id));
        $this->actingAs($this->backup)->get(route('bons-caisse.show', $beneficiaire))->assertOk();
    }

    /** TC-BC-031 (ANO-08) : la carte « Rejetés » compte les lignes affichées ; montant total rejeté */
    public function test_les_cartes_comptent_les_lignes_du_filtre(): void
    {
        BonCaisse::factory()->count(3)->statut('REJETE')->create(['demandeur_id' => $this->backup->id, 'montant' => 100000]);
        BonCaisse::factory()->count(2)->statut('PAYE')->create(['demandeur_id' => $this->backup->id, 'montant' => 50000, 'date_paiement' => now()]);
        BonCaisse::factory()->statut('ARCHIVE')->create(['demandeur_id' => $this->backup->id, 'commentaire_rejet' => 'Pièce manquante', 'date_paiement' => null]);

        $this->liste()->assertInertia(fn (Assert $page) => $page
            ->where('statsIndex.total.nombre', 6)
            ->where('statsIndex.payes.nombre', 2)
            ->where('statsIndex.rejetes.nombre', 4));      // le rejeté archivé n'est plus compté comme payé

        $this->liste(['statut' => 'REJETE'])->assertInertia(fn (Assert $page) => $page
            ->has('bonsCaisse.data', 3)
            ->where('statsIndex.rejetes.nombre', 3)
            ->where('statsIndex.rejetes.montant', 300000)
            ->where('statsIndex.total.nombre', 3));
    }

    public function test_filtres_urgence_et_periode_et_20_lignes_par_page(): void
    {
        BonCaisse::factory()->count(22)->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->backup->id, 'date_demande' => '2026-09-15', 'niveau_urgence' => 'normale']);
        BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->backup->id, 'date_demande' => '2026-10-02', 'niveau_urgence' => 'tres_urgente']);

        $this->liste()->assertInertia(fn (Assert $page) => $page->has('bonsCaisse.data', 20)->where('bonsCaisse.total', 23));
        $this->liste(['niveau_urgence' => 'tres_urgente'])->assertInertia(fn (Assert $page) => $page->where('bonsCaisse.total', 1));
        $this->liste(['du' => '2026-10-01', 'au' => '2026-10-31'])->assertInertia(fn (Assert $page) => $page->where('bonsCaisse.total', 1));
    }

    /** Âge depuis la soumission, en rouge au-delà de 2 × le délai de l'étape en cours */
    public function test_l_age_est_en_alerte_au_dela_de_deux_fois_le_delai(): void
    {
        $ancien = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->backup->id, 'date_soumission' => now()->subDays(2)]);
        Validation::create(['bon_caisse_id' => $ancien->id, 'niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente', 'date_attribution' => now()->subDays(2)]);
        $recent = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $this->backup->id, 'date_soumission' => now()->subMinutes(30)]);
        Validation::create(['bon_caisse_id' => $recent->id, 'niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente', 'date_attribution' => now()->subMinutes(30)]);

        $lignes = collect($this->liste()->viewData('page')['props']['bonsCaisse']['data'])->keyBy('id');

        $this->assertTrue($lignes[$ancien->id]['age_alerte']);
        $this->assertSame("2\u{00A0}j", $lignes[$ancien->id]['age']);   // espace insécable (§1.4)
        $this->assertFalse($lignes[$recent->id]['age_alerte']);
    }

    /* ------------------------------------------------------------------
     * E-03.8 — fiche
     * ------------------------------------------------------------------ */

    /** US-BC-14 : bandeau de rejet (motif, commentaire, valideur, date) */
    public function test_la_fiche_d_un_bon_rejete_affiche_le_bandeau(): void
    {
        $cdg = User::where('role', 'controle_gestion')->first();
        $bon = BonCaisse::factory()->statut('REJETE')->create(['demandeur_id' => $this->backup->id]);
        Validation::create(['bon_caisse_id' => $bon->id, 'niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'rejete',
            'validateur_id' => $cdg->id, 'date_validation' => '2026-10-06 10:15:00', 'commentaire' => 'Pièce justificative manquante - Joindre la facture']);

        $this->actingAs($this->backup)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rejet.motif', 'Pièce justificative manquante')
                ->where('rejet.commentaire', 'Joindre la facture')
                ->where('rejet.niveau', 'Contrôle de gestion')
                ->where('rejet.valideur', $cdg->nom_complet)
                ->where('rejet.date', '06/10/2026 10:15'));
    }

    /** US-BC-16 : fait (nom, date, durée), en cours (valideurs possibles, échéance), à venir */
    public function test_l_onglet_validations_detaille_chaque_niveau(): void
    {
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create(['demandeur_id' => $this->backup->id, 'service' => 'Technique']);
        Validation::create(['bon_caisse_id' => $bon->id, 'niveau' => 1, 'role' => 'responsable_service', 'statut' => 'en_attente', 'date_attribution' => now()->subHour()]);
        Validation::create(['bon_caisse_id' => $bon->id, 'niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente']);

        $this->actingAs($this->backup)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('etapesValidation.0.etat', 'en_cours')
                ->where('etapesValidation.0.valideurs_possibles', ['Thomas BANGOURA'])
                ->where('etapesValidation.0.echeance', fn ($echeance) => $echeance !== null)
                ->where('etapesValidation.1.etat', 'a_venir'));
    }

    /* ------------------------------------------------------------------
     * Outils
     * ------------------------------------------------------------------ */

    private function delegation(?User $delegue = null, ?User $titulaire = null, array $fonctionnalites = ['initiation']): Delegation
    {
        return Delegation::create([
            'delegant_id' => ($titulaire ?? $this->mamadou)->id,
            'delegue_id' => ($delegue ?? $this->backup)->id,
            'date_debut' => today()->subDays(2),
            'date_fin' => today()->addDays(9),
            'fonctionnalites' => $fonctionnalites,
            'statut' => 'acceptee',
            'acceptee_le' => now(),
        ]);
    }

    private function brouillon(array $valeurs = []): BonCaisse
    {
        $id = $this->actingAs($this->backup)->postJson(route('api.bons.creer'), $valeurs + ['type_bon' => 'BD', 'code_analytique' => 'MPRZZZ'])
            ->assertCreated()->json('bon.id');

        return BonCaisse::findOrFail($id);
    }

    private function bonComplet(array $valeurs = []): BonCaisse
    {
        $bon = $this->brouillon($valeurs + ['site' => 'Conakry']);
        $this->actingAs($this->backup)->patchJson(route('api.bons.enregistrer', $bon), [
            'motif' => 'Achat de pièces pour la machine du client', 'categorie_depense' => 'fournitures',
            'montant' => 450000, 'mode_paiement' => 'especes',
        ])->assertOk();
        $this->actingAs($this->backup)->postJson(route('api.bons.pieces.ajouter', $bon), [
            'fichier' => UploadedFile::fake()->image('facture.jpg', 4000, 3000), 'type_document' => 'facture',
        ])->assertCreated();

        return $bon->fresh();
    }

    private function liste(array $filtres = [])
    {
        return $this->actingAs($this->backup)->get(route('bons-caisse.index', $filtres))->assertOk();
    }
}
