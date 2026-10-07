<?php

namespace Tests\Feature\Referentiels;

use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\ModificationEnAttente;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import du classeur « Référentiels de paramétrage — tous sites » (points 11 à 14).
 */
class ImportReferentielsTest extends TestCase
{
    use RefreshDatabase;

    private User $administrateur;
    private User $second;
    private Caisse $especes;
    private Caisse $atelier;

    protected function setUp(): void
    {
        parent::setUp();

        $conakry = Site::factory()->conakry()->create();
        Site::factory()->create(['code' => '49', 'nom' => 'Kouroussa', 'ville' => 'Kouroussa']);
        Service::factory()->create(['nom' => 'DAF', 'code' => '900']);
        Service::factory()->create(['nom' => 'Technique', 'code' => '300']);
        Service::factory()->create(['nom' => 'Aftermarket', 'code' => '200']);

        $this->especes = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id,
            'plafond_retrait' => 20000000, 'seuil_alerte' => 1000000]);
        $this->atelier = Caisse::factory()->create(['code' => 'CKY-ATL', 'libelle' => 'Caisse Atelier', 'site_id' => $conakry->id,
            'mode' => 'avance_fixe', 'montant_avance' => 15000000, 'actif' => false]);
        Caisse::factory()->create(['code' => 'KOU-ESP', 'libelle' => 'Caisse principale Kouroussa',
            'site_id' => Site::where('code', '49')->value('id')]);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Administration ADA']);

        $this->administrateur = User::factory()->role('administrateur')->create(['email' => 'admin@neemba.com']);
        $this->second = User::factory()->role('administrateur')->create(['email' => 'admin2@neemba.com']);
        User::factory()->create(['name' => 'BARRY', 'prenom' => 'Saoudou', 'email' => 'saoudou.barry@neemba.com', 'site' => 'Conakry', 'service' => 'Aftermarket']);
        User::factory()->role('daf')->create(['name' => 'DIAKITE', 'prenom' => 'Mohamed', 'email' => 'mohamed.diakite@neemba.com']);
        User::factory()->create(['name' => 'TOURE', 'prenom' => 'Youssouf', 'email' => 'youssouf.toure@neemba.com']);
    }

    public function test_une_simulation_n_enregistre_rien(): void
    {
        $this->artisan('referentiels:importer', ['fichier' => $this->classeur(), '--rapport' => $this->rapport()])
            ->expectsOutputToContain('Simulation')
            ->assertSuccessful();

        $this->assertFalse(User::where('email', 'saliou.boiro@neemba.com')->exists());
        $this->assertSame(0, ModificationEnAttente::count());
        $this->assertStringContainsString('**simulation**', file_get_contents($this->rapport()));
    }

    public function test_l_import_cree_les_comptes_et_attribue_les_roles_des_nouveaux_comptes(): void
    {
        $this->importer();

        $boiro = User::where('email', 'saliou.boiro@neemba.com')->firstOrFail();
        $this->assertTrue($boiro->aLeRole('controle_gestion'));
        $this->assertTrue($boiro->aLeRole('demandeur'));
        $this->assertSame('controle_gestion', $boiro->role);
        $this->assertSame('DAF', $boiro->service);
        $this->assertSame('+224622352940', $boiro->telephone);
        $this->assertSame('cadre', $boiro->statut_cadre);
        $this->assertSame('DIAKITE', $boiro->responsable?->name);

        /* Visa Finance « un seul des trois suffit » : rôle déclaré selon la fonction */
        $this->assertTrue(User::where('email', 'mamadou-alpha.bah@neemba.com')->firstOrFail()->aLeRole('chef_comptable'));
    }

    /** Décision Q11 : « Saoudou » → « Souadou », rapproché malgré l'orthographe ; l'adresse de connexion suit le référentiel */
    public function test_un_compte_existant_est_rapproche_et_mis_a_jour(): void
    {
        $rapport = $this->importer();

        $souadou = User::where('email', 'souadou.barry@neemba.com')->firstOrFail();
        $this->assertSame('Souadou', $souadou->prenom);
        $this->assertSame('Neemba Guinée', $souadou->entite);
        $this->assertStringContainsString('Adresse de connexion modifiée : saoudou.barry@neemba.com → souadou.barry@neemba.com', $rapport);
    }

    public function test_les_valeurs_a_confirmer_ne_sont_pas_appliquees(): void
    {
        $rapport = $this->importer();

        /* « Trésorerie (à confirmer) » et « Caissier (à confirmer) » */
        $toure = User::where('email', 'youssouf.toure@neemba.com')->firstOrFail();
        $this->assertFalse($toure->aLeRole('caissier'));
        $this->assertTrue($toure->aLeRole('tresorerie'));   // titulaire Trésorerie (onglet 5), rôle sans privilège
        $this->assertNull(Service::where('nom', 'Technique')->value('equivalent_odm'));
        $this->assertStringContainsString('| Technique | Équivalent sur la fiche ODM | SERVICE |', $rapport);
        $this->assertStringContainsString('Caissier (à confirmer)', $rapport);
    }

    public function test_un_role_a_privileges_n_est_pas_ajoute_a_un_compte_existant(): void
    {
        $rapport = $this->importer();

        $this->assertFalse(User::where('email', 'youssouf.toure@neemba.com')->first()->aLeRole('controle_gestion'));
        $this->assertStringContainsString('Attribuer le rôle « Contrôle de gestion » (Utilisateurs)', $rapport);
    }

    public function test_un_compte_sans_email_n_est_pas_cree(): void
    {
        $rapport = $this->importer();

        $this->assertFalse(User::where('name', 'CISS')->exists());
        $this->assertStringContainsString('| CISS Mor | Compte non créé : adresse e-mail manquante', $rapport);
    }

    /** Plafonds et seuils : double validation ; un second import ne double pas la demande */
    public function test_plafonds_et_seuils_de_caisse_passent_par_la_double_validation(): void
    {
        $this->importer();
        $this->importer();

        $this->assertSame(1000000.0, (float) $this->especes->fresh()->seuil_alerte);
        $demandes = ModificationEnAttente::where('entite_id', $this->especes->id)->where('statut', 'en_attente')->get()->keyBy('champ');
        $this->assertSame(['plafond_caisse', 'seuil_alerte'], $demandes->keys()->sort()->values()->all());
        $this->assertSame($this->administrateur->id, $demandes['seuil_alerte']->demandeur_id);

        $demandes['plafond_caisse']->approuver($this->second);
        $this->assertSame(250000000.0, (float) $this->especes->fresh()->plafond_caisse);
    }

    public function test_les_autres_champs_de_caisse_sont_appliques(): void
    {
        $this->importer();

        $especes = $this->especes->fresh();
        $this->assertTrue($especes->encaissements_clients);
        $this->assertSame('Virement bancaire — Banque', $especes->reapprovisionnement);
        $this->assertSame(['BOIRO', 'DIAKITE'], User::whereIn('id', $especes->destinataires_rapport)->orderBy('name')->pluck('name')->all());

        /* « Caissier — caisse Atelier » : gestionnaire de la caisse, sans le rôle caissier général */
        $raby = User::where('email', 'raby.tounkara@neemba.com')->firstOrFail();
        $this->assertSame($raby->id, $this->atelier->fresh()->gestionnaire_id);
        $this->assertFalse($raby->aLeRole('caissier'));

        /* « CORICA » = Kouroussa, rattachée par le code site */
        $this->assertSame('Caisse CORICA', Caisse::where('code', 'KOU-ESP')->value('libelle'));
    }

    public function test_installation_initiale_sans_double_validation(): void
    {
        $this->importer(['--sans-double-validation' => true]);

        $this->assertSame(50000000.0, (float) $this->especes->fresh()->seuil_alerte);
        $this->assertSame(1000000.0, (float) $this->atelier->fresh()->plafond_retrait);
        $this->assertSame(0, ModificationEnAttente::count());
    }

    public function test_codes_analytiques_de_la_liste_de_reference(): void
    {
        $rapport = $this->importer();

        $this->assertSame('200', CodeAnalytique::where('code', 'ADAZZZ')->value('code_service_comptable'));
        $this->assertSame('Administration ADA', CodeAnalytique::where('code', 'ADAZZZ')->value('libelle'));   // libellé laissé au CDG
        $nouveau = CodeAnalytique::where('code', 'MACZZZ')->firstOrFail();
        $this->assertSame('MACZZZ (libellé à compléter par le CDG)', $nouveau->libelle);
        $this->assertFalse(CodeAnalytique::where('code', 'ZZZZZZ')->exists());   // hors liste : décision du CDG
        $this->assertStringContainsString('ZZZZZZ | Hors liste (utilisé en caisse) : Code « non affecté » : à interdire', $rapport);
    }

    /** Paramétrage › Caisses : plafond de caisse en double validation ; gestionnaire et réapprovisionnement tout de suite */
    public function test_l_ecran_parametrage_regle_la_matrice_de_la_caisse(): void
    {
        $gestionnaire = User::where('email', 'youssouf.toure@neemba.com')->first();

        $this->actingAs($this->administrateur)->put(route('parametrage.caisses.update', $this->especes), [
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry',
            'plafond_retrait' => 20000000, 'seuil_alerte' => 1000000, 'plafond_caisse' => 250000000,
            'gestionnaire_id' => $gestionnaire->id, 'encaissements_clients' => true, 'reapprovisionnement' => 'Virement bancaire — Banque',
        ])->assertSessionHasNoErrors();

        $especes = $this->especes->fresh();
        $this->assertNull($especes->plafond_caisse);
        $this->assertSame($gestionnaire->id, $especes->gestionnaire_id);
        $this->assertTrue($especes->encaissements_clients);
        $this->assertSame(1, ModificationEnAttente::where('champ', 'plafond_caisse')->where('statut', 'en_attente')->count());
    }

    /* ------------------------------------------------------------------ */

    private function importer(array $options = []): string
    {
        $this->artisan('referentiels:importer', $options + [
            'fichier' => $this->classeur(),
            '--appliquer' => true,
            '--auteur' => 'admin@neemba.com',
            '--rapport' => $this->rapport(),
        ])->assertSuccessful();

        return file_get_contents($this->rapport());
    }

    private function rapport(): string
    {
        return storage_path('framework/testing/rapport_referentiels.md');
    }

    /** Classeur au format du modèle Neemba (en-têtes en ligne 4, données à partir de la ligne 5) */
    private function classeur(): string
    {
        $onglets = [
            "Mode d'emploi" => [
                ['Onglet', 'Responsable proposé', 'Échéance', 'Cellules jaunes restantes'],
                ['1-Utilisateurs', 'Trésorerie + RH', '09/10/2026', '10'],
                ['4-Caisses', 'Trésorerie', '13/10/2026', '5'],
            ],
            '1-Utilisateurs' => [
                ['Nom', 'Prénom', 'Matricule', 'Email', 'Téléphone', 'Entité', 'Site de rattachement', 'Service', 'Fonction',
                    'Statut (Cadre / Non-cadre)', 'Responsable N+1', 'Rôle(s) dans la plateforme', 'Actif (O/N)', 'Commentaire'],
                ['EXEMPLE — DIALLO', 'Mamadou', '20999', 'prenom.nom@neemba.com', '', 'Neemba Guinée', 'Conakry', 'Technique', 'Technicien', 'Non-cadre', '', 'Demandeur', 'O', 'Ligne d\'exemple'],
                ['DIAKITE', 'Mohamed', '', 'mohamed.diakite@neemba.com', '', 'Neemba Guinée', 'Conakry', 'DAF', 'Directeur Administratif et Financier', '', '', 'Finance (visa DAF), Demandeur', 'O', ''],
                ['CISS', 'Mor', '', '', '', 'Neemba Guinée', 'Conakry', 'DAF', 'DAF adjoint', '', 'DIAKITE Mohamed', 'Finance (visa), Demandeur', 'O', ''],
                ['BAH', 'Mamadou Alpha', '', 'mamadou-alpha.bah@neemba.com', '', 'Neemba Guinée', 'Conakry', 'DAF', 'Chef comptable', '', 'DIAKITE Mohamed', 'Finance (visa), Demandeur', 'O', ''],
                ['BOIRO', 'Saliou', '', 'saliou.boiro@neemba.com', '+224 622 35 29 40', 'Neemba Guinée', 'Conakry', 'DAF', 'Contrôleur de gestion', 'Cadre', 'DIAKITE Mohamed', 'CDG, Demandeur', 'O', ''],
                ['TOURE', 'Youssouf', '', 'youssouf.toure@neemba.com', '', 'Neemba Guinée', 'Conakry', 'DAF', 'Assistant trésorier', '', '', 'Trésorerie, Caissier (à confirmer)', 'O', 'Caisse principale Conakry ?'],
                ['TOUNKARA', 'Raby', '', 'raby.tounkara@neemba.com', '', 'Neemba Guinée', 'Conakry', 'Technique', 'Responsable Atelier', '', '', 'Caissier — caisse Atelier, Demandeur', 'O', ''],
                ['BARRY', 'Souadou', '', 'souadou.barry@neemba.com', '', 'Neemba Guinée', 'Conakry', '(à confirmer)', '(à confirmer)', '', '', 'Demandeur', 'O', ''],
            ],
            '2-Codes analytiques' => [
                ['Radical analytique (6 caractères)', 'Statut', 'Code service (liste de référence CDG)', 'Libellé du code', 'Services réellement imputés',
                    'Sites observés', "Nb d'opérations", 'Natures', 'Actif (O/N)', 'Validé par le CDG (O/N)', 'Point à trancher / commentaire'],
                ['ADAZZZ', 'Liste de référence', '200', '', '200 (417)', 'Boké, Conakry', '435', 'Missions', '', '', ''],
                ['MACZZZ', 'Liste de référence', '200', '', '900 (304)', 'Sangarédi', '548', 'Missions', '', '', 'Référence 200, mais imputé en 900 : quel service retenir ?'],
                ['ZZZZZZ', 'Hors liste (utilisé en caisse)', '', '', '900 (60)', 'Boké', '67', 'Réceptions', '', '', 'Code « non affecté » : à interdire dans la plateforme ?'],
            ],
            '3-Services' => [
                ['Service (liste du CDG)', 'Code numérique comptable', 'Équivalent sur la fiche ODM', 'Chef de service à Conakry', 'Commentaire'],
                ['DAF', '', '', '', 'Équivalent ODM à indiquer'],
                ['Technique', '', 'SERVICE', 'TOUNKARA Raby (à confirmer)', 'Correspondance proposée par Addvalis : à confirmer'],
            ],
            '4-Caisses' => [
                ['Site', 'Code site', 'Entité', 'Caisse', 'Nature', 'Plafond de caisse (GNF)', 'Seuil de réapprovisionnement (GNF)', 'Plafond de retrait par bon (GNF)',
                    'Gestionnaire / caissier', 'Suppléant du caissier', 'Mode de réapprovisionnement', 'Caisse ou compte qui réapprovisionne',
                    'Destinataires du rapport journalier', 'Encaissements clients (O/N)', 'Commentaire'],
                ['Conakry', '01', 'Neemba Guinée', 'Caisse principale Conakry', 'Espèces', '250000000', '50000000', '20000000', '', '', 'Virement bancaire', 'Banque',
                    'DIAKITE Mohamed, BOIRO Saliou, CISS Mor', 'O', ''],
                ['Conakry', '01', 'Neemba Guinée', 'Caisse Atelier', 'Espèces', '15000000', '3000000', '1000000', 'TOUNKARA Raby', '', 'Avance fixe (retour au plafond)',
                    'Caisse principale Conakry (à confirmer)', '', 'N', ''],
                ['CORICA', '49', 'Neemba Guinée', 'Caisse CORICA', 'Espèces', '5000000', '1000000', '1000000', '', '', '', '', '', 'N', 'CORICA = Kouroussa'],
                ['Simandou / SIMFER', '(à confirmer)', 'Neemba Mining (à confirmer)', 'Caisse Simandou', 'Espèces', '(à confirmer)', '', '1000000', '', '', '', '', '', 'N', 'Existe-t-elle ?'],
            ],
            '5-Valideurs' => [
                ['Site', 'Service', 'Niveau', 'Titulaire', 'Suppléant 1', 'Suppléant 2', 'Commentaire'],
                ['Tous', 'Tous', 'Contrôle de gestion (CDG)', 'BOIRO Saliou', 'TOURE Youssouf', '', ''],
                ['Tous', 'Tous', 'Finance (visa)', 'DIAKITE Mohamed (DAF)', 'CISS Mor (DAF adjoint)', 'BAH Mamadou Alpha (chef comptable)', 'Un seul des trois suffit'],
                ['Tous', 'Tous', 'Trésorerie (réapprovisionnements, arrêtés)', 'TOURE Youssouf', '', '', ''],
                ['Tous', 'Tous', 'Contrôle de gestion (CDG)', 'TOURE Youssouf', '', '', 'Ligne d\'essai : rôle à privilèges sur un compte existant'],
            ],
        ];

        $classeur = new Spreadsheet();
        $classeur->removeSheetByIndex(0);
        foreach ($onglets as $titre => $lignes) {
            $feuille = $classeur->createSheet();
            $feuille->setTitle(mb_substr($titre, 0, 31));
            $feuille->setCellValue('A1', "Point — {$titre}");
            $feuille->setCellValue('A2', 'Description');
            foreach ($lignes as $index => $ligne) {
                foreach (array_values($ligne) as $colonne => $valeur) {
                    $feuille->setCellValueExplicit([$colonne + 1, $index + 4], $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        $chemin = storage_path('framework/testing/referentiels_essai.xlsx');
        @mkdir(dirname($chemin), 0777, true);
        (new Xlsx($classeur))->save($chemin);

        return $chemin;
    }
}
