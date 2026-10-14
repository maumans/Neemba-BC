<?php

namespace Tests\Feature\LotE;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\EcritureCaisse;
use App\Models\JustificationBp;
use App\Models\Notification;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Models\Visiteur;
use App\Services\BonCaisse\JustifierBp;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot E (spec v2.3, M07) : justification d'un BP conforme à l'Annexe 3 et visas (RG-M07-03), initiateur d'un visiteur
 * (RG-M07-01), BP régularisé, reliquat rétrocédé en caisse et complément versé par un BD lié (RG-M07-08), relance du
 * vendredi (RG-M07-04), transmission aux RH (RG-M07-05), rapport mensuel au DP (RG-M07-07).
 */
class JustificationBpTest extends TestCase
{
    use RefreshDatabase;

    private User $souadou;
    private User $chef;
    private User $cdg;
    private User $comptable;
    private User $caissier;
    private Caisse $especes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $conakry = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id,
            'plafond_retrait' => 20000000, 'solde' => 10000000]);
        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);
        CodeAnalytique::factory()->create(['code' => 'ADBZZZ', 'libelle' => 'Aftermarket atelier', 'service_id' => $aftermarket->id]);

        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'matricule' => 'NG0042', 'site' => 'Conakry', 'service' => 'Aftermarket']);
        $this->chef = User::factory()->role('responsable_service')->create(['prenom' => 'Thierry', 'name' => 'GOMIS', 'service' => 'Aftermarket']);
        $this->cdg = User::factory()->role('controle_gestion')->create(['prenom' => 'Maimouna', 'name' => 'BARRY']);
        $this->comptable = User::factory()->create(['prenom' => 'Mamadou Alpha', 'name' => 'BAH']);
        $this->comptable->ajouterRoles(['chef_comptable']);
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'site' => 'Conakry']);
    }

    /** RG-M07-03, RG-M07-08 : Annexe 3, visas initiateur → N+1 → CDG → Comptabilité, BP régularisé, reliquat encaissé */
    public function test_la_justification_visee_regularise_le_bp_et_le_reliquat_est_encaisse(): void
    {
        $bp = $this->bpPaye(1000000);

        $this->actingAs($this->souadou)->get(route('bons-caisse.justification', $bp))
            ->assertInertia(fn (Assert $page) => $page->component('BonsCaisse/Justification')
                ->where('bon.nom', 'BARRY')->where('bon.prenom', 'Souadou')->where('bon.matricule', 'NG0042')
                ->where('bon.departement', 'Aftermarket')->where('bon.montant', 1000000)
                ->where('justification.statut', 'brouillon')->where('droits.modifier', true));

        $facture = $this->piece($bp, 'facture-hotel.pdf');
        $recu = $this->piece($bp, 'recu-taxi.pdf');
        $this->actingAs($this->souadou)->put(route('bons-caisse.justification.enregistrer', $bp), [
            'lignes' => [
                ['intitule' => 'Hôtel Kankan, 2 nuits', 'code_analytique' => 'ADAZZZ', 'compte' => '625100', 'montant' => 600000, 'piece_jointe_id' => $facture],
                ['intitule' => 'Taxi aéroport', 'code_analytique' => 'ADBZZZ', 'compte' => '625200', 'montant' => 200000, 'piece_jointe_id' => $recu],
            ],
            'montant_a_retourner' => 200000,
        ])->assertSessionHas('success');
        $j = $bp->justification()->sole();
        $this->assertSame([800000.0, 200000.0, 0.0], [(float) $j->montant_justifie, (float) $j->montant_a_retourner, (float) $j->trop_justifie]);
        $this->assertSame(['justifie' => 800000.0, 'a_retourner' => 200000.0, 'restant' => 0.0, 'trop_justifie' => 0.0],
            JustifierBp::calculs(1000000, 800000, 200000));

        /* Personne d'autre que l'initiateur ne vise ou ne modifie */
        $this->actingAs($this->souadou)->post(route('bons-caisse.justification.soumettre', $bp))->assertSessionHas('success');
        $this->assertSame(['en_validation', 'n1'], [$j->fresh()->statut, $j->fresh()->etape]);
        $this->assertTrue(Notification::where('destinataire_id', $this->chef->id)->where('type', 'justification_bp')->exists());
        $this->actingAs($this->cdg)->post(route('bons-caisse.justification.viser', $bp), ['decision' => 'visee'])
            ->assertForbidden();
        $this->actingAs($this->souadou)->put(route('bons-caisse.justification.enregistrer', $bp), ['lignes' => []])
            ->assertSessionHasErrors();

        /* Le chef de service voit le BP et vise ; puis le CDG ; puis la Comptabilité */
        $this->actingAs($this->chef)->get(route('bons-caisse.justification', $bp))->assertInertia(fn (Assert $page) => $page->where('droits.viser', true));
        $this->viser($this->chef, $bp);
        $this->assertSame('cdg', $j->fresh()->etape);
        $this->viser($this->cdg, $bp);
        $this->assertSame('comptabilite', $j->fresh()->etape);
        $this->viser($this->comptable, $bp);
        $this->assertSame(['validee', 'REGULARISE'], [$j->fresh()->statut, $bp->fresh()->statut]);
        $this->assertNull($j->fresh()->bon_complement_id);
        $this->assertSame(['initiateur:soumise', 'n1:visee', 'cdg:visee', 'comptabilite:visee'],
            $j->visas()->get()->map(fn ($v) => "{$v->etape}:{$v->decision}")->all());

        /* Reliquat : le caissier l'encaisse, une seule fois */
        $this->actingAs($this->caissier)->get(route('bons-caisse.show', $bp))
            ->assertInertia(fn (Assert $page) => $page->where('justification.reliquat_a_encaisser', true)->where('justification.peut_encaisser', true));
        $this->actingAs($this->caissier)->post(route('bons-caisse.justification.reliquat', $bp))->assertSessionHas('success');
        $this->assertSame(10200000.0, (float) $this->especes->fresh()->solde);
        $this->assertTrue(EcritureCaisse::where('nature', 'retrocession_bp')->where('bon_caisse_id', $bp->id)->where('montant', 200000)->exists());
        $this->actingAs($this->caissier)->post(route('bons-caisse.justification.reliquat', $bp))->assertSessionHasErrors();
        $this->assertSame(10200000.0, (float) $this->especes->fresh()->solde);
    }

    /** RG-M07-08 : trop justifié versé par un BD de complément, approuvé et lié au BP */
    public function test_le_trop_justifie_est_verse_par_un_bd_de_complement(): void
    {
        $bp = $this->bpPaye(500000);
        $this->justifier($bp, [['Carburant groupe électrogène', 'ADAZZZ', 650000]]);
        $this->actingAs($this->souadou)->post(route('bons-caisse.justification.soumettre', $bp))->assertSessionHas('success');
        foreach ([$this->chef, $this->cdg, $this->comptable] as $valideur) {
            $this->viser($valideur, $bp);
        }

        $complement = BonCaisse::where('bp_regularise_id', $bp->id)->sole();
        $this->assertSame(['BD', 'APPROUVE', 150000.0, $this->souadou->id], [$complement->type_bon, $complement->statut, (float) $complement->montant, $complement->demandeur_id]);
        $this->assertNotNull($complement->numero);
        $this->assertSame($complement->id, $bp->justification->bon_complement_id);
        $this->assertSame('REGULARISE', $bp->fresh()->statut);
        $this->actingAs($this->souadou)->get(route('bons-caisse.show', $complement))
            ->assertInertia(fn (Assert $page) => $page->where('bpRegularise.numero', $bp->numero));
    }

    /** RG-M07-03 : restant à justifier, ligne incomplète, 10 lignes au plus ; rejet motivé, renvoi à l'initiateur, version 2 */
    public function test_les_controles_et_le_rejet(): void
    {
        $bp = $this->bpPaye(1000000);
        $this->justifier($bp, [['Hôtel', 'ADAZZZ', 700000]]);
        $this->actingAs($this->souadou)->post(route('bons-caisse.justification.soumettre', $bp))
            ->assertSessionHasErrors(['lignes' => __('MSG-APP-072', ['detail' => __('MSG-APP-071', ['restant' => Format::nombre(300000)])])]);

        /* Ligne sans pièce ni code actif */
        $this->actingAs($this->souadou)->put(route('bons-caisse.justification.enregistrer', $bp), [
            'lignes' => [['intitule' => 'Repas', 'code_analytique' => 'INCONNU', 'montant' => 1000000]],
        ]);
        $this->assertSame([__('MSG-APP-070', ['ligne' => 1])], JustifierBp::erreurs($bp->justification->fresh()));
        $this->actingAs($this->souadou)->put(route('bons-caisse.justification.enregistrer', $bp), [
            'lignes' => array_fill(0, 11, ['intitule' => 'Repas', 'code_analytique' => 'ADAZZZ', 'montant' => 1000]),
        ])->assertSessionHasErrors('lignes');

        /* Une autre personne ne peut ni ouvrir ni modifier */
        $autre = User::factory()->create(['service' => 'Aftermarket']);
        $this->actingAs($autre)->get(route('bons-caisse.justification', $bp))->assertForbidden();
        $this->actingAs($autre)->put(route('bons-caisse.justification.enregistrer', $bp), ['lignes' => []])->assertForbidden();

        /* Rejet du N+1 : commentaire obligatoire, renvoi à l'initiateur ; nouvelle version à la resoumission */
        $this->justifier($bp, [['Hôtel', 'ADAZZZ', 1000000]]);
        $this->actingAs($this->souadou)->post(route('bons-caisse.justification.soumettre', $bp))->assertSessionHas('success');
        $this->actingAs($this->chef)->post(route('bons-caisse.justification.viser', $bp), ['decision' => 'rejetee', 'commentaire' => 'Court'])
            ->assertSessionHasErrors('commentaire');
        $this->actingAs($this->chef)->post(route('bons-caisse.justification.viser', $bp), ['decision' => 'rejetee', 'commentaire' => 'La facture ne porte pas le cachet de l\'hôtel'])
            ->assertSessionHas('success');
        $j = $bp->justification->fresh();
        $this->assertSame(['rejetee', 1], [$j->statut, $j->version]);
        $this->assertTrue(Notification::where('destinataire_id', $this->souadou->id)->where('titre', 'Justification rejetée')->exists());
        $this->justifier($bp, [['Hôtel (facture avec cachet)', 'ADAZZZ', 1000000]]);
        $this->actingAs($this->souadou)->post(route('bons-caisse.justification.soumettre', $bp))->assertSessionHas('success');
        $this->assertSame(['en_validation', 2, 'n1'], [$j->fresh()->statut, $j->fresh()->version, $j->fresh()->etape]);
    }

    /** Étape sans valideur possible sautée ; RG-M07-01 : pour un visiteur, le directeur d'accueil est l'initiateur */
    public function test_etape_sautee_et_visiteur(): void
    {
        $this->chef->update(['service' => 'Location']);
        $directeur = User::factory()->role('responsable_service')->create(['prenom' => 'Lamine', 'name' => 'SOUMAH', 'service' => 'Technique']);
        $visiteur = Visiteur::create(['nom' => 'DIOP', 'prenom' => 'Awa', 'filiale' => 'Neemba Sénégal', 'departement_accueil' => 'Technique',
            'directeur_id' => $directeur->id, 'actif' => true, 'date_debut' => today(), 'date_fin' => today()->addMonth()]);
        $bp = $this->bpPaye(300000, ['type_beneficiaire' => 'visiteur', 'visiteur_id' => $visiteur->id, 'beneficiaire' => 'Awa DIOP']);

        $this->assertSame($directeur->id, JustifierBp::initiateurDe($bp)->id);
        $this->actingAs($directeur)->get(route('bons-caisse.justification', $bp))
            ->assertInertia(fn (Assert $page) => $page->where('droits.modifier', true)->where('bon.visiteur', 'Awa DIOP'));
        $this->justifier($bp, [['Hébergement visiteur', 'ADAZZZ', 300000]], $directeur);
        $this->actingAs($directeur)->post(route('bons-caisse.justification.soumettre', $bp))->assertSessionHas('success');

        /* Le N+1 du directeur (service Technique) n'existe pas en dehors de lui-même : étape sautée */
        $j = $bp->justification->fresh();
        $this->assertSame('cdg', $j->etape);
        $this->assertSame('sautee', $j->visas()->where('etape', 'n1')->value('decision'));
    }

    /** Le paiement d'un BP ne le régularise plus, même avec des pièces jointes à l'avance */
    public function test_le_paiement_ne_regularise_plus_le_bp(): void
    {
        $bp = BonCaisse::factory()->provisoire()->approuve()->create(['demandeur_id' => $this->souadou->id, 'montant' => 400000, 'site' => 'Conakry']);
        $this->piece($bp, 'devis.pdf', 'justificatif');
        $this->assertTrue($bp->marquerCommePaye($this->caissier, 'especes'));
        $this->assertSame('EN_ATTENTE_REGULARISATION', $bp->fresh()->statut);
        $this->assertNotNull($bp->fresh()->date_limite_regularisation);
    }

    /** RG-M07-04, RG-M07-05, RG-M07-07 : relance du vendredi, transmission aux RH, information puis retenue, rapport au DP */
    public function test_relance_suivi_rh_et_rapport_mensuel(): void
    {
        $tresoriere = User::factory()->create(['prenom' => 'Mariamagbè', 'name' => 'CAMARA']);
        $tresoriere->ajouterRoles(['tresorerie']);
        $daf = User::factory()->role('daf')->create();
        $drh = User::factory()->create(['prenom' => 'Fatou', 'name' => 'CAMARA']);
        $drh->ajouterRoles(['rh']);
        $dp = User::factory()->role('directeur_pays')->create();
        $bp = $this->bpPaye(750000, ['date_limite_regularisation' => today()->subDays(3)]);

        /* Relance au nom du caissier, copies : chef de service, Chef comptable, Trésorerie, DAF */
        $this->artisan('bons:relancer-regularisation')->assertSuccessful();
        $relances = Notification::where('type', 'relance_regularisation')->where('bon_caisse_id', $bp->id)->get();
        $this->assertEqualsCanonicalizing([$this->souadou->id, $this->chef->id, $this->comptable->id, $tresoriere->id, $daf->id], $relances->pluck('destinataire_id')->all());
        $this->assertSame([$this->caissier->id], $relances->pluck('expediteur_id')->unique()->values()->all());

        /* Transmission aux RH, une seule fois */
        $this->artisan('bons:transmettre-bp-rh')->assertSuccessful();
        $this->artisan('bons:transmettre-bp-rh')->assertSuccessful();
        $this->assertNotNull($bp->fresh()->suivi_rh['transmis_le'] ?? null);
        $this->assertSame(1, Notification::where('destinataire_id', $drh->id)->where('type', 'suivi_rh')->count());

        /* Les RH voient le BP ; la retenue n'intervient qu'après l'information de la DRH */
        $this->actingAs($drh)->get(route('bons-caisse.show', $bp))->assertInertia(fn (Assert $page) => $page->where('justification.peut_suivre_rh', true));
        $this->actingAs($drh)->get(route('bons-caisse.bp-en-retard'))->assertOk();
        $this->actingAs($drh)->post(route('bons-caisse.suivi-rh', $bp), ['action' => 'retenue'])->assertSessionHasErrors(['action' => __('MSG-APP-075')]);
        $this->actingAs($drh)->post(route('bons-caisse.suivi-rh', $bp), ['action' => 'information_drh', 'commentaire' => 'Email du 14/10'])->assertSessionHas('success');
        $this->actingAs($drh)->post(route('bons-caisse.suivi-rh', $bp), ['action' => 'retenue', 'montant' => 750000])->assertSessionHas('success');
        $suivi = $bp->fresh()->suivi_rh;
        $this->assertSame([750000, $drh->id], [(int) $suivi['retenue']['montant'], $suivi['information_drh']['par_id']]);
        $this->actingAs($this->souadou)->post(route('bons-caisse.suivi-rh', $bp), ['action' => 'retenue'])->assertForbidden();

        /* Rapport mensuel au DP */
        $this->artisan('bons:rapport-bp-mensuel')->assertSuccessful();
        $rapport = Notification::where('destinataire_id', $dp->id)->where('type', 'rapport_bp')->sole();
        $this->assertStringContainsString('1 BP non régularisé(s)', $rapport->message);
        $this->assertStringContainsString('1 transmis aux RH', $rapport->message);
    }

    /* ------------------------------------------------------------------ */

    private function bpPaye(float $montant, array $attributs = []): BonCaisse
    {
        return BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create($attributs + [
            'demandeur_id' => $this->souadou->id,
            'beneficiaire_id' => $this->souadou->id,
            'beneficiaire' => 'Souadou BARRY',
            'montant' => $montant,
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'caissier_id' => $this->caissier->id,
            'caisse_id' => $this->especes->id,
            'date_paiement' => now()->subDays(5),
            'mode_paiement_effectif' => 'especes',
            'date_limite_regularisation' => today()->addDays(2),
        ]);
    }

    private function piece(BonCaisse $bon, string $nom, string $type = 'justificatif_bp'): int
    {
        return $bon->piecesJointes()->create([
            'type_document' => $type,
            'nom_fichier' => $nom,
            'chemin_fichier' => 'pieces_jointes/' . $bon->id . '/' . $nom,
            'taille' => 1000,
            'mime_type' => 'application/pdf',
        ])->id;
    }

    /** @param array<array{0: string, 1: string, 2: float}> $lignes */
    private function justifier(BonCaisse $bp, array $lignes, ?User $auteur = null): void
    {
        $this->actingAs($auteur ?? $this->souadou)->put(route('bons-caisse.justification.enregistrer', $bp), [
            'lignes' => array_map(fn ($l) => ['intitule' => $l[0], 'code_analytique' => $l[1], 'montant' => $l[2],
                'piece_jointe_id' => $this->piece($bp, uniqid('piece-') . '.pdf')], $lignes),
        ])->assertSessionHas('success');
    }

    private function viser(User $valideur, BonCaisse $bp): void
    {
        $this->actingAs($valideur)->post(route('bons-caisse.justification.viser', $bp), ['decision' => 'visee'])->assertSessionHas('success');
    }
}
