<?php

namespace Tests\Feature\M12;

use App\Models\TauxChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Le formulaire d'un ODM extérieur affiche le dernier taux FCFA → GNF saisi par la trésorerie dès l'ouverture, avant le
 * premier calcul du brouillon (signalé le 20/10 : « Aucun taux saisi » s'affichait alors que le taux du jour existait).
 */
class TauxFormulaireOdmTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_formulaire_recoit_le_dernier_taux_saisi(): void
    {
        $demandeur = User::factory()->create();
        $this->actingAs($demandeur)->get(route('odm.create'))->assertInertia(fn (Assert $page) => $page
            ->component('Odm/Formulaire')->where('tauxEnVigueur', null));

        TauxChange::create(['date_taux' => today()->subDay(), 'devise' => TauxChange::DEVISE_FCFA, 'taux' => 14.40, 'saisi_par_id' => $demandeur->id]);
        TauxChange::create(['date_taux' => today(), 'devise' => TauxChange::DEVISE_FCFA, 'taux' => 14.52, 'saisi_par_id' => $demandeur->id]);
        $this->actingAs($demandeur)->get(route('odm.create'))->assertInertia(fn (Assert $page) => $page
            ->where('tauxEnVigueur.taux', 14.52)->where('tauxEnVigueur.date', today()->format('d/m/Y'))->where('tauxEnVigueur.fige', false));
    }
}
