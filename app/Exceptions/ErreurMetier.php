<?php

namespace App\Exceptions;

use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Refus d'une règle de gestion, avec le message du catalogue de la SFD (lang/fr.json).
 *
 * - Appel API (Accept: application/json) : réponse au format SFD §5.7
 *   { "code": "PLAFOND_RETRAIT_DEPASSE", "regle": "RG-BC-11", "message_cle": "MSG-BC-012", "valeurs": {...}, "message": "..." }
 * - Écran (Inertia) : retour à la page, le message s'affiche sous le champ concerné (ou en bandeau).
 *
 *   throw new ErreurMetier('PLAFOND_RETRAIT_DEPASSE', 'MSG-BC-012',
 *       ['plafond' => 20000000, 'caisse' => 'caisse principale Conakry'], 'RG-BC-11', 'mode_paiement');
 */
class ErreurMetier extends RuntimeException
{
    public function __construct(
        public readonly string $codeErreur,
        public readonly string $messageCle,
        public readonly array $valeurs = [],
        public readonly ?string $regle = null,
        public readonly ?string $champ = null,
        public readonly int $statutHttp = 422,
    ) {
        parent::__construct(self::texte($messageCle, $valeurs));
    }

    /**
     * Texte du message, variables formatées selon §1.4 (nombres avec séparateur de milliers, date JJ/MM/AAAA).
     */
    public static function texte(string $messageCle, array $valeurs = []): string
    {
        $remplacements = [];
        foreach ($valeurs as $nom => $valeur) {
            $remplacements[$nom] = match (true) {
                is_int($valeur), is_float($valeur) => Format::nombre($valeur),
                $nom === 'date' => Format::date($valeur),
                default => (string) $valeur,
            };
        }

        return __($messageCle, $remplacements);
    }

    /** Refus métier attendu : rien à journaliser */
    public function report(): void
    {
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => $this->codeErreur,
                'regle' => $this->regle,
                'message_cle' => $this->messageCle,
                'valeurs' => (object) $this->valeurs,
                'message' => $this->getMessage(),
            ], $this->statutHttp);
        }

        return back()
            ->withErrors([$this->champ ?? 'general' => $this->getMessage()])
            ->with('error', $this->getMessage());
    }
}
