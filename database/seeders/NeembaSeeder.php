<?php

namespace Database\Seeders;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\Delegation;
use App\Models\HistoriqueAction;
use App\Models\MouvementCaisse;
use App\Models\RapportCaisse;
use App\Models\Service;
use App\Models\Site;
use App\Models\TypeDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder NEEMBA - Données réelles de référence
 * 
 * Initialise les tables de paramétrage avec les données exactes
 * fournies par NEEMBA (sites, services, codes analytiques)
 * et crée des utilisateurs/bons de test pour le workflow.
 */
class NeembaSeeder extends Seeder
{
    public function run(): void
    {
        /* ================================================================
         * 0. TABLES DE PARAMÉTRAGE — DONNÉES RÉELLES NEEMBA
         * ================================================================ */

        /* ─── Sites NEEMBA (avec code site officiel) ─────────────── */
        $sitesData = [
            ['code' => '01', 'nom' => 'Conakry',   'ville' => 'Conakry',   'solde_caisse' => 15000000, 'plafond_caisse' => 50000000, 'seuil_minimum_caisse' => 2000000],
            ['code' => '31', 'nom' => 'Boke',      'ville' => 'Boké',      'solde_caisse' => 8000000,  'plafond_caisse' => 30000000, 'seuil_minimum_caisse' => 1000000],
            ['code' => '11', 'nom' => 'Sangaredi',  'ville' => 'Sangarédi', 'solde_caisse' => 5000000,  'plafond_caisse' => 20000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '81', 'nom' => 'Siguiri',    'ville' => 'Siguiri',   'solde_caisse' => 3000000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '49', 'nom' => 'Kouroussa',  'ville' => 'Kouroussa', 'solde_caisse' => 4000000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '39', 'nom' => 'Mandiana',   'ville' => 'Mandiana',  'solde_caisse' => 3500000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
        ];
        foreach ($sitesData as $s) {
            Site::create($s);
        }

        /* ─── Services NEEMBA (avec code numérique) ──────────────── */
        $servicesData = [
            ['nom' => 'Direction',            'code' => '900'],
            ['nom' => 'DAF',                  'code' => '900'],
            ['nom' => 'Ressources Humaines',  'code' => '900'],
            ['nom' => 'Moyens Généraux',      'code' => '900'],
            ['nom' => 'Aftermarket',          'code' => '200'],
            ['nom' => 'Technique',            'code' => '300'],
            ['nom' => 'PSSR',                 'code' => '200'],
            ['nom' => 'Location',             'code' => '400'],
            ['nom' => 'Logistique',           'code' => '100'],
            ['nom' => 'Informatique',         'code' => '600'],
        ];
        foreach ($servicesData as $s) {
            Service::create($s);
        }

        /* ─── Codes Analytiques NEEMBA (22 comptes réels) ────────── */
        $codesData = [
            ['code' => 'ADAZZZ', 'libelle' => 'Administration ADA',       'code_service' => '200', 'business_unit' => 'Aftermarket'],
            ['code' => 'ADVZZZ', 'libelle' => 'Administration ADV',       'code_service' => '100', 'business_unit' => 'Logistique'],
            ['code' => 'CCFZZZ', 'libelle' => 'Comptabilité CCF',        'code_service' => '100', 'business_unit' => 'Logistique'],
            ['code' => 'CGEZZZ', 'libelle' => 'Contrôle de Gestion CGE', 'code_service' => '100', 'business_unit' => 'Logistique'],
            ['code' => 'DAFREG', 'libelle' => 'DAF Régional',            'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'DAFZZZ', 'libelle' => 'DAF Central',             'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'DEPZZZ', 'libelle' => 'Dépenses DEP',            'code_service' => '200', 'business_unit' => 'Aftermarket'],
            ['code' => 'DIRZZZ', 'libelle' => 'Direction DIR',           'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'DRHZZZ', 'libelle' => 'DRH',                     'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'FLXZZZ', 'libelle' => 'Flux FLX',                'code_service' => '300', 'business_unit' => 'Technique'],
            ['code' => 'IGEZZZ', 'libelle' => 'Ingénierie IGE',          'code_service' => '200', 'business_unit' => 'Aftermarket'],
            ['code' => 'INFREG', 'libelle' => 'Informatique Régional',   'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'INFZZZ', 'libelle' => 'Informatique INF',        'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'LABZZZ', 'libelle' => 'Laboratoire LAB',         'code_service' => '600', 'business_unit' => 'Informatique'],
            ['code' => 'LOCZZZ', 'libelle' => 'Location LOC',            'code_service' => '400', 'business_unit' => 'Location'],
            ['code' => 'MACZZZ', 'libelle' => 'Machines MAC',            'code_service' => '200', 'business_unit' => 'Aftermarket'],
            ['code' => 'MKTZZZ', 'libelle' => 'Marketing MKT',          'code_service' => '100', 'business_unit' => 'Logistique'],
            ['code' => 'MMICBS', 'libelle' => 'MMI CBS',                 'code_service' => '200', 'business_unit' => 'Aftermarket'],
            ['code' => 'MPRZZZ', 'libelle' => 'Matériel/Pièces MPR',    'code_service' => '300', 'business_unit' => 'Technique'],
            ['code' => 'SGNZZZ', 'libelle' => 'Signalisation SGN',      'code_service' => '900', 'business_unit' => 'Direction'],
            ['code' => 'SMIZZZ', 'libelle' => 'SMI',                     'code_service' => '300', 'business_unit' => 'Technique'],
            ['code' => 'SMOZZZ', 'libelle' => 'SMO',                     'code_service' => '300', 'business_unit' => 'Technique'],
        ];
        foreach ($codesData as $c) {
            $service = Service::where('nom', $c['business_unit'])->first();
            CodeAnalytique::create([
                'code' => $c['code'],
                'libelle' => $c['libelle'],
                'service_id' => $service ? $service->id : null,
            ]);
        }

        /* ─── Types de document ──────────────────────────────────── */
        foreach (['Facture', 'Devis', 'Bon de commande', 'Bon de livraison', 'Reçu', 'Contrat', 'Ordre de mission', 'Justificatif', 'Autre'] as $type) {
            TypeDocument::create(['nom' => $type]);
        }

        /* ================================================================
         * 1. UTILISATEURS DE TEST
         * ================================================================ */

        /* Demandeur */
        $demandeur = User::create([
            'name' => 'DIALLO',
            'prenom' => 'Mamadou',
            'email' => 'demandeur@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-001',
            'telephone' => '+224 622 11 11 11',
            'role' => 'demandeur',
            'service' => 'Informatique',
            'site' => 'Conakry',
            'poste' => 'Développeur',
            'actif' => true,
        ]);

        /* Responsable Service */
        $chef = User::create([
            'name' => 'BAH',
            'prenom' => 'Thierno',
            'email' => 'chef@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-002',
            'telephone' => '+224 622 22 22 22',
            'role' => 'responsable_service',
            'service' => 'Informatique',
            'site' => 'Conakry',
            'poste' => 'Chef de Service IT',
            'actif' => true,
        ]);

        /* Contrôle de Gestion */
        $cdg = User::create([
            'name' => 'CAMARA',
            'prenom' => 'Fatoumata',
            'email' => 'cdg@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-003',
            'telephone' => '+224 622 33 33 33',
            'role' => 'controle_gestion',
            'service' => 'DAF',
            'site' => 'Conakry',
            'poste' => 'Contrôleur de Gestion',
            'actif' => true,
        ]);

        /* DAF */
        $daf = User::create([
            'name' => 'SOUMAH',
            'prenom' => 'Mohamed',
            'email' => 'daf@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-004',
            'telephone' => '+224 622 44 44 44',
            'role' => 'daf',
            'service' => 'DAF',
            'site' => 'Conakry',
            'poste' => 'Directeur Administratif et Financier',
            'actif' => true,
        ]);

        /* Directeur Pays */
        $dp = User::create([
            'name' => 'CONDE',
            'prenom' => 'Alpha',
            'email' => 'dp@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-005',
            'telephone' => '+224 622 55 55 55',
            'role' => 'directeur_pays',
            'service' => 'Direction',
            'site' => 'Conakry',
            'poste' => 'Directeur Pays',
            'actif' => true,
        ]);

        /* Caissier */
        $caissier = User::create([
            'name' => 'SYLLA',
            'prenom' => 'Aissatou',
            'email' => 'caissier@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-006',
            'telephone' => '+224 622 66 66 66',
            'role' => 'caissier',
            'service' => 'DAF',
            'site' => 'Conakry',
            'poste' => 'Caissière Principale',
            'actif' => true,
        ]);

        /* Administrateur */
        $admin = User::create([
            'name' => 'TOURE',
            'prenom' => 'Ibrahim',
            'email' => 'admin@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-008',
            'telephone' => '+224 622 88 88 88',
            'role' => 'administrateur',
            'service' => 'Direction',
            'site' => 'Conakry',
            'poste' => 'Administrateur Système',
            'actif' => true,
        ]);

        /* Deuxième demandeur */
        $demandeur2 = User::create([
            'name' => 'BARRY',
            'prenom' => 'Ousmane',
            'email' => 'demandeur2@neemba.com',
            'password' => Hash::make('password'),
            'matricule' => 'NMB-007',
            'telephone' => '+224 622 77 77 77',
            'role' => 'demandeur',
            'service' => 'Logistique',
            'site' => 'Conakry',
            'poste' => 'Assistant Logistique',
            'actif' => true,
        ]);

        /* ================================================================
         * 2. BONS DE CAISSE DE TEST
         * ================================================================ */

        /* Bon 1 : Brouillon */
        $bon1 = BonCaisse::create([
            'numero' => 'BC-2026-0001',
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Informatique',
            'beneficiaire' => 'Mamadou DIALLO',
            'type_beneficiaire' => 'employe',
            'telephone_beneficiaire' => '+224 622 11 11 11',
            'mode_paiement' => 'especes',
            'motif' => 'Achat de fournitures informatiques (câbles réseau, adaptateurs USB)',
            'categorie_depense' => 'fournitures_bureau',
            'montant' => 2500000,
            'montant_lettres' => 'Deux millions cinq cent mille francs guinéens',
            'devise' => 'GNF',
            'statut' => 'BROUILLON',
            'demandeur_id' => $demandeur->id,
            'date_demande' => now()->subDays(3)->toDateString(),
        ]);

        HistoriqueAction::enregistrer($bon1, HistoriqueAction::ACTION_CREATION, null, 'BROUILLON', $demandeur->id, 'Création du bon de caisse');

        /* Bon 2 : En attente chef service */
        $bon2 = BonCaisse::create([
            'numero' => 'BC-2026-0002',
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Informatique',
            'beneficiaire' => 'Fournisseur TECH GUINEE',
            'type_beneficiaire' => 'fournisseur',
            'telephone_beneficiaire' => '+224 628 00 00 00',
            'mode_paiement' => 'virement',
            'motif' => 'Maintenance préventive des serveurs et équipements réseau du site de Conakry',
            'categorie_depense' => 'entretien_reparation',
            'montant' => 3500000,
            'montant_lettres' => 'Trois millions cinq cent mille francs guinéens',
            'devise' => 'GNF',
            'statut' => 'EN_ATTENTE_CHEF_SERVICE',
            'demandeur_id' => $demandeur->id,
            'date_demande' => now()->subDays(2)->toDateString(),
            'date_soumission' => now()->subDays(2),
        ]);

        /* Créer les étapes de validation pour le bon 2 */
        $bon2->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'en_attente'],
            ['niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente'],
            ['niveau' => 3, 'role' => 'daf', 'statut' => 'en_attente'],
        ]);

        /* Bon 3 : En attente CDG (chef a déjà validé) */
        $bon3 = BonCaisse::create([
            'numero' => 'BC-2026-0003',
            'type_bon' => 'BP',
            'site' => 'Conakry',
            'service' => 'Logistique',
            'beneficiaire' => 'Ousmane BARRY',
            'type_beneficiaire' => 'employe',
            'telephone_beneficiaire' => '+224 622 77 77 77',
            'mode_paiement' => 'especes',
            'motif' => 'Avance sur frais de déplacement pour mission de supervision à Kouroussa',
            'categorie_depense' => 'frais_mission',
            'montant' => 4000000,
            'montant_lettres' => 'Quatre millions de francs guinéens',
            'devise' => 'GNF',
            'statut' => 'EN_ATTENTE_CDG',
            'demandeur_id' => $demandeur2->id,
            'date_demande' => now()->subDays(1)->toDateString(),
            'date_soumission' => now()->subDays(1),
        ]);

        $bon3->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'commentaire' => 'Mission approuvée.', 'date_validation' => now()->subHours(12)],
            ['niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'en_attente'],
            ['niveau' => 3, 'role' => 'daf', 'statut' => 'en_attente'],
        ]);

        /* Bon 4 : Approuvé (montant > 5M donc DP a validé aussi) */
        $bon4 = BonCaisse::create([
            'numero' => 'BC-2026-0004',
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Direction',
            'beneficiaire' => 'Fournisseur MOBILIER PRO',
            'type_beneficiaire' => 'fournisseur',
            'telephone_beneficiaire' => '+224 625 99 99 99',
            'mode_paiement' => 'virement',
            'motif' => 'Achat de mobilier de bureau pour le nouveau local administratif',
            'categorie_depense' => 'achat_materiel',
            'montant' => 8000000,
            'montant_lettres' => 'Huit millions de francs guinéens',
            'devise' => 'GNF',
            'statut' => 'APPROUVE',
            'demandeur_id' => $demandeur->id,
            'date_demande' => now()->subDays(5)->toDateString(),
            'date_soumission' => now()->subDays(5),
        ]);

        $bon4->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(4)],
            ['niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'approuve', 'validateur_id' => $cdg->id, 'date_validation' => now()->subDays(3)],
            ['niveau' => 3, 'role' => 'daf', 'statut' => 'approuve', 'validateur_id' => $daf->id, 'date_validation' => now()->subDays(2)],
            ['niveau' => 4, 'role' => 'directeur_pays', 'statut' => 'approuve', 'validateur_id' => $dp->id, 'commentaire' => 'Approuvé, priorité haute.', 'date_validation' => now()->subDays(1)],
        ]);

        /* Bon 5 : Payé */
        BonCaisse::create([
            'numero' => 'BC-2026-0005',
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Informatique',
            'beneficiaire' => 'Mamadou DIALLO',
            'type_beneficiaire' => 'employe',
            'telephone_beneficiaire' => '+224 622 11 11 11',
            'mode_paiement' => 'especes',
            'motif' => 'Renouvellement de licence logicielle annuelle',
            'categorie_depense' => 'achat_materiel',
            'montant' => 1500000,
            'devise' => 'GNF',
            'statut' => 'PAYE',
            'demandeur_id' => $demandeur->id,
            'caissier_id' => $caissier->id,
            'mode_paiement_effectif' => 'especes',
            'date_demande' => now()->subDays(10)->toDateString(),
            'date_soumission' => now()->subDays(10),
            'date_paiement' => now()->subDays(8),
        ]);

        /* Bon 6 : Rejeté */
        $bon6 = BonCaisse::create([
            'numero' => 'BC-2026-0006',
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Logistique',
            'beneficiaire' => 'Ousmane BARRY',
            'type_beneficiaire' => 'employe',
            'telephone_beneficiaire' => '+224 622 77 77 77',
            'mode_paiement' => 'especes',
            'motif' => 'Demande non justifiée insuffisamment détaillée',
            'categorie_depense' => 'autre',
            'montant' => 15000000,
            'devise' => 'GNF',
            'statut' => 'REJETE',
            'commentaire_rejet' => 'Motif trop vague, merci de détailler la dépense avec les devis correspondants.',
            'demandeur_id' => $demandeur2->id,
            'date_demande' => now()->subDays(7)->toDateString(),
            'date_soumission' => now()->subDays(7),
        ]);

        $bon6->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(6)],
            ['niveau' => 2, 'role' => 'controle_gestion', 'statut' => 'rejete', 'validateur_id' => $cdg->id, 'commentaire' => 'Motif trop vague, merci de détailler la dépense avec les devis correspondants.', 'date_validation' => now()->subDays(5)],
        ]);

        /* ================================================================
         * 3. RAPPORT DE CAISSE DE TEST
         * ================================================================ */

        RapportCaisse::create([
            'date_rapport' => now()->subDays(1)->toDateString(),
            'site' => 'Conakry',
            'solde_ouverture' => 50000000,
            'total_entrees' => 10000000,
            'total_sorties' => 1500000,
            'solde_cloture' => 58500000,
            'observations' => 'Journée normale. Un paiement de licence logicielle effectué.',
            'caissier_id' => $caissier->id,
            'cloture' => true,
        ]);

        RapportCaisse::create([
            'date_rapport' => now()->toDateString(),
            'site' => 'Conakry',
            'solde_ouverture' => 58500000,
            'total_entrees' => 0,
            'total_sorties' => 0,
            'solde_cloture' => 58500000,
            'observations' => '',
            'caissier_id' => $caissier->id,
            'cloture' => false,
        ]);

        /* ================================================================
         * 4. BON PROVISOIRE EN ATTENTE DE RÉGULARISATION
         * ================================================================ */

        BonCaisse::create([
            'numero' => 'BC-2026-0007',
            'type_bon' => 'BP',
            'site' => 'Conakry',
            'service' => 'Logistique',
            'beneficiaire' => 'Ousmane BARRY',
            'type_beneficiaire' => 'employe',
            'telephone_beneficiaire' => '+224 622 77 77 77',
            'mode_paiement' => 'especes',
            'motif' => 'Avance carburant pour livraisons urgentes vers Kouroussa et Mandiana',
            'categorie_depense' => 'carburant',
            'montant' => 2000000,
            'montant_lettres' => 'Deux millions de francs guinéens',
            'devise' => 'GNF',
            'statut' => 'EN_ATTENTE_REGULARISATION',
            'demandeur_id' => $demandeur2->id,
            'caissier_id' => $caissier->id,
            'mode_paiement_effectif' => 'especes',
            'date_demande' => now()->subDays(6)->toDateString(),
            'date_soumission' => now()->subDays(6),
            'date_paiement' => now()->subDays(4),
            'date_limite_regularisation' => now()->subDays(2)->toDateString(),
        ]);

        /* ================================================================
         * 5. DÉLÉGATION DE POUVOIRS (exemple)
         * ================================================================ */

        Delegation::create([
            'delegant_id' => $chef->id,
            'delegue_id' => $cdg->id,
            'date_debut' => now()->addDays(5),
            'date_fin' => now()->addDays(12),
            'motif' => 'Congé annuel — délégation temporaire des validations de service IT',
            'statut' => 'en_attente',
        ]);

        /* ================================================================
         * 6. MOUVEMENT DE CAISSE (approvisionnement exemple)
         * ================================================================ */

        MouvementCaisse::create([
            'reference' => MouvementCaisse::genererReference(),
            'type' => 'approvisionnement',
            'montant' => 10000000,
            'motif' => 'Approvisionnement mensuel caisse Conakry — transfert trésorerie',
            'site' => 'Conakry',
            'statut' => 'valide',
            'effectue_par' => $caissier->id,
            'valide_par' => $daf->id,
            'date_mouvement' => now()->subDays(3),
            'date_validation' => now()->subDays(3),
            'commentaire_validation' => 'Approvisionnement mensuel approuvé.',
        ]);

        MouvementCaisse::create([
            'reference' => MouvementCaisse::genererReference(),
            'type' => 'approvisionnement',
            'montant' => 5000000,
            'motif' => 'Approvisionnement complémentaire caisse Boke',
            'site' => 'Boke',
            'statut' => 'en_attente',
            'effectue_par' => $caissier->id,
            'date_mouvement' => now()->subDay(),
        ]);

        $this->command->info('✓ Données NEEMBA de référence créées avec succès !');
        $this->command->info('');
        $this->command->info('Sites : Conakry (01), Boke (31), Sangaredi (11), Siguiri (81), Kouroussa (49), Mandiana (39)');
        $this->command->info('Services : Direction, DAF, RH, Aftermarket, Technique, PSSR, Location, Logistique, Moyens Généraux, Informatique');
        $this->command->info('Codes analytiques : 22 comptes NEEMBA réels');
        $this->command->info('');
        $this->command->info('Comptes de test (mot de passe: password) :');
        $this->command->info('  - Demandeur     : demandeur@neemba.com');
        $this->command->info('  - Chef Service  : chef@neemba.com');
        $this->command->info('  - CDG           : cdg@neemba.com');
        $this->command->info('  - DAF           : daf@neemba.com');
        $this->command->info('  - Dir. Pays     : dp@neemba.com');
        $this->command->info('  - Caissier      : caissier@neemba.com');
        $this->command->info('  - Administrateur: admin@neemba.com');
    }
}
