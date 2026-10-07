<?php

namespace Tests\Feature\M03;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Module M03 — saisie du bon de caisse : règles serveur et API de l'assistant (SFD §5.5, cas de test §5.8).
 */
class SaisieBonTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $souadou;
    private Caisse $especesConakry;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $conakry = Site::factory()->conakry()->create();
        $boke = Site::factory()->create(['nom' => 'Boke']);
        $this->especesConakry = Caisse::factory()->create([
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id,
            'plafond_retrait' => 20000000, 'solde' => 30000000,
        ]);
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id]);
        Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse principale Boke', 'site_id' => $boke->id, 'plafond_retrait' => 1000000, 'solde' => 5000000]);

        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        Service::factory()->create(['nom' => 'Location']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);
        CodeAnalytique::factory()->create(['code' => 'LOCZZZ', 'libelle' => 'Location']);

        $this->souadou = User::factory()->create([
            'prenom' => 'Souadou', 'name' => 'BARRY', 'matricule' => '20412',
            'site' => 'Conakry', 'service' => 'Aftermarket', 'telephone' => '622 46 12 61',
        ]);
        User::factory()->role('responsable_service')->create(['prenom' => 'Lamine', 'name' => 'SOUMAH', 'service' => 'Aftermarket']);
        User::factory()->role('controle_gestion')->create(['prenom' => 'Saliou', 'name' => 'BOIRO']);
        User::factory()->role('daf')->create();
    }

    /* ------------------------------------------------------------------
     * US-BC-01 — ouvrir un nouveau bon
     * ------------------------------------------------------------------ */

    /** TC-BC-001, TC-BC-002 */
    public function test_ouvrir_l_assistant_ne_cree_aucun_bon_et_propose_les_valeurs_par_defaut(): void
    {
        $this->actingAs($this->souadou)->get(route('bons-caisse.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('BonsCaisse/Assistant')
                ->where('bon', null)
                ->where('demandeur.nom_complet', 'Souadou BARRY')
                ->where('demandeur.site', 'Conakry')
                ->where('demandeur.service', 'Aftermarket'));

        $this->assertSame(0, BonCaisse::count());
    }

    /** RG-BC-01, RG-BC-02, ANO-03 */
    public function test_le_premier_suivant_cree_un_brouillon_sans_numero_avec_les_valeurs_par_defaut(): void
    {
        $reponse = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), [
            'type_bon' => 'BD', 'code_analytique' => 'ADAZZZ', 'etape' => 1,
        ])->assertCreated()->assertJsonPath('erreurs', []);

        $bon = BonCaisse::findOrFail($reponse->json('bon.id'));
        $this->assertSame('BROUILLON', $bon->statut);
        $this->assertNull($bon->numero);
        $this->assertSame('Conakry', $bon->site);
        $this->assertSame('Aftermarket', $bon->service);
        $this->assertSame('normale', $bon->niveau_urgence);
        $this->assertSame('employe', $bon->type_beneficiaire);
        $this->assertSame($this->souadou->id, $bon->beneficiaire_id);
        $this->assertSame('especes', $bon->mode_paiement);
        $this->assertTrue($bon->caisse->is($this->especesConakry));
    }

    /** TC-BC-032 */
    public function test_un_caissier_sans_role_demandeur_ne_peut_pas_creer_de_bon_par_l_api(): void
    {
        $this->actingAs($this->utilisateurAvecRoles(['caissier']))
            ->postJson(route('api.bons.creer'), ['type_bon' => 'BD'])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------
     * US-BC-02, US-BC-03 — identification et urgence
     * ------------------------------------------------------------------ */

    /** TC-BC-003 (ANO-04) */
    public function test_le_code_analytique_est_obligatoire(): void
    {
        $this->etape(1, ['type_bon' => 'BD', 'code_analytique' => null])
            ->assertJsonPath('erreurs.code_analytique.0', 'Ce champ est obligatoire.');
    }

    /** RG-BC-04 */
    public function test_le_code_analytique_doit_etre_rattache_au_service(): void
    {
        $this->etape(1, ['type_bon' => 'BD', 'code_analytique' => 'LOCZZZ'])
            ->assertJsonPath('erreurs.code_analytique.0', "Ce code analytique n'est pas rattaché au service Aftermarket.");
    }

    /** TC-BC-004 */
    public function test_une_urgence_exige_une_justification_d_au_moins_10_caracteres(): void
    {
        $this->etape(1, ['type_bon' => 'BD', 'code_analytique' => 'ADAZZZ', 'niveau_urgence' => 'urgente', 'motif_urgence' => 'Panne', 'justification_urgence' => 'vite'])
            ->assertJsonPath('erreurs.justification_urgence.0', 'La justification doit comporter au moins 10 caractères.');
    }

    public function test_revenir_a_normale_efface_motif_et_justification(): void
    {
        $bon = $this->brouillon(['niveau_urgence' => 'tres_urgente', 'motif_urgence' => 'Panne', 'justification_urgence' => 'Machine arrêtée chez le client']);

        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['niveau_urgence' => 'normale'])->assertOk();

        $this->assertNull($bon->fresh()->motif_urgence);
        $this->assertNull($bon->fresh()->justification_urgence);
    }

    /* ------------------------------------------------------------------
     * US-BC-04 — bénéficiaire
     * ------------------------------------------------------------------ */

    /** TC-BC-005 */
    public function test_un_beneficiaire_employe_est_repris_du_referentiel(): void
    {
        $bon = $this->brouillon(['beneficiaire' => 'Saoudou BARRY', 'telephone_beneficiaire' => '611111111']);

        $this->assertSame('Souadou BARRY', $bon->beneficiaire);
        $this->assertSame('+224622461261', $bon->telephone_beneficiaire);
    }

    public function test_la_recherche_de_beneficiaires_porte_sur_le_nom_et_le_matricule(): void
    {
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.beneficiaires', ['q' => 'bar']))
            ->assertJsonPath('resultats.0.libelle', 'BARRY Souadou — 20412 — Aftermarket');
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.beneficiaires', ['q' => '204']))
            ->assertJsonCount(1, 'resultats');
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.beneficiaires', ['q' => 'b']))
            ->assertJsonCount(0, 'resultats');
    }

    public function test_un_tiers_a_un_nom_libre_de_3_a_120_caracteres(): void
    {
        $bon = $this->brouillon();
        $this->etape(2, ['type_beneficiaire' => 'fournisseur', 'beneficiaire' => 'GK'], $bon)
            ->assertJsonPath('erreurs.beneficiaire.0', 'Le nom doit comporter entre 3 et 120 caractères.');
    }

    /** TC-BC-006 */
    public function test_un_tiers_paye_en_especes_sans_telephone_bloque_la_soumission(): void
    {
        $bon = $this->bonComplet(['type_beneficiaire' => 'fournisseur', 'beneficiaire' => 'GARAGE KABA', 'telephone_beneficiaire' => null]);

        $controle = collect($this->controles($bon))->firstWhere('numero', 8);
        $this->assertSame('bloquant', $controle['niveau']);
        $this->assertSame('MSG-BC-006', $controle['message_cle']);
    }

    /* ------------------------------------------------------------------
     * US-BC-05, US-BC-06, US-BC-07 — dépense, mode de paiement, véhicule / OR / mission
     * ------------------------------------------------------------------ */

    public function test_le_motif_fait_au_moins_10_caracteres_et_le_montant_est_un_entier_positif(): void
    {
        $bon = $this->brouillon();
        $this->etape(3, $this->depense(['motif' => 'Achat']), $bon)
            ->assertJsonPath('erreurs.motif.0', 'Le motif doit comporter au moins 10 caractères.');

        foreach (['abc', 0, '12,5'] as $montant) {
            $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['montant' => $montant])
                ->assertStatus(422)
                ->assertJsonPath('errors.montant.0', 'Saisissez un montant entier supérieur à 0.');
        }
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['montant' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.montant.0', 'Saisissez un montant entier supérieur à 0.');
    }

    /** TC-BC-008, RG-BC-09 : au-delà du seuil (strict) */
    public function test_le_visa_du_directeur_pays_s_ajoute_au_dela_du_seuil(): void
    {
        DB::table('parametres')->where('cle', 'seuil_validation_dp')->update(['valeur' => '1500000']);
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertFalse((new BonCaisse(['montant' => 1500000]))->necessite_validation_dp);
        $this->assertTrue((new BonCaisse(['montant' => 1500001]))->necessite_validation_dp);
    }

    /** TC-BC-009 : Conakry, 23 500 000, espèces refusé ; virement accepté */
    public function test_au_dela_du_plafond_de_retrait_les_especes_sont_refusees(): void
    {
        $bon = $this->brouillon();
        $this->etape(3, $this->depense(['montant' => 23500000, 'mode_paiement' => 'especes']), $bon)
            ->assertJsonPath('erreurs.mode_paiement.0', 'Au-delà de 20' . self::NBSP . '000' . self::NBSP . '000 GNF, ce bon ne peut pas être payé '
                . 'en espèces sur la caisse principale Conakry. Choisissez Orange Money, chèque ou virement.');

        $this->etape(3, $this->depense(['montant' => 23500000, 'mode_paiement' => 'virement']), $bon)
            ->assertJsonPath('erreurs', []);
        $this->assertNull($bon->fresh()->caisse_id);   // virement : sans objet (paiement hors caisse)
    }

    /** TC-BC-010 : Boké, plafond 1 000 000 */
    public function test_le_plafond_depend_de_la_caisse_du_site(): void
    {
        $bon = $this->brouillon(['site' => 'Boke']);
        $this->etape(3, $this->depense(['montant' => 1200000, 'mode_paiement' => 'especes']), $bon)
            ->assertJsonPath('erreurs.mode_paiement.0', fn ($message) => str_contains($message, 'caisse principale Boke'));
    }

    /** TC-BC-011 */
    public function test_orange_money_est_paye_par_la_caisse_om_de_conakry(): void
    {
        $bon = $this->brouillon(['site' => 'Boke']);
        $this->etape(3, $this->depense(['mode_paiement' => 'orange_money']), $bon);

        $this->assertSame('CKY-OM', $bon->fresh()->caisse->code);
    }

    /** TC-BC-012, TC-BC-013 */
    public function test_carburant_exige_le_vehicule_et_un_or_fait_8_chiffres(): void
    {
        $bon = $this->brouillon();
        $this->etape(3, $this->depense(['categorie_depense' => 'carburant', 'vehicule' => null]), $bon)
            ->assertJsonPath('erreurs.vehicule.0', "Indiquez l'immatriculation du véhicule ou le numéro du matériel.");

        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['references_or' => ['1102221']])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn ($erreurs) => ($erreurs['references_or.0'][0] ?? null) === 'Un numéro d\'OR comporte 8 chiffres (ex. 11022219).');

        $this->etape(3, $this->depense(['categorie_depense' => 'carburant', 'vehicule' => 'be 3424', 'references_or' => ['11022204', '11022205']]), $bon)
            ->assertJsonPath('erreurs', []);
        $this->assertSame('BE 3424', $bon->fresh()->vehicule);
    }

    /** TC-BC-014 */
    public function test_un_bp_lie_a_une_mission_exige_la_date_de_retour(): void
    {
        $bon = $this->brouillon(['type_bon' => 'BP']);
        $this->etape(3, $this->depense(['lie_mission' => true, 'date_retour_mission' => null]), $bon)
            ->assertJsonPath('erreurs.date_retour_mission.0', "Pour un bon provisoire lié à une mission, choisissez l'ordre de mission ou indiquez la date de retour.");
    }

    /* ------------------------------------------------------------------
     * US-BC-08 — pièces
     * ------------------------------------------------------------------ */

    /** TC-BC-015 */
    public function test_un_bd_avec_seulement_une_demande_d_achat_n_a_pas_de_justificatif(): void
    {
        $bon = $this->bonComplet([], false);
        $this->piece($bon, 'demande_achat');

        $this->etape(4, [], $bon)->assertJsonPath('erreurs.pieces.0', __('MSG-BC-017'));
    }

    public function test_chaque_piece_doit_avoir_un_type(): void
    {
        $bon = $this->bonComplet([], false);
        $this->piece($bon, null);

        $this->etape(4, [], $bon)->assertJsonPath('erreurs.pieces.0', 'Indiquez le type de chaque pièce.');
    }

    public function test_une_piece_d_un_format_non_accepte_est_refusee(): void
    {
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)
            ->postJson(route('api.bons.pieces.ajouter', $bon), ['fichier' => UploadedFile::fake()->create('note.docx', 20, 'application/msword')])
            ->assertStatus(422)
            ->assertJsonPath('errors.fichier.0', __('MSG-BC-021'));
    }

    /* ------------------------------------------------------------------
     * US-BC-11 — brouillon
     * ------------------------------------------------------------------ */

    /** RG-BC-25 : un brouillon ne contrôle que les formats */
    public function test_un_brouillon_accepte_une_saisie_incomplete(): void
    {
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['motif' => 'Achat'])
            ->assertOk()->assertJsonPath('erreurs', []);
        $this->assertSame('Achat', $bon->fresh()->motif);
    }

    /** TC-BC-024 */
    public function test_la_reprise_ouvre_la_premiere_etape_incomplete(): void
    {
        $bon = $this->brouillon(['motif' => null]);

        $this->actingAs($this->souadou)->get(route('bons-caisse.edit', $bon))
            ->assertInertia(fn (Assert $page) => $page->component('BonsCaisse/Assistant')->where('etapeInitiale', 3));
    }

    /* ------------------------------------------------------------------
     * US-BC-10, US-BC-12 — contrôle et soumission
     * ------------------------------------------------------------------ */

    public function test_un_bon_conforme_peut_etre_soumis_et_recoit_son_numero(): void
    {
        $bon = $this->bonComplet();

        $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))
            ->assertJsonPath('soumission_possible', true)
            ->assertJsonPath('circuit.0.valideurs.0', 'Lamine SOUMAH');

        $this->actingAs($this->souadou)
            ->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-1'])
            ->assertOk()
            ->assertJsonPath('numero', 'BC-' . now()->year . '-0001')
            ->assertJsonPath('statut', 'EN_ATTENTE_CHEF_SERVICE')
            ->assertJsonPath('caisse.code', 'CKY-ESP')
            ->assertSessionHas('success', 'Bon BC-' . now()->year . '-0001 soumis. Il est en attente de validation par le chef de service.');

        $bon->refresh();
        $this->assertNotNull($bon->date_soumission);
        $this->assertSame(3, $bon->validations()->count());
        $this->assertTrue(HistoriqueAction::where('bon_caisse_id', $bon->id)->where('action', 'soumission')->exists());
    }

    /** TC-BC-025 : double clic → un seul numéro */
    public function test_un_double_clic_ne_consomme_qu_un_numero(): void
    {
        $bon = $this->bonComplet();

        $premier = $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-unique']);
        $second = $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-unique']);

        $premier->assertOk();
        $second->assertOk()->assertJsonPath('numero', $premier->json('numero'));
        $this->assertSame(1, (int) DB::table('compteurs_numerotation')->value('dernier_numero'));
        $this->assertSame(3, $bon->validations()->count());
    }

    /** TC-BC-026 : plafond abaissé entre l'étape 5 et la soumission → refus, aucun numéro consommé */
    public function test_un_controle_serveur_en_echec_ne_consomme_pas_de_numero(): void
    {
        $bon = $this->bonComplet(['montant' => 15000000]);
        $this->especesConakry->update(['plafond_retrait' => 10000000]);

        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-2'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PLAFOND_RETRAIT_DEPASSE')
            ->assertJsonPath('regle', 'RG-BC-11')
            ->assertJsonPath('message_cle', 'MSG-BC-012');

        $this->assertNull($bon->fresh()->numero);
        $this->assertSame('BROUILLON', $bon->fresh()->statut);
        $this->assertSame(0, (int) DB::table('compteurs_numerotation')->value('dernier_numero'));
    }

    public function test_la_numerotation_ne_redescend_pas_sous_un_numero_existant(): void
    {
        BonCaisse::factory()->statut('PAYE')->create(['numero' => 'BC-' . now()->year . '-0010', 'demandeur_id' => $this->souadou->id]);
        $bon = $this->bonComplet();

        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-3'])
            ->assertJsonPath('numero', 'BC-' . now()->year . '-0011');
    }

    /* ------------------------------------------------------------------
     * US-BC-15 — annulation
     * ------------------------------------------------------------------ */

    public function test_un_brouillon_peut_etre_annule_avec_un_motif(): void
    {
        $bon = $this->brouillon();

        $this->actingAs($this->souadou)->postJson(route('api.bons.annuler', $bon), ['motif' => 'court'])
            ->assertStatus(422);
        $this->actingAs($this->souadou)->postJson(route('api.bons.annuler', $bon), ['motif' => 'Dépense finalement prise en charge par le client'])
            ->assertOk();

        $this->assertSame('ANNULE', $bon->fresh()->statut);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['motif' => 'Nouvelle tentative de saisie'])
            ->assertForbidden();
    }

    /** TC-BC-030 */
    public function test_un_bon_en_validation_ne_peut_pas_etre_annule(): void
    {
        $bon = $this->bonComplet();
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-4'])->assertOk();

        $this->actingAs($this->souadou)->postJson(route('api.bons.annuler', $bon), ['motif' => 'Je ne veux plus de ce bon'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ce bon ne peut plus être annulé : il est déjà en validation, approuvé ou payé.');
    }

    public function test_un_autre_utilisateur_ne_peut_pas_modifier_le_bon(): void
    {
        $bon = $this->brouillon();

        $this->actingAs(User::factory()->create())
            ->patchJson(route('api.bons.enregistrer', $bon), ['motif' => 'Tentative de modification'])
            ->assertForbidden();
    }

    public function test_un_bon_deja_soumis_ne_se_soumet_pas_une_seconde_fois(): void
    {
        $bon = $this->bonComplet();
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-5'])->assertOk();

        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'autre-cle'])
            ->assertStatus(409)
            ->assertJsonPath('message_cle', 'MSG-APP-001');
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['motif' => 'Modification après soumission'])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------
     * US-BC-14 — corriger et resoumettre
     * ------------------------------------------------------------------ */

    /** RG-BC-30 : même numéro, version + 1, circuit repris au chef de service, historique conservé */
    public function test_un_bon_rejete_est_resoumis_avec_le_meme_numero_et_une_nouvelle_version(): void
    {
        $bon = $this->bonComplet();
        $numero = $this->actingAs($this->souadou)
            ->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-6'])->json('numero');

        /* Rejet par le chef de service (M04) */
        $bon->validations()->where('niveau', 1)->update(['statut' => 'rejete', 'commentaire' => 'Joindre la facture définitive']);
        DB::table('bons_caisse')->where('id', $bon->id)->update(['statut' => 'REJETE']);

        $this->actingAs($this->souadou)->get(route('bons-caisse.edit', $bon))
            ->assertInertia(fn (Assert $page) => $page->component('BonsCaisse/Assistant')->where('bon.statut', 'REJETE'));
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['montant' => 430000])->assertOk();

        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-7'])
            ->assertOk()
            ->assertJsonPath('numero', $numero)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('statut', 'EN_ATTENTE_CHEF_SERVICE');

        $this->assertSame(1, $bon->validations()->where('version', 1)->where('statut', 'rejete')->count());
        $this->assertSame(3, $bon->validations()->where('version', 2)->where('statut', 'en_attente')->count());
        $this->assertSame(1, (int) DB::table('compteurs_numerotation')->value('dernier_numero'));
    }

    /* ------------------------------------------------------------------
     * RG-BC-26 — brouillons abandonnés
     * ------------------------------------------------------------------ */

    public function test_un_brouillon_non_modifie_depuis_30_jours_est_annule_et_le_demandeur_prevenu(): void
    {
        $abandonne = $this->brouillon();
        $recent = $this->brouillon();
        $avecPieceRecente = $this->brouillon();
        $this->piece($avecPieceRecente, 'facture');
        DB::table('bons_caisse')->whereIn('id', [$abandonne->id, $avecPieceRecente->id])->update(['updated_at' => now()->subDays(31)]);

        $this->artisan('bons:annuler-brouillons-abandonnes')->assertSuccessful();

        $this->assertSame('ANNULE', $abandonne->fresh()->statut);
        $this->assertSame('BROUILLON', $recent->fresh()->statut);
        $this->assertSame('BROUILLON', $avecPieceRecente->fresh()->statut);
        $this->assertDatabaseHas('notifications', [
            'destinataire_id' => $this->souadou->id,
            'bon_caisse_id' => $abandonne->id,
            'type' => 'annulation',
        ]);
        $this->assertTrue(HistoriqueAction::where('bon_caisse_id', $abandonne->id)->where('action', 'annulation')->exists());
    }

    /* ------------------------------------------------------------------
     * Outils
     * ------------------------------------------------------------------ */

    private function brouillon(array $valeurs = []): BonCaisse
    {
        $id = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), $valeurs + [
            'type_bon' => 'BD', 'code_analytique' => 'ADAZZZ',
        ])->assertCreated()->json('bon.id');

        return BonCaisse::findOrFail($id);
    }

    private function depense(array $valeurs = []): array
    {
        return $valeurs + [
            'motif' => 'Achat de fournitures pour l\'atelier Aftermarket',
            'categorie_depense' => 'fournitures',
            'montant' => 450000,
            'mode_paiement' => 'especes',
        ];
    }

    /** Bon complet (étapes 1 à 3) avec une facture si demandé */
    private function bonComplet(array $valeurs = [], bool $avecFacture = true): BonCaisse
    {
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), $this->depense($valeurs) + $valeurs)->assertOk();
        if ($avecFacture) {
            $this->piece($bon, 'facture');
        }

        return $bon->fresh();
    }

    private function piece(BonCaisse $bon, ?string $type): void
    {
        $this->actingAs($this->souadou)->postJson(route('api.bons.pieces.ajouter', $bon), array_filter([
            'fichier' => UploadedFile::fake()->create('justificatif.pdf', 120, 'application/pdf'),
            'type_document' => $type,
        ]))->assertCreated();
    }

    private function etape(int $etape, array $valeurs, ?BonCaisse $bon = null)
    {
        $bon ??= null;
        if ($bon === null) {
            return $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), $valeurs + ['etape' => $etape])->assertCreated();
        }

        return $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), $valeurs + ['etape' => $etape])->assertOk();
    }

    private function controles(BonCaisse $bon): array
    {
        return $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))->assertOk()->json('controles');
    }
}
