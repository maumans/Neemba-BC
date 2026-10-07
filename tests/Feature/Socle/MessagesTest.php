<?php

namespace Tests\Feature\Socle;

use App\Exceptions\ErreurMetier;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Catalogue des messages (SFD §5.6, lang/fr.json) et format des refus métier (SFD §5.7).
 */
class MessagesTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function test_le_catalogue_contient_tous_les_messages_du_module_m03(): void
    {
        $catalogue = json_decode(file_get_contents(lang_path('fr.json')), true);
        $attendus = ['001', '002', '003', '004', '005', '006', '012', '013', '014', '015', '016', '017', '018',
            '019', '020', '021', '022', '023', '024', '025', '026', '030', '031', '032', '033', '034', '035',
            '040', '041', '042'];

        foreach ($attendus as $numero) {
            $this->assertNotEmpty($catalogue["MSG-BC-{$numero}"] ?? null, "MSG-BC-{$numero} manquant");
        }
    }

    public function test_les_variables_sont_formatees(): void
    {
        $this->assertSame(
            'Au-delà de 1' . self::NBSP . '500' . self::NBSP . '000 GNF, ce bon sera aussi validé par le Directeur Pays.',
            ErreurMetier::texte('MSG-BC-020', ['seuil' => 1500000]),
        );
        $this->assertSame(
            'Cette pièce a déjà été jointe au bon BC-2026-0011 du 05/10/2026. Confirmez qu\'elle concerne une autre dépense et expliquez pourquoi.',
            ErreurMetier::texte('MSG-BC-019', ['numero' => 'BC-2026-0011', 'date' => '2026-10-05']),
        );
    }

    public function test_un_refus_metier_appele_par_l_api_suit_le_format_de_la_sfd(): void
    {
        $this->routeQuiRefuse();

        $this->postJson('/_test/erreur-metier')
            ->assertStatus(422)
            ->assertExactJson([
                'code' => 'PLAFOND_RETRAIT_DEPASSE',
                'regle' => 'RG-BC-11',
                'message_cle' => 'MSG-BC-012',
                'valeurs' => ['plafond' => 20000000, 'caisse' => 'caisse principale Conakry'],
                'message' => 'Au-delà de 20' . self::NBSP . '000' . self::NBSP . '000 GNF, ce bon ne peut pas être payé en espèces '
                    . 'sur la caisse principale Conakry. Choisissez Orange Money, chèque ou virement.',
                'champ' => 'mode_paiement',
            ]);
    }

    public function test_un_refus_metier_a_l_ecran_s_affiche_sous_le_champ(): void
    {
        $this->routeQuiRefuse();

        $this->from('/bons-caisse/create')
            ->post('/_test/erreur-metier')
            ->assertRedirect('/bons-caisse/create')
            ->assertSessionHasErrors('mode_paiement');
    }

    private function routeQuiRefuse(): void
    {
        Route::middleware('web')->post('/_test/erreur-metier', function () {
            throw new ErreurMetier(
                'PLAFOND_RETRAIT_DEPASSE',
                'MSG-BC-012',
                ['plafond' => 20000000, 'caisse' => 'caisse principale Conakry'],
                'RG-BC-11',
                'mode_paiement',
            );
        });
    }
}
