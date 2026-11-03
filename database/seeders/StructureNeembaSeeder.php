<?php

namespace Database\Seeders;

use App\Models\Caisse;
use App\Models\Site;
use App\Models\TypeDocument;
use Illuminate\Database\Seeder;

/**
 * Structure de Neemba Guinée pour une base neuve (production comprise), sans données de démonstration : sites, caisses
 * (principale par site, Orange Money, Atelier en avance fixe) et types de documents. Soldes à zéro ; plafonds, seuils,
 * gérants et libellés viennent ensuite de l'import des référentiels (referentiels:importer), qui ne crée ni site ni
 * caisse. Rejouable : ce qui existe déjà n'est pas recréé.
 *
 *   php artisan db:seed --class=StructureNeembaSeeder --force
 */
class StructureNeembaSeeder extends Seeder
{
    /** Sites et codes analytiques de site (classeur des référentiels, onglet 4-Caisses) */
    private const SITES = [
        ['code' => '01', 'nom' => 'Conakry', 'ville' => 'Conakry', 'caisse' => 'CKY-ESP'],
        ['code' => '31', 'nom' => 'Boke', 'ville' => 'Boké', 'caisse' => 'BOK-ESP'],
        ['code' => '11', 'nom' => 'Sangaredi', 'ville' => 'Sangarédi', 'caisse' => 'SAN-ESP'],
        ['code' => '81', 'nom' => 'Siguiri', 'ville' => 'Siguiri', 'caisse' => 'SIG-ESP'],
        ['code' => '49', 'nom' => 'Kouroussa', 'ville' => 'Kouroussa', 'caisse' => 'KOU-ESP'],
        ['code' => '39', 'nom' => 'Mandiana', 'ville' => 'Mandiana', 'caisse' => 'MAN-ESP'],
    ];

    private const TYPES_DOCUMENT = ['Facture', 'Devis', 'Bon de commande', 'Bon de livraison', 'Reçu', 'Contrat', 'Ordre de mission', 'Justificatif', 'Autre'];

    public function run(): void
    {
        foreach (self::SITES as $s) {
            $site = Site::firstOrCreate(['code' => $s['code']], ['nom' => $s['nom'], 'ville' => $s['ville']]);
            if (!Caisse::where('code', $s['caisse'])->exists() && !Caisse::whereHas('sites', fn ($q) => $q->whereKey($site->id))->where('type', 'especes')->exists()) {
                Caisse::creerCaissePrincipale($site)->update(['code' => $s['caisse']]);
            }
        }

        /* Caisse Orange Money unique (sans site, RG-M02-11) et caisse Atelier en avance fixe, inactive jusqu'à la saisie de son solde réel */
        $conakry = Site::where('code', '01')->first();
        Caisse::firstOrCreate(['code' => 'CKY-OM'], [
            'libelle' => 'Caisse Orange Money', 'type' => 'orange_money', 'mode' => 'standard', 'solde' => 0, 'actif' => true,
        ]);
        if (!Caisse::where('code', 'CKY-ATL')->exists()) {
            Caisse::create([
                'code' => 'CKY-ATL', 'libelle' => 'Caisse Atelier', 'site_id' => $conakry->id, 'type' => 'especes', 'mode' => 'avance_fixe',
                'montant_avance' => 15000000, 'solde' => 0, 'actif' => false,
            ]);
        }

        foreach (self::TYPES_DOCUMENT as $type) {
            TypeDocument::firstOrCreate(['nom' => $type]);
        }
    }
}
