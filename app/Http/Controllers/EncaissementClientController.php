<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\Client;
use App\Models\EncaissementClient;
use App\Services\Caisse\AccesCaisse;
use App\Services\Caisse\EncaisserClient;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Encaissements clients (spec v2.4, RG-M08-10) : liste, saisie, reçu PDF. Réservés aux caisses autorisées (Conakry
 * principale) ; saisis par le gérant de la caisse ou la trésorerie ; consultés par les rôles transverses.
 */
class EncaissementClientController extends Controller
{
    public function index()
    {
        $utilisateur = Auth::user();
        $caisses = AccesCaisse::caissesVisibles($utilisateur);
        $autorisees = $caisses->filter(fn (Caisse $c) => $c->encaissements_clients && EncaisserClient::peutEncaisser($utilisateur, $c))->values();

        return Inertia::render('Caisses/Encaissements/Index', [
            'encaissements' => EncaissementClient::with(['caisse', 'client', 'encaissePar'])
                ->whereIn('caisse_id', $caisses->pluck('id'))->latest('encaisse_le')->paginate(20)
                ->through(fn (EncaissementClient $e) => [
                    'id' => $e->id,
                    'numero' => $e->numero,
                    'date' => Format::dateHeure($e->encaisse_le),
                    'caisse' => $e->caisse?->libelle,
                    'client' => $e->client?->raison_sociale,
                    'numero_piece' => $e->numero_piece,
                    'montant' => Format::montant($e->montant),
                    'mode' => EncaissementClient::MODES[$e->mode] ?? $e->mode,
                    'par' => $e->encaissePar?->nom_complet,
                ]),
            'caisses' => $autorisees->map(fn (Caisse $c) => [
                'id' => $c->id, 'libelle' => $c->libelle, 'solde' => (float) $c->solde,
                'plafond' => $c->plafond_caisse !== null ? (float) $c->plafond_caisse : null,
            ])->values(),
            'clients' => Client::where('actif', true)->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale']),
            'modes' => EncaissementClient::MODES,
        ]);
    }

    public function store(Request $request)
    {
        $donnees = $request->validate([
            'caisse_id' => ['required', 'integer', Rule::exists('caisses', 'id')],
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('actif', true)],
            'numero_piece' => ['required', 'string', 'max:40'],
            'montant' => ['required', 'numeric', 'min:1', 'max:10000000000'],
            'mode' => ['required', Rule::in(array_keys(EncaissementClient::MODES))],
            'observations' => ['nullable', 'string', 'max:500'],
            'piece' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'client_id.required' => 'Choisissez le client.',
            'numero_piece.required' => 'Indiquez le n° de pièce (OR ou facture).',
        ]);
        $encaissement = EncaisserClient::enregistrer(Auth::user(), Caisse::findOrFail($donnees['caisse_id']), Client::findOrFail($donnees['client_id']),
            $donnees['numero_piece'], (float) $donnees['montant'], $donnees['mode'], $donnees['observations'] ?? null, $request->file('piece'));

        return back()->with('success', "Encaissement {$encaissement->numero} enregistré : " . Format::montant($encaissement->montant) . '. Imprimez le reçu depuis la liste.');
    }

    public function recu(EncaissementClient $encaissementClient)
    {
        $utilisateur = Auth::user();
        abort_unless($encaissementClient->caisse && AccesCaisse::caissesVisibles($utilisateur)->contains('id', $encaissementClient->caisse_id), 403);
        $encaissementClient->loadMissing(['caisse', 'client', 'encaissePar']);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.recu-encaissement-pdf', [
            'e' => $encaissementClient,
            'entite' => $encaissementClient->caisse->entite()->first(),
            'montant_lettres' => \App\Support\MontantEnLettres::convertir((float) $encaissementClient->montant),
            'edite_le' => Format::dateHeure(now()),
        ])->setPaper('a5', 'landscape')->stream("{$encaissementClient->numero}.pdf");
    }
}
