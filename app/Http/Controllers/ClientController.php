<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\Referentiels\ImportClients;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Référentiel clients (spec v2.3, §6.5) : géré par la Comptabilité (chef comptable), le CDG et l'administrateur.
 * Import initial d'un fichier fourni par Neemba (PO-26), puis ajouts au fil de l'eau. Un client ne se supprime pas :
 * il se désactive (il n'est alors plus proposé sur les ODM, ceux qui le portent déjà le gardent).
 */
class ClientController extends Controller
{
    public function index(Request $request)
    {
        $recherche = trim((string) $request->query('q', ''));
        $statut = $request->query('statut', 'tous');

        $clients = Client::query()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('code', 'like', "%{$recherche}%")->orWhere('raison_sociale', 'like', "%{$recherche}%")))
            ->when($statut === 'actifs', fn ($q) => $q->where('actif', true))
            ->when($statut === 'inactifs', fn ($q) => $q->where('actif', false))
            ->withCount('ordresMission')
            ->orderBy('raison_sociale')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Client $client) => $client->resume() + ['odm' => $client->ordres_mission_count]);

        return Inertia::render('Referentiels/Clients', [
            'clients' => $clients,
            'filtres' => ['q' => $recherche, 'statut' => $statut],
            'totaux' => ['actifs' => Client::actifs()->count(), 'tous' => Client::count()],
        ]);
    }

    public function store(Request $request)
    {
        /** @var User $auteur */
        $auteur = Auth::user();
        $donnees = $this->valider($request);
        Client::create($donnees + ['actif' => true, 'cree_par_id' => $auteur->id]);

        return back()->with('success', "Client {$donnees['code']} ajouté.");
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->valider($request, $client));

        return back()->with('success', "Client {$client->code} modifié.");
    }

    public function basculer(Client $client)
    {
        $client->update(['actif' => !$client->actif]);

        return back()->with('success', $client->actif ? "Client {$client->code} réactivé." : "Client {$client->code} désactivé : il n'est plus proposé sur les ordres de mission.");
    }

    /** Import d'un fichier Excel ou CSV : aperçu, puis application du même fichier (« appliquer » = 1) */
    public function importer(Request $request)
    {
        /** @var User $auteur */
        $auteur = Auth::user();
        $request->validate([
            'fichier' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'],
            'appliquer' => ['sometimes', 'boolean'],
        ], [
            'fichier.required' => 'Choisissez le fichier des clients.',
            'fichier.mimes' => 'Le fichier doit être un classeur Excel (.xlsx, .xls) ou un CSV.',
            'fichier.max' => 'Le fichier dépasse 5 Mo.',
        ]);
        $fichier = $request->file('fichier');

        return response()->json(ImportClients::executer(
            $fichier->getRealPath(), $fichier->getClientOriginalExtension(), $auteur, $request->boolean('appliquer'),
        ));
    }

    /** Recherche pour les sélecteurs des ODM : clients actifs */
    public function recherche(Request $request)
    {
        $recherche = trim((string) $request->query('q', ''));

        return response()->json(Client::actifs()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('code', 'like', "%{$recherche}%")->orWhere('raison_sociale', 'like', "%{$recherche}%")))
            ->orderBy('raison_sociale')
            ->limit(20)
            ->get()
            ->map(fn (Client $client) => $client->resume())
            ->values());
    }

    private function valider(Request $request, ?Client $client = null): array
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('clients', 'code')->ignore($client?->id)],
            'raison_sociale' => ['required', 'string', 'max:150'],
        ], [
            'code.required' => 'Saisissez le code client.',
            'code.unique' => 'Ce code client existe déjà.',
            'code.max' => 'Le code client fait 30 caractères au plus.',
            'raison_sociale.required' => 'Saisissez la raison sociale.',
        ]);

        return ['code' => mb_strtoupper(trim($donnees['code'])), 'raison_sociale' => trim(preg_replace('/\s+/', ' ', $donnees['raison_sociale']))];
    }
}
