<?php

namespace App\Http\Controllers;

use App\Models\TauxChange;
use App\Models\User;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Écran Trésorerie « Taux du jour » (spec v2.2, §6.6, PO-08) : la Trésorerie saisit chaque jour le taux FCFA → GNF
 * communiqué par la banque. Sans taux du jour, le paiement d'un ODM extérieur est bloqué (RG-M12-10, MSG-M12-05).
 */
class TauxChangeController extends Controller
{
    public function index()
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();

        $historique = TauxChange::with('saisiPar:id,name,prenom')
            ->where('devise', TauxChange::DEVISE_FCFA)
            ->orderByDesc('date_taux')
            ->limit(30)
            ->get()
            ->map(fn (TauxChange $taux) => self::presenter($taux));

        return Inertia::render('Tresorerie/TauxChange', [
            'tauxDuJour' => ($duJour = TauxChange::duJour()) ? self::presenter($duJour) : null,
            'dernierTaux' => ($dernier = TauxChange::dernier()) ? self::presenter($dernier) : null,
            'historique' => $historique,
            'peutSaisir' => $utilisateur->aLeRole(TauxChange::ROLES_SAISIE),
            'dateDuJour' => Format::date(today()),
        ]);
    }

    /**
     * Saisie du taux du jour. Une correction le même jour exige un motif, conservé avec l'ancien taux.
     */
    public function store(Request $request)
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($utilisateur->aLeRole(TauxChange::ROLES_SAISIE), 403, 'Seule la Trésorerie saisit le taux du jour.');

        $donnees = $request->validate([
            'taux' => ['required', 'numeric', 'gt:0', 'lt:1000'],
            'motif' => ['nullable', 'string', 'max:200'],
        ], [
            'taux.required' => 'Saisissez le taux du jour.',
            'taux.numeric' => 'Le taux doit être un nombre (GNF pour 1 FCFA).',
            'taux.gt' => 'Le taux doit être supérieur à 0.',
            'taux.lt' => 'Le taux saisi est anormalement élevé : il s\'exprime en GNF pour 1 FCFA.',
        ]);

        $existant = TauxChange::duJour();
        if ($existant) {
            if ((float) $existant->taux === (float) $donnees['taux']) {
                return back()->with('success', 'Ce taux est déjà enregistré pour aujourd\'hui.');
            }
            if (blank($donnees['motif'] ?? null)) {
                throw ValidationException::withMessages(['motif' => 'Indiquez le motif de la correction du taux du jour.']);
            }
            $existant->update([
                'taux' => $donnees['taux'],
                'saisi_par_id' => $utilisateur->id,
                'commentaire' => mb_substr('Correction : ' . trim($donnees['motif']) . ' (ancien taux ' . self::taux($existant->taux) . ')', 0, 255),
            ]);

            return back()->with('success', 'Taux du jour corrigé : 1 FCFA = ' . self::taux($donnees['taux']) . ' GNF.');
        }

        TauxChange::create([
            'date_taux' => today(),
            'devise' => TauxChange::DEVISE_FCFA,
            'taux' => $donnees['taux'],
            'saisi_par_id' => $utilisateur->id,
        ]);

        return back()->with('success', 'Taux du jour enregistré : 1 FCFA = ' . self::taux($donnees['taux']) . ' GNF.');
    }

    private static function presenter(TauxChange $taux): array
    {
        return [
            'id' => $taux->id,
            'date' => Format::date($taux->date_taux),
            'taux' => (float) $taux->taux,
            'taux_format' => self::taux($taux->taux),
            'saisi_par' => $taux->saisiPar ? trim(mb_strtoupper($taux->saisiPar->name) . ' ' . $taux->saisiPar->prenom) : '—',
            'saisi_le' => Format::dateHeure($taux->updated_at),
            'commentaire' => $taux->commentaire,
        ];
    }

    /** Taux affiché à la française, sans zéros inutiles : 14,5 ; 14,6215 */
    private static function taux(float|string $taux): string
    {
        $texte = rtrim(rtrim(number_format((float) $taux, 4, ',', "\u{202F}"), '0'), ',');

        return $texte === '' ? '0' : $texte;
    }
}
