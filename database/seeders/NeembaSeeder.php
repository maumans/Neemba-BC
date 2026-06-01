<?php

namespace Database\Seeders;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\Service;
use App\Models\Site;
use App\Models\TypeDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder NEEMBA — Données pilote Conakry
 *
 * Utilisateurs réels, seuil DP = 1 500 000 GNF, caisse Conakry 15 M GNF.
 * Bons pré-positionnés pour couvrir les scénarios S03 → S11 du plan de test.
 * Mot de passe commun : Neemba@2026
 */
class NeembaSeeder extends Seeder
{
    public function run(): void
    {
        /* ================================================================
         * 0. PARAMÈTRES PILOTE
         * Mis à jour après la migration (valeurs par défaut overridées)
         * ================================================================ */

        // Seuil DG Pays client = 1 500 000 GNF (scénario S08 du plan de test)
        DB::table('parametres')->where('cle', 'seuil_validation_dp')->update(['valeur' => '1500000']);
        // Seuil d'alerte caisse minimum Conakry
        DB::table('parametres')->where('cle', 'seuil_minimum_caisse')->update(['valeur' => '1000000']);

        /* ================================================================
         * 1. SITES NEEMBA
         * Site Conakry configuré pour le pilote : solde 15 M, seuil 1 M
         * S11 : bon de 16 M sera bloqué car > solde 15 M
         * ================================================================ */

        $sitesData = [
            ['code' => '01', 'nom' => 'Conakry',   'ville' => 'Conakry',   'solde_caisse' => 15000000, 'plafond_caisse' => 50000000, 'seuil_minimum_caisse' => 1000000],
            ['code' => '31', 'nom' => 'Boke',       'ville' => 'Boké',      'solde_caisse' => 8000000,  'plafond_caisse' => 30000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '11', 'nom' => 'Sangaredi',  'ville' => 'Sangarédi', 'solde_caisse' => 5000000,  'plafond_caisse' => 20000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '81', 'nom' => 'Siguiri',    'ville' => 'Siguiri',   'solde_caisse' => 3000000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '49', 'nom' => 'Kouroussa',  'ville' => 'Kouroussa', 'solde_caisse' => 4000000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
            ['code' => '39', 'nom' => 'Mandiana',   'ville' => 'Mandiana',  'solde_caisse' => 3500000,  'plafond_caisse' => 15000000, 'seuil_minimum_caisse' => 500000],
        ];
        foreach ($sitesData as $s) {
            Site::create($s);
        }

        /* ================================================================
         * 2. SERVICES NEEMBA
         * ================================================================ */

        $servicesData = [
            ['nom' => 'Direction',           'code' => '900'],
            ['nom' => 'DAF',                 'code' => '900'],
            ['nom' => 'Ressources Humaines', 'code' => '900'],
            ['nom' => 'Moyens Généraux',     'code' => '900'],
            ['nom' => 'Aftermarket',         'code' => '200'],
            ['nom' => 'Technique',           'code' => '300'],
            ['nom' => 'PSSR',                'code' => '200'],
            ['nom' => 'Location',            'code' => '400'],
            ['nom' => 'Logistique',          'code' => '100'],
            ['nom' => 'Informatique',        'code' => '600'],
        ];
        foreach ($servicesData as $s) {
            Service::create($s);
        }

        /* ================================================================
         * 3. CODES ANALYTIQUES NEEMBA (22 comptes réels)
         * ================================================================ */

        $codesData = [
            ['code' => 'ADAZZZ', 'libelle' => 'Administration ADA',       'business_unit' => 'Aftermarket'],
            ['code' => 'ADVZZZ', 'libelle' => 'Administration ADV',       'business_unit' => 'Logistique'],
            ['code' => 'CCFZZZ', 'libelle' => 'Comptabilité CCF',         'business_unit' => 'Logistique'],
            ['code' => 'CGEZZZ', 'libelle' => 'Contrôle de Gestion CGE',  'business_unit' => 'Logistique'],
            ['code' => 'DAFREG', 'libelle' => 'DAF Régional',             'business_unit' => 'Direction'],
            ['code' => 'DAFZZZ', 'libelle' => 'DAF Central',              'business_unit' => 'Direction'],
            ['code' => 'DEPZZZ', 'libelle' => 'Dépenses DEP',             'business_unit' => 'Aftermarket'],
            ['code' => 'DIRZZZ', 'libelle' => 'Direction DIR',            'business_unit' => 'Direction'],
            ['code' => 'DRHZZZ', 'libelle' => 'DRH',                      'business_unit' => 'Direction'],
            ['code' => 'FLXZZZ', 'libelle' => 'Flux FLX',                 'business_unit' => 'Technique'],
            ['code' => 'IGEZZZ', 'libelle' => 'Ingénierie IGE',           'business_unit' => 'Aftermarket'],
            ['code' => 'INFREG', 'libelle' => 'Informatique Régional',    'business_unit' => 'Direction'],
            ['code' => 'INFZZZ', 'libelle' => 'Informatique INF',         'business_unit' => 'Direction'],
            ['code' => 'LABZZZ', 'libelle' => 'Laboratoire LAB',          'business_unit' => 'Informatique'],
            ['code' => 'LOCZZZ', 'libelle' => 'Location LOC',             'business_unit' => 'Location'],
            ['code' => 'MACZZZ', 'libelle' => 'Machines MAC',             'business_unit' => 'Aftermarket'],
            ['code' => 'MKTZZZ', 'libelle' => 'Marketing MKT',            'business_unit' => 'Logistique'],
            ['code' => 'MMICBS', 'libelle' => 'MMI CBS',                  'business_unit' => 'Aftermarket'],
            ['code' => 'MPRZZZ', 'libelle' => 'Matériel/Pièces MPR',     'business_unit' => 'Technique'],
            ['code' => 'SGNZZZ', 'libelle' => 'Signalisation SGN',        'business_unit' => 'Direction'],
            ['code' => 'SMIZZZ', 'libelle' => 'SMI',                      'business_unit' => 'Technique'],
            ['code' => 'SMOZZZ', 'libelle' => 'SMO',                      'business_unit' => 'Technique'],
        ];
        foreach ($codesData as $c) {
            $service = Service::where('nom', $c['business_unit'])->first();
            CodeAnalytique::create([
                'code'       => $c['code'],
                'libelle'    => $c['libelle'],
                'service_id' => $service?->id,
            ]);
        }

        /* ================================================================
         * 4. TYPES DE DOCUMENTS
         * ================================================================ */

        foreach (['Facture', 'Devis', 'Bon de commande', 'Bon de livraison', 'Reçu', 'Contrat', 'Ordre de mission', 'Justificatif', 'Autre'] as $type) {
            TypeDocument::create(['nom' => $type]);
        }

        /* ================================================================
         * 5. UTILISATEURS PILOTE RÉELS
         * Source : plan de test test.md + image contacts WhatsApp
         * Service "Aftermarket" partagé entre demandeur et chef de service
         * ================================================================ */

        /* S01-S05-S09-S11 : Demandeur */
        $demandeur = User::create([
            'name'       => 'BARRY',
            'prenom'     => 'Saoudou',
            'email'      => 'saoudou.barry@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P001',
            'telephone'  => '622461261',
            'role'       => 'demandeur',
            'service'    => 'Aftermarket',
            'site'       => 'Conakry',
            'poste'      => 'Chargé Aftermarket',
            'actif'      => true,
        ]);

        /* S01-S02-S03-S04 : Chef de service (même service que demandeur) */
        $chef = User::create([
            'name'       => 'GOMIS',
            'prenom'     => 'Thierry Antoine',
            'email'      => 'thierry.gomis@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P002',
            'telephone'  => '627471735',
            'role'       => 'responsable_service',
            'service'    => 'Aftermarket',
            'site'       => 'Conakry',
            'poste'      => 'Chef de Service Aftermarket',
            'actif'      => true,
        ]);

        /* S03-S04-S10 : Contrôle de Gestion */
        $cdg = User::create([
            'name'       => 'BARRY',
            'prenom'     => 'Maïmouna Sonna',
            'email'      => 'maimouna.barry@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P003',
            'telephone'  => '627261871',
            'role'       => 'controle_gestion',
            'service'    => 'DAF',
            'site'       => 'Conakry',
            'poste'      => 'Contrôleur de Gestion',
            'actif'      => true,
        ]);

        /* S07-S08-S09 : DAF */
        $daf = User::create([
            'name'       => 'DIAKITE',
            'prenom'     => 'Mohamed',
            'email'      => 'mohamed.diakite@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P004',
            'telephone'  => '629000769',
            'role'       => 'daf',
            'service'    => 'DAF',
            'site'       => 'Conakry',
            'poste'      => 'Directeur Administratif et Financier',
            'actif'      => true,
        ]);

        /* S07-S08-S11 : DG Pays */
        $dp = User::create([
            'name'       => 'LO',
            'prenom'     => 'Mamadou',
            'email'      => 'mamadou.lo@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P005',
            'telephone'  => '612007272',
            'role'       => 'directeur_pays',
            'service'    => 'Direction',
            'site'       => 'Conakry',
            'poste'      => 'Directeur Général Pays',
            'actif'      => true,
        ]);

        /* S06-S09-S11 : Caissier */
        $caissier = User::create([
            'name'       => 'TOURE',
            'prenom'     => 'Youssouf',
            'email'      => 'youssouf.toure@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'NMB-P006',
            'telephone'  => '623072484',
            'role'       => 'caissier',
            'service'    => 'DAF',
            'site'       => 'Conakry',
            'poste'      => 'Caissier Principal',
            'actif'      => true,
        ]);

        /* Admin Addvalis (référent technique) */
        User::create([
            'name'       => 'DIALLO',
            'prenom'     => 'Thierno',
            'email'      => 'thierno.diallo@addvalis.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'ADV-001',
            'telephone'  => '',
            'role'       => 'administrateur',
            'service'    => 'Direction',
            'site'       => 'Conakry',
            'poste'      => 'Lead Consultant Addvalis',
            'actif'      => true,
        ]);

        /* ================================================================
         * 6. BONS DE CAISSE PRÉ-POSITIONNÉS
         *
         * S01/S02 → créés en direct par Saoudou pendant le test
         * S10     → délégation créée via l'UI pendant le test
         *
         * Seuil DG Pays = 1 500 000 GNF
         *   < 1 500 000 : niveaux 1 (Chef) + 2 (CDG) + 3 (DAF) = 3 niveaux
         *   ≥ 1 500 000 : niveaux 1 + 2 + 3 + 4 (DP)           = 4 niveaux
         * ================================================================ */

        /* ── S03 : Code analytique erroné → EN_ATTENTE_CDG ──────────── */
        // Chef GOMIS a déjà approuvé. CDG BARRY doit corriger le code puis approuver.
        $bonS03 = BonCaisse::create([
            'numero'               => 'BC-2026-0001',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'DAFZZZ', // code erroné — CDG devra corriger en ADAZZZ
            'beneficiaire'         => 'GUINEE FOURNITURES SARL',
            'type_beneficiaire'    => 'fournisseur',
            'telephone_beneficiaire' => '622000001',
            'mode_paiement'        => 'especes',
            'motif'                => 'Achat de consommables et fournitures pour la flotte Aftermarket (filtres, huiles, courroies)',
            'categorie_depense'    => 'fournitures_bureau',
            'montant'              => 450000,
            'montant_lettres'      => 'Quatre cent cinquante mille francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'EN_ATTENTE_CDG',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDays(2)->toDateString(),
            'date_soumission'      => now()->subDays(2),
        ]);

        $bonS03->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve',   'validateur_id' => $chef->id, 'commentaire' => 'Achat validé.', 'date_validation' => now()->subDays(2)->addHours(3)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'en_attente'],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'en_attente'],
        ]);

