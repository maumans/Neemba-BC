<?php

namespace Tests\Feature\LotD;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\MotifRejet;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot D2 (spec v2.3) : motifs de rejet paramétrables (RG-M04-04) ; référence du paiement selon le mode (RG-M06-04) ;
 * modes retenus par le caissier, MTN Money activable (RG-M06-07, §6.2).
 */
class ValidationPaiementV23Test extends TestCase
{
    use RefreshDatabase;

    private User $caissier;
    private Caisse $especes;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $site = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $site->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $site->id, 'solde' => 30000000]);
        $this->caissier = User::factory()->role('caissier')->create(['site' => 'Conakry']);
    }

    /** RG-M04-04 : liste initiale de la spec ; un motif désactivé n'est plus proposé ; liste tenue dans le paramétrage */
    public function test_les_motifs_de_rejet_parametrables(): void
    {
        $this->assertSame([
            'piece_manquante' => 'Absence de pièce justificative',
            'manque_authentification' => "Manque d'authentification (bon non signé par le bénéficiaire, le demandeur ou le responsable habilité)",
            'montant_errone' => 'Montant erroné ou illisible',
            'non_conforme_politique' => 'Non-respect de la politique interne (plafond espèces)',
            'mention_obligatoire' => 'Absence de mention obligatoire',
            'autre' => 'Autre (préciser en commentaire)',
        ], MotifRejet::options());

        $chef = User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create(['service' => 'Aftermarket', 'date_soumission' => now()]);
        $bon->creerEtapesValidation();
        $this->actingAs($chef)->post(route('validations.rejeter', $bon), ['motif_rejet' => 'budget_insuffisant'])->assertSessionHasErrors('motif_rejet');
        $this->actingAs($chef)->post(route('validations.rejeter', $bon), ['motif_rejet' => 'manque_authentification', 'commentaire' => 'Signature du bénéficiaire absente'])
            ->assertSessionHas('success');
        $this->assertSame('REJETE', $bon->fresh()->statut);
        $this->actingAs($bon->demandeur)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rejet.motif', "Manque d'authentification (bon non signé par le bénéficiaire, le demandeur ou le responsable habilité)")
                ->where('rejet.commentaire', 'Signature du bénéficiaire absente'));

        $admin = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['gestionnaire_referentiels']));
        $this->actingAs($admin)->post(route('parametrage.motifs-rejet.store'), ['libelle' => 'Dépense hors budget du service'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('parametrage.motifs-rejet.toggle', MotifRejet::where('code', 'mention_obligatoire')->sole()))->assertSessionHas('success');
        $this->assertContains('Dépense hors budget du service', MotifRejet::options());
        $this->assertNotContains('Absence de mention obligatoire', MotifRejet::options());
    }

    /** RG-M06-04 : référence exigée en OM, chèque, virement ; RG-M06-07 : plus de mode « autre » ; MTN Money si activé */
    public function test_la_reference_et_les_modes_du_paiement(): void
    {
        $bon = $this->bonApprouve();
        $this->payer($bon, ['mode_paiement_effectif' => 'orange_money'])->assertSessionHasErrors(['reference_paiement' => __('MSG-APP-065')]);
        $this->payer($bon, ['mode_paiement_effectif' => 'autre', 'reference_paiement' => 'X'])->assertSessionHasErrors('mode_paiement_effectif');
        $this->payer($bon, ['mode_paiement_effectif' => 'mtn_money', 'reference_paiement' => 'MTN-1'])->assertSessionHasErrors('mode_paiement_effectif');
        $this->payer($bon, ['mode_paiement_effectif' => 'cheque', 'reference_paiement' => ' 0012457 '])->assertSessionHas('success');
        $this->assertSame(['PAYE', 'cheque', '0012457'], [$bon->fresh()->statut, $bon->fresh()->mode_paiement_effectif, $bon->fresh()->reference_paiement]);

        /* MTN Money activé : proposé au caissier, payé hors caisse */
        Parametre::majValeur('mode_mtn_actif', 'true');
        $mtn = $this->bonApprouve();
        $this->actingAs($this->caissier)->get(route('bons-caisse.show', $mtn))
            ->assertInertia(fn (Assert $page) => $page->where('modesCaissier.mtn_money', 'MTN Money')->missing('modesCaissier.autre'));
        $solde = (float) $this->especes->fresh()->solde;
        $this->payer($mtn, ['mode_paiement_effectif' => 'mtn_money', 'reference_paiement' => 'MTN-77812'])->assertSessionHas('success');
        $this->assertSame(['mtn_money', null], [$mtn->fresh()->mode_paiement_effectif, $mtn->fresh()->caisse_id]);
        $this->assertSame($solde, (float) $this->especes->fresh()->solde);
    }

    private function bonApprouve(): BonCaisse
    {
        return BonCaisse::factory()->approuve()->create(['site' => 'Conakry', 'montant' => 300000, 'caisse_id' => null]);
    }

    private function payer(BonCaisse $bon, array $donnees)
    {
        OtpValidation::create(['bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false]);

        return $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), $donnees);
    }
}
