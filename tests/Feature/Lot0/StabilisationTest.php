<?php

namespace Tests\Feature\Lot0;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\MouvementCaisse;
use App\Models\OtpValidation;
use App\Models\RapportCaisse;
use App\Models\Site;
use App\Models\User;
use App\Services\RapportJournalierService;
use Database\Seeders\NeembaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Non-régression des correctifs du lot 0 (stabilisation avant M03).
 */
class StabilisationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequenceBon = 0;

    /* ------------------------------------------------------------------
     * Paiement
     * ------------------------------------------------------------------ */

    public function test_un_paiement_orange_money_debite_le_solde_om(): void
    {
        $site = $this->site(['solde_especes' => 1000000, 'solde_om' => 800000]);
        $bon = $this->bon(['montant' => 300000]);
        $otp = $this->otpVerifie($bon);

        $this->actingAs($this->utilisateur('caissier'))
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])
            ->assertRedirect(route('bons-caisse.show', $bon));

        $site->refresh();
        $this->assertEquals(1000000, (float) $site->solde_especes);
        $this->assertEquals(497000, (float) $site->solde_om);   // spec v2.2 §6.6 : 300 000 + 1 % de frais OM
        $this->assertEquals('orange_money', $bon->fresh()->mode_paiement_effectif);
        $this->assertEquals('PAYE', $bon->fresh()->statut);   // Q14 : un BD payé reste « Payé »
        $this->assertTrue((bool) $otp->fresh()->is_used);
    }

    public function test_un_virement_est_paye_hors_caisse(): void
    {
        $site = $this->site(['solde_especes' => 1000000, 'solde_om' => 0]);
        $bon = $this->bon(['montant' => 5000000]);
        $this->otpVerifie($bon);

        $this->actingAs($this->utilisateur('caissier'))
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'virement'])
            ->assertRedirect(route('bons-caisse.show', $bon));

        $this->assertEquals(1000000, (float) $site->fresh()->solde_especes);
        $this->assertNotNull($bon->fresh()->date_paiement);
    }

    public function test_un_solde_insuffisant_ne_consomme_pas_l_otp(): void
    {
        $site = $this->site(['solde_especes' => 100000, 'solde_om' => 0]);
        $bon = $this->bon(['montant' => 300000]);
        $otp = $this->otpVerifie($bon);

        $this->actingAs($this->utilisateur('caissier'))
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertSessionHas('error');

        $this->assertEquals('APPROUVE', $bon->fresh()->statut);
        $this->assertFalse((bool) $otp->fresh()->is_used);
        $this->assertEquals(100000, (float) $site->fresh()->solde_especes);
    }

    /* ------------------------------------------------------------------
     * Liste des bons
     * ------------------------------------------------------------------ */

    public function test_les_cartes_de_la_liste_ne_tombent_pas_a_zero_en_page_2(): void
    {
        $demandeur = $this->utilisateur('demandeur');
        for ($i = 0; $i < 25; $i++) {
            $this->bon(['demandeur_id' => $demandeur->id, 'statut' => 'REJETE']);
        }

        /* 20 lignes par page (E-03.1) : la page 2 en montre 5, les cartes comptent les 25 */
        $this->actingAs($demandeur)
            ->get(route('bons-caisse.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('bonsCaisse.data', 5)
                ->where('statsIndex.total.nombre', 25)
                ->where('statsIndex.rejetes.nombre', 25));
    }

    public function test_la_fiche_transmet_les_motifs_de_rejet(): void
    {
        $demandeur = $this->utilisateur('demandeur');
        $bon = $this->bon(['demandeur_id' => $demandeur->id]);

        $this->actingAs($demandeur)
            ->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('motifsRejet', BonCaisse::MOTIFS_REJET));
    }

    /* ------------------------------------------------------------------
     * Rapport journalier
     * ------------------------------------------------------------------ */

    public function test_le_rapport_journalier_ventile_especes_et_om(): void
    {
        $this->site();
        $hier = now()->subDay()->startOfDay();
        $caissier = $this->utilisateur('caissier');

        /* Clôture de l'avant-veille = ouverture d'hier */
        RapportCaisse::create([
            'date_rapport' => $hier->copy()->subDay(), 'site' => 'Conakry', 'caissier_id' => $caissier->id,
            'solde_ouverture' => 0, 'total_entrees' => 0, 'total_sorties' => 0,
            'solde_cloture' => 2100000, 'solde_cloture_especes' => 2000000, 'solde_cloture_om' => 100000,
        ]);

        $paye = ['date_paiement' => $hier->copy()->setTime(10, 0), 'caissier_id' => $caissier->id];
        /* BP payé : passé en attente de régularisation, il doit rester dans le rapport du jour */
        $this->bon($paye + ['type_bon' => 'BP', 'statut' => 'EN_ATTENTE_REGULARISATION', 'montant' => 300000, 'mode_paiement_effectif' => 'especes']);
        $this->bon($paye + ['statut' => 'PAYE', 'montant' => 200000, 'mode_paiement_effectif' => 'orange_money']);
        /* Virement : hors caisse */
        $this->bon($paye + ['statut' => 'PAYE', 'montant' => 900000, 'mode_paiement_effectif' => 'virement']);

        $this->mouvement($caissier, 'approvisionnement', 'especes', 1000000, $hier->copy()->setTime(8, 0));
        $this->mouvement($caissier, 'retrait', 'om', 50000, $hier->copy()->setTime(9, 0));

        ['rapport' => $rapport, 'bonsPaye' => $bonsPaye] = RapportJournalierService::construire($hier, 'Conakry');

        $this->assertCount(2, $bonsPaye);
        $this->assertEquals(2000000, (float) $rapport->solde_ouverture_especes);
        $this->assertEquals(100000, (float) $rapport->solde_ouverture_om);
        $this->assertEquals(1000000, (float) $rapport->total_entrees_especes);
        $this->assertEquals(300000, (float) $rapport->total_sorties_especes);
        $this->assertEquals(250000, (float) $rapport->total_sorties_om);
        $this->assertEquals(2700000, (float) $rapport->solde_cloture_especes);
        $this->assertEquals(-150000, (float) $rapport->solde_cloture_om);
        $this->assertEquals(2550000, (float) $rapport->solde_cloture);
    }

    public function test_le_rapport_du_jour_ne_sert_pas_d_ouverture_a_lui_meme(): void
    {
        $caissier = $this->utilisateur('caissier');
        RapportCaisse::create([
            'date_rapport' => today(), 'site' => 'Conakry', 'caissier_id' => $caissier->id,
            'solde_ouverture' => 0, 'total_entrees' => 0, 'total_sorties' => 0,
            'solde_cloture' => 999, 'solde_cloture_especes' => 999, 'solde_cloture_om' => 0,
        ]);

        $this->assertEquals(0, RapportCaisse::soldePrecedent('Conakry', today())['especes']);
        $this->assertEquals(999, RapportCaisse::soldePrecedent('Conakry', today()->addDay())['especes']);
    }

    public function test_l_enregistrement_du_rapport_recalcule_les_ecarts_et_exige_un_motif(): void
    {
        $this->site();
        $caissier = $this->utilisateur('caissier');
        $saisie = [
            'date_rapport' => today()->toDateString(), 'site' => 'Conakry',
            'total_entrees_especes' => 0, 'total_entrees_om' => 0,
            'total_sorties_especes' => 0, 'total_sorties_om' => 0,
            'billetage' => ['20000' => 10, '999' => 5],   // 999 : coupure inconnue, ignorée
            'solde_physique_especes' => 1,                 // recalculé depuis le billetage
            'ecart_especes' => 0,                          // envoyé par l'écran : ignoré
        ];

        $this->actingAs($caissier)->post(route('rapports.store'), $saisie)
            ->assertSessionHasErrors('motif_ecart');

        $this->actingAs($caissier)->post(route('rapports.store'), $saisie + ['motif_ecart' => 'Billets trouvés dans le coffre'])
            ->assertSessionHasNoErrors();

        $rapport = RapportCaisse::latest('id')->first();
        $this->assertEquals(200000, (float) $rapport->solde_physique_especes);
        $this->assertEquals(200000, (float) $rapport->ecart_especes);
        $this->assertNull($rapport->ecart_om);
        $this->assertEquals(['20000' => 10], $rapport->billetage);
    }

    /* ------------------------------------------------------------------
     * Commandes et seeder
     * ------------------------------------------------------------------ */

    public function test_l_alerte_d_expiration_des_archives_s_execute(): void
    {
        $this->artisan('archives:alerter-expiration')->assertExitCode(0);
    }

    public function test_le_seeder_pilote_credite_la_caisse_de_conakry(): void
    {
        $this->seed(NeembaSeeder::class);

        $this->assertEquals(15000000, Site::where('nom', 'Conakry')->first()->solde_especes);   // caisse CKY-ESP
    }

    /* ------------------------------------------------------------------
     * Données de test
     * ------------------------------------------------------------------ */

    private function utilisateur(string $role, array $attributs = []): User
    {
        return User::factory()->create($attributs + [
            'prenom' => 'Test',
            'role' => $role,
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'actif' => true,
            'matricule' => 'T-' . Str::random(8),
            'telephone' => '622000000',
        ]);
    }

    /** Site de Conakry avec sa caisse espèces et la caisse Orange Money (lot 2 : l'argent est porté par les caisses) */
    private function site(array $soldes = []): Site
    {
        $site = Site::create(['code' => '01', 'nom' => 'Conakry', 'ville' => 'Conakry', 'actif' => true]);
        Caisse::create([
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $site->id,
            'type' => 'especes', 'solde' => $soldes['solde_especes'] ?? 0, 'seuil_alerte' => 0,
        ]);
        Caisse::create([
            'code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $site->id,
            'type' => 'orange_money', 'solde' => $soldes['solde_om'] ?? 0, 'seuil_alerte' => 0,
        ]);

        return $site;
    }

    private function bon(array $attributs = []): BonCaisse
    {
        $this->sequenceBon++;

        return BonCaisse::create($attributs + [
            'numero' => sprintf('BC-2026-9%03d', $this->sequenceBon),
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'beneficiaire' => 'Souadou BARRY',
            'type_beneficiaire' => 'employe',
            'mode_paiement' => 'especes',
            'motif' => 'Achat carburant mission Kankan',
            'categorie_depense' => 'carburant',
            'montant' => 500000,
            'statut' => 'APPROUVE',
            'demandeur_id' => $attributs['demandeur_id'] ?? $this->utilisateur('demandeur')->id,
            'date_demande' => today(),
        ]);
    }

    private function otpVerifie(BonCaisse $bon): OtpValidation
    {
        return OtpValidation::create([
            'bon_caisse_id' => $bon->id,
            'code' => '123456',
            'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
            'is_used' => false,
        ]);
    }

    private function mouvement(User $par, string $type, string $typeCaisse, float $montant, $dateValidation): MouvementCaisse
    {
        return MouvementCaisse::create([
            'reference' => MouvementCaisse::genererReference(),
            'type' => $type,
            'type_caisse' => $typeCaisse,
            'montant' => $montant,
            'motif' => 'Mouvement de test',
            'site' => 'Conakry',
            'statut' => 'valide',
            'effectue_par' => $par->id,
            'valide_par' => $par->id,
            'date_mouvement' => $dateValidation,
            'date_validation' => $dateValidation,
        ]);
    }
}