        HistoriqueAction::enregistrer($bonS03, 'soumission', null, 'EN_ATTENTE_CHEF_SERVICE', $demandeur->id, 'Soumission du bon');
        HistoriqueAction::enregistrer($bonS03, 'validation_chef_service', 'EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', $chef->id, 'Approuvé par le chef de service');

        /* ── S04 : Bon incomplet → EN_ATTENTE_CHEF_SERVICE ─────────── */
        // GOMIS doit le rejeter avec un motif explicite.
        $bonS04 = BonCaisse::create([
            'numero'               => 'BC-2026-0002',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'ADAZZZ',
            'beneficiaire'         => 'Saoudou BARRY',
            'type_beneficiaire'    => 'employe',
            'telephone_beneficiaire' => '622461261',
            'mode_paiement'        => 'especes',
            'motif'                => 'Divers dépenses opérationnelles',
            'categorie_depense'    => 'autre',
            'montant'              => 320000,
            'montant_lettres'      => 'Trois cent vingt mille francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'EN_ATTENTE_CHEF_SERVICE',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDay()->toDateString(),
            'date_soumission'      => now()->subDay(),
        ]);

        $bonS04->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'en_attente'],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'en_attente'],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'en_attente'],
        ]);

        HistoriqueAction::enregistrer($bonS04, 'soumission', null, 'EN_ATTENTE_CHEF_SERVICE', $demandeur->id, 'Soumission du bon');

        /* ── S05 : Bon Provisoire payé → à régulariser ──────────────── */
        // Deadline dans 2 jours — Saoudou doit régulariser pendant le test.
        $bonS05 = BonCaisse::create([
            'numero'                   => 'BC-2026-0003',
            'type_bon'                 => 'BP',
            'site'                     => 'Conakry',
            'service'                  => 'Aftermarket',
            'code_analytique'          => 'ADAZZZ',
            'beneficiaire'             => 'Saoudou BARRY',
            'type_beneficiaire'        => 'employe',
            'telephone_beneficiaire'   => '622461261',
            'mode_paiement'            => 'especes',
            'mode_paiement_effectif'   => 'especes',
            'motif'                    => 'Avance sur frais de déplacement pour visite client à Sangarédi — régularisation avec justificatifs à retour',
            'categorie_depense'        => 'frais_mission',
            'montant'                  => 1200000,
            'montant_lettres'          => 'Un million deux cent mille francs guinéens',
            'devise'                   => 'GNF',
            'statut'                   => 'PAYE',
            'demandeur_id'             => $demandeur->id,
            'caissier_id'              => $caissier->id,
            'date_demande'             => now()->subDays(5)->toDateString(),
            'date_soumission'          => now()->subDays(5),
            'date_paiement'            => now()->subDays(4),
            'date_limite_regularisation' => now()->addDays(2)->toDateString(),
        ]);

        $bonS05->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id,     'date_validation' => now()->subDays(5)->addHours(2)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve', 'validateur_id' => $cdg->id,      'date_validation' => now()->subDays(4)->addHours(10)],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'approuve', 'validateur_id' => $daf->id,      'date_validation' => now()->subDays(4)->addHours(14)],
        ]);

        HistoriqueAction::enregistrer($bonS05, 'paiement', 'APPROUVE', 'PAYE', $caissier->id, 'Paiement effectué — avance mission Sangarédi');

        /* ── S06 : Bon approuvé → prêt pour paiement OTP ───────────── */
        // TOURE doit générer l'OTP, le faire communiquer par Saoudou, puis payer.
        $bonS06 = BonCaisse::create([
            'numero'               => 'BC-2026-0004',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'ADAZZZ',
            'beneficiaire'         => 'STATION TOTAL CONAKRY',
            'type_beneficiaire'    => 'fournisseur',
            'telephone_beneficiaire' => '622000002',
            'mode_paiement'        => 'especes',
            'motif'                => 'Approvisionnement carburant pour véhicules de service Aftermarket — bon de carburant mensuel',
            'categorie_depense'    => 'carburant',
            'montant'              => 750000,
            'montant_lettres'      => 'Sept cent cinquante mille francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'APPROUVE',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDays(3)->toDateString(),
            'date_soumission'      => now()->subDays(3),
        ]);

        $bonS06->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(3)->addHours(4)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve', 'validateur_id' => $cdg->id,  'date_validation' => now()->subDays(2)->addHours(9)],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'approuve', 'validateur_id' => $daf->id,  'date_validation' => now()->subDays(2)->addHours(14)],
        ]);

        HistoriqueAction::enregistrer($bonS06, 'validation_daf', 'EN_ATTENTE_DAF', 'APPROUVE', $daf->id, 'Approuvé — en attente de paiement OTP');

        /* ── S07 : Workflow 4 niveaux — 6 000 000 GNF → EN_ATTENTE_DAF */
        // Après approbation DAF → passera EN_ATTENTE_DP (6M ≥ 1,5M seuil DP).
        $bonS07 = BonCaisse::create([
            'numero'               => 'BC-2026-0005',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'MACZZZ',
            'beneficiaire'         => 'CAT EQUIPMENT GUINEE',
            'type_beneficiaire'    => 'fournisseur',
            'telephone_beneficiaire' => '629000100',
            'mode_paiement'        => 'virement',
            'motif'                => 'Acquisition de pièces de rechange pour maintenance préventive flotte Caterpillar — commande N° CAT-2026-0152',
            'categorie_depense'    => 'achat_materiel',
            'montant'              => 6000000,
            'montant_lettres'      => 'Six millions de francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'EN_ATTENTE_DAF',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDays(4)->toDateString(),
            'date_soumission'      => now()->subDays(4),
        ]);

        $bonS07->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve',   'validateur_id' => $chef->id, 'date_validation' => now()->subDays(4)->addHours(3)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve',   'validateur_id' => $cdg->id,  'date_validation' => now()->subDays(3)->addHours(11)],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'en_attente'],
            ['niveau' => 4, 'role' => 'directeur_pays',      'statut' => 'en_attente'],
        ]);

        HistoriqueAction::enregistrer($bonS07, 'validation_controle_gestion', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', $cdg->id, 'Approuvé par le CDG — code analytique MACZZZ confirmé');

        /* ── S08 : Seuil DG Pays — 2 000 000 GNF → EN_ATTENTE_DP ───── */
        // DAF a déjà approuvé. LO doit valider (2M ≥ 1,5M seuil DP client).
        $bonS08 = BonCaisse::create([
            'numero'               => 'BC-2026-0006',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'ADAZZZ',
            'beneficiaire'         => 'TECHNO SERVICES GUINEE',
            'type_beneficiaire'    => 'fournisseur',
            'telephone_beneficiaire' => '625000300',
            'mode_paiement'        => 'virement',
            'motif'                => 'Contrat de maintenance annuelle équipements de diagnostic — renouvellement contrat AMC-2026',
            'categorie_depense'    => 'entretien_reparation',
            'montant'              => 2000000,
            'montant_lettres'      => 'Deux millions de francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'EN_ATTENTE_DP',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDays(3)->toDateString(),
            'date_soumission'      => now()->subDays(3),
        ]);

        $bonS08->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(3)->addHours(2)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve', 'validateur_id' => $cdg->id,  'date_validation' => now()->subDays(2)->addHours(10)],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'approuve', 'validateur_id' => $daf->id,  'date_validation' => now()->subDays(2)->addHours(15)],
            ['niveau' => 4, 'role' => 'directeur_pays',      'statut' => 'en_attente'],
        ]);

        HistoriqueAction::enregistrer($bonS08, 'validation_daf', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP', $daf->id, 'Approuvé — montant ≥ 1 500 000 GNF, transmission au DG Pays');

        /* ── S09 : 3 bons payés aujourd'hui → rapport journalier ────── */
        // Ces bons apparaissent dans la vue temps réel de la journée courante.
        $bonsS09 = [
            ['num' => 'BC-2026-0007', 'benef' => 'SHELL STATION KALOUM',    'motif' => 'Carburant véhicules service semaine 18',                    'montant' => 480000,  'lettres' => 'Quatre cent quatre-vingt mille francs guinéens'],
            ['num' => 'BC-2026-0008', 'benef' => 'OFFICE PLUS CONAKRY',     'motif' => 'Achat fournitures bureau — cartouches imprimante et papier', 'montant' => 520000,  'lettres' => 'Cinq cent vingt mille francs guinéens'],
            ['num' => 'BC-2026-0009', 'benef' => 'TRANSPORT EXPRESS SARL',  'motif' => 'Frais de transport livraison pièces détachées',              'montant' => 350000,  'lettres' => 'Trois cent cinquante mille francs guinéens'],
        ];

        foreach ($bonsS09 as $b) {
            $bon = BonCaisse::create([
                'numero'                 => $b['num'],
                'type_bon'               => 'BD',
                'site'                   => 'Conakry',
                'service'                => 'Aftermarket',
                'code_analytique'        => 'ADAZZZ',
                'beneficiaire'           => $b['benef'],
                'type_beneficiaire'      => 'fournisseur',
                'telephone_beneficiaire' => '620000000',
                'mode_paiement'          => 'especes',
                'mode_paiement_effectif' => 'especes',
                'motif'                  => $b['motif'],
                'categorie_depense'      => 'carburant',
                'montant'                => $b['montant'],
                'montant_lettres'        => $b['lettres'],
                'devise'                 => 'GNF',
                'statut'                 => 'PAYE',
                'demandeur_id'           => $demandeur->id,
                'caissier_id'            => $caissier->id,
                'date_demande'           => now()->subDays(1)->toDateString(),
                'date_soumission'        => now()->subDays(1),
                'date_paiement'          => now(),
            ]);

            $bon->validations()->createMany([
                ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(1)->addHours(3)],
                ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve', 'validateur_id' => $cdg->id,  'date_validation' => now()->subDays(1)->addHours(8)],
                ['niveau' => 3, 'role' => 'daf',                 'statut' => 'approuve', 'validateur_id' => $daf->id,  'date_validation' => now()->subDays(1)->addHours(10)],
            ]);

            HistoriqueAction::enregistrer($bon, 'paiement', 'APPROUVE', 'PAYE', $caissier->id, 'Paiement OTP effectué');
        }

        /* ── S11 : Blocage solde — 16 000 000 GNF > solde 15 000 000 ─ */
        // APPROUVE mais non payable : caisse Conakry = 15 M, bon = 16 M.
        // TOURE tentera le paiement → message d'erreur + alerte solde.
        $bonS11 = BonCaisse::create([
            'numero'               => 'BC-2026-0010',
            'type_bon'             => 'BD',
            'site'                 => 'Conakry',
            'service'              => 'Aftermarket',
            'code_analytique'      => 'MACZZZ',
            'beneficiaire'         => 'CAT FINANCE SERVICES',
            'type_beneficiaire'    => 'fournisseur',
            'telephone_beneficiaire' => '629000200',
            'mode_paiement'        => 'virement',
            'motif'                => 'Règlement facture acquisition équipement lourd — pelle hydraulique CAT 320 — facture N° CAT-INV-2026-0089',
            'categorie_depense'    => 'achat_materiel',
            'montant'              => 16000000,
            'montant_lettres'      => 'Seize millions de francs guinéens',
            'devise'               => 'GNF',
            'statut'               => 'APPROUVE',
            'demandeur_id'         => $demandeur->id,
            'date_demande'         => now()->subDays(6)->toDateString(),
            'date_soumission'      => now()->subDays(6),
        ]);

        $bonS11->validations()->createMany([
            ['niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'validateur_id' => $chef->id, 'date_validation' => now()->subDays(6)->addHours(3)],
            ['niveau' => 2, 'role' => 'controle_gestion',    'statut' => 'approuve', 'validateur_id' => $cdg->id,  'date_validation' => now()->subDays(5)->addHours(10)],
            ['niveau' => 3, 'role' => 'daf',                 'statut' => 'approuve', 'validateur_id' => $daf->id,  'date_validation' => now()->subDays(5)->addHours(14)],
            ['niveau' => 4, 'role' => 'directeur_pays',      'statut' => 'approuve', 'validateur_id' => $dp->id,   'date_validation' => now()->subDays(4)->addHours(9)],
        ]);

        HistoriqueAction::enregistrer($bonS11, 'validation_directeur_pays', 'EN_ATTENTE_DP', 'APPROUVE', $dp->id, 'Approuvé DG Pays — en attente paiement caissier');

        /* ================================================================
         * 7. RÉSUMÉ CONSOLE
         * ================================================================ */

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════════════╗');
        $this->command->info('║      NEEMBA — Données pilote Conakry initialisées           ║');
        $this->command->info('╚══════════════════════════════════════════════════════════════╝');
        $this->command->info('');
        $this->command->info('  Seuil DG Pays  : 1 500 000 GNF (scénario S08)');
        $this->command->info('  Solde Conakry  : 15 000 000 GNF (S11 bloqué à 16 M)');
        $this->command->info('  Seuil alerte   : 1 000 000 GNF');
        $this->command->info('');
        $this->command->info('  Comptes pilote (mot de passe : Neemba@2026) :');
        $this->command->info('  ┌──────────────────────────────────────────────────────────┐');
        $this->command->info('  │ Demandeur      saoudou.barry@neemba.com   +224 622461261 │');
        $this->command->info('  │ Chef Service   thierry.gomis@neemba.com   +224 627471735 │');
        $this->command->info('  │ CDG            maimouna.barry@neemba.com  +224 627261871 │');
        $this->command->info('  │ DAF            mohamed.diakite@neemba.com +224 629000769 │');
        $this->command->info('  │ DG Pays        mamadou.lo@neemba.com      +224 612007272 │');
        $this->command->info('  │ Caissier       youssouf.toure@neemba.com  +224 623072484 │');
        $this->command->info('  │ Admin Addvalis thierno.diallo@addvalis.com               │');
        $this->command->info('  └──────────────────────────────────────────────────────────┘');
        $this->command->info('');
        $this->command->info('  Bons pré-positionnés :');
        $this->command->info('  BC-2026-0001  S03  EN_ATTENTE_CDG    450 000  (code analytique erroné)');
        $this->command->info('  BC-2026-0002  S04  EN_ATTENTE_CHEF   320 000  (à rejeter)');
        $this->command->info('  BC-2026-0003  S05  PAYE (BP)       1 200 000  (régulariser avant J+2)');
        $this->command->info('  BC-2026-0004  S06  APPROUVE          750 000  (paiement OTP)');
        $this->command->info('  BC-2026-0005  S07  EN_ATTENTE_DAF  6 000 000  (workflow 4 niveaux)');
        $this->command->info('  BC-2026-0006  S08  EN_ATTENTE_DP   2 000 000  (seuil DG Pays)');
        $this->command->info('  BC-2026-0007/8/9 S09 PAYE aujourd\'hui   (rapport journalier)');
        $this->command->info('  BC-2026-0010  S11  APPROUVE       16 000 000  (blocage solde)');
        $this->command->info('');
    }
}
