<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CategorieDepense;
use App\Models\CodeAnalytique;
use App\Models\PieceJointe;
use App\Models\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * Règles de saisie de l'assistant (SFD M03, E-03.3 à E-03.6).
 *
 * - Règles complètes : « Suivant », écran Contrôle, soumission (RG-BC-03 à RG-BC-18).
 * - Règles de format : enregistrement d'un brouillon, où seuls les formats sont vérifiés (RG-BC-25).
 * Les règles portent sur l'état complet du bon (ce qui est déjà enregistré + la saisie), car certaines
 * dépendent d'autres étapes (véhicule obligatoire selon la catégorie, plafond de retrait selon le site…).
 */
class ReglesSaisie
{
    const ETAPES = [
        1 => 'Identification',
        2 => 'Bénéficiaire',
        3 => 'Dépense',
        4 => 'Pièces',
        5 => 'Contrôle',
    ];

    /** Champs saisis à chaque étape (l'étape 4 porte sur les pièces, contrôlées à part) */
    const CHAMPS_PAR_ETAPE = [
        1 => ['type_bon', 'code_analytique', 'site', 'service', 'niveau_urgence', 'motif_urgence', 'justification_urgence'],
        2 => ['type_beneficiaire', 'beneficiaire_id', 'beneficiaire', 'telephone_beneficiaire'],
        3 => ['motif', 'categorie_depense', 'montant', 'mode_paiement', 'vehicule', 'references_or', 'lie_mission', 'date_retour_mission'],
    ];

    /** Libellés écran des champs (MSG-BC-040 : liste des champs manquants) */
    const LIBELLES = [
        'type_bon' => 'Type de bon',
        'code_analytique' => 'Code analytique',
        'site' => 'Site',
        'service' => 'Service',
        'niveau_urgence' => "Niveau d'urgence",
        'motif_urgence' => "Motif d'urgence",
        'justification_urgence' => 'Justification',
        'type_beneficiaire' => 'Type de bénéficiaire',
        'beneficiaire_id' => 'Bénéficiaire',
        'beneficiaire' => 'Nom du bénéficiaire',
        'telephone_beneficiaire' => 'Téléphone',
        'motif' => 'Motif de la demande',
        'categorie_depense' => 'Catégorie de dépense',
        'montant' => 'Montant',
        'mode_paiement' => 'Mode de paiement',
        'vehicule' => 'Véhicule / matériel',
        'references_or' => "N° d'OR",
        'lie_mission' => 'Lié à une mission',
        'date_retour_mission' => 'Date de retour de mission',
    ];

    /** Champs enregistrables par l'assistant */
    public static function champs(): array
    {
        return array_merge(...array_values(self::CHAMPS_PAR_ETAPE));
    }

    /** Étape d'un champ */
    public static function etapeDe(string $champ): int
    {
        $base = explode('.', $champ)[0];
        foreach (self::CHAMPS_PAR_ETAPE as $etape => $champs) {
            if (in_array($base, $champs, true)) {
                return $etape;
            }
        }

        return 4;
    }

    /* ------------------------------------------------------------------
     * Validation
     * ------------------------------------------------------------------ */

    /**
     * Erreurs des étapes demandées (règles complètes), sur l'état fusionné $donnees.
     * Sans le plafond de retrait, pour le contrôle 1 de l'étape 5 (le plafond est le contrôle 2).
     */
    public static function erreursCompletes(array $donnees, array $etapes = [1, 2, 3], bool $avecPlafond = true): MessageBag
    {
        $regles = array_intersect_key(
            self::reglesCompletes($donnees, $avecPlafond),
            array_flip(self::champsDesRegles($etapes)),
        );

        return Validator::make($donnees, $regles, self::messages())->errors();
    }

    /**
     * Erreurs de format (brouillon) sur la saisie $saisie.
     */
    public static function erreursFormat(array $saisie): MessageBag
    {
        return Validator::make($saisie, self::reglesFormat(), self::messages())->errors();
    }

    public static function reglesCompletes(array $d, bool $avecPlafond = true): array
    {
        $categorie = CategorieDepense::where('code', $d['categorie_depense'] ?? null)->first();
        $employe = ($d['type_beneficiaire'] ?? 'employe') === 'employe';
        $urgent = ($d['niveau_urgence'] ?? 'normale') !== 'normale';
        $bp = ($d['type_bon'] ?? null) === 'BP';

        return [
            /* Étape 1 — RG-BC-03, RG-BC-04, RG-BC-05 */
            'type_bon' => ['required', Rule::in(['BD', 'BP'])],
            'code_analytique' => ['required', Rule::exists('codes_analytiques', 'code')->where('actif', true), self::regleCodeDuService($d)],
            'site' => ['required', Rule::exists('sites', 'nom')->where('actif', true)],
            'service' => ['required', Rule::exists('services', 'nom')->where('actif', true)],
            'niveau_urgence' => ['required', Rule::in(array_keys(BonCaisse::NIVEAUX_URGENCE))],
            'motif_urgence' => $urgent ? ['required', 'string', 'max:255'] : ['nullable'],
            'justification_urgence' => $urgent ? ['required', 'string', 'min:10', 'max:300'] : ['nullable'],

            /* Étape 2 — RG-BC-06, RG-BC-07 */
            'type_beneficiaire' => ['required', Rule::in(array_keys(BonCaisse::TYPES_BENEFICIAIRE))],
            'beneficiaire_id' => $employe
                ? ['required', Rule::exists('users', 'id')->where('actif', true)]
                : ['nullable'],
            'beneficiaire' => $employe ? ['nullable'] : ['required', 'string', 'min:3', 'max:120'],
            'telephone_beneficiaire' => ['nullable', 'regex:/^(\+224)?6\d{8}$/'],

            /* Étape 3 — RG-BC-08 à RG-BC-14 */
            'motif' => ['required', 'string', 'min:10', 'max:200'],
            'categorie_depense' => ['required', Rule::exists('categories_depense', 'code')->where('actif', true)->where('proposee_assistant', true)],
            'montant' => ['required', 'integer', 'min:1', 'max:999999999'],
            'mode_paiement' => array_filter(['required', Rule::in(BonCaisse::MODES_PAIEMENT_ASSISTANT), $avecPlafond ? self::reglePlafondRetrait($d) : null]),
            'vehicule' => $categorie?->vehicule_obligatoire ? ['required', 'string', 'min:3', 'max:20'] : ['nullable', 'string', 'min:3', 'max:20'],
            'references_or' => ['nullable', 'array'],
            'references_or.*' => ['regex:/^\d{8}$/'],
            'lie_mission' => ['boolean'],
            'date_retour_mission' => $bp && !empty($d['lie_mission'])
                ? ['required', 'date', 'after_or_equal:' . now()->subDays(30)->toDateString()]
                : ['nullable', 'date'],
        ];
    }

    /**
     * Formats seulement (brouillon, RG-BC-25) : ni champ obligatoire ni longueur minimale.
     */
    public static function reglesFormat(): array
    {
        return [
            'type_bon' => ['nullable', Rule::in(['BD', 'BP'])],
            'code_analytique' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'niveau_urgence' => ['nullable', Rule::in(array_keys(BonCaisse::NIVEAUX_URGENCE))],
            'motif_urgence' => ['nullable', 'string', 'max:255'],
            'justification_urgence' => ['nullable', 'string', 'max:300'],
            'type_beneficiaire' => ['nullable', Rule::in(array_keys(BonCaisse::TYPES_BENEFICIAIRE))],
            'beneficiaire_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'beneficiaire' => ['nullable', 'string', 'max:120'],
            'telephone_beneficiaire' => ['nullable', 'regex:/^(\+224)?6\d{8}$/'],
            'motif' => ['nullable', 'string', 'max:200'],
            'categorie_depense' => ['nullable', Rule::exists('categories_depense', 'code')],
            'montant' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'mode_paiement' => ['nullable', Rule::in(BonCaisse::MODES_PAIEMENT_ASSISTANT)],
            'vehicule' => ['nullable', 'string', 'max:20'],
            'references_or' => ['nullable', 'array'],
            'references_or.*' => ['regex:/^\d{8}$/'],
            'lie_mission' => ['nullable', 'boolean'],
            'date_retour_mission' => ['nullable', 'date'],
        ];
    }

    /** Messages exacts de la SFD (§5.6) */
    public static function messages(): array
    {
        $obligatoire = __('MSG-BC-001');

        return [
            'required' => $obligatoire,
            'motif.min' => __('MSG-BC-002'),
            'montant.required' => $obligatoire,   // avant « montant.* » : le premier message qui correspond l'emporte
            'montant.*' => __('MSG-BC-003'),
            'beneficiaire.min' => __('MSG-BC-004'),
            'beneficiaire.max' => __('MSG-BC-004'),
            'justification_urgence.min' => __('MSG-BC-005'),
            'telephone_beneficiaire.regex' => __('MSG-BC-006'),
            'vehicule.required' => __('MSG-BC-013'),
            'vehicule.min' => __('MSG-BC-013'),
            'vehicule.max' => __('MSG-BC-013'),
            'references_or.*.regex' => __('MSG-BC-014'),
            'date_retour_mission.required' => __('MSG-BC-015'),
        ];
    }

    /* ------------------------------------------------------------------
     * Pièces (étape 4)
     * ------------------------------------------------------------------ */

    /**
     * Erreur bloquante des pièces : type manquant (RG-BC-18, MSG-BC-016) ou BD sans justificatif (RG-BC-15, MSG-BC-017).
     * Une pièce illisible ne compte pas comme justificatif (US-BC-08) ; une pièce remplacée non plus.
     */
    public static function erreurPieces(BonCaisse $bon): ?string
    {
        $pieces = $bon->piecesActives()->get();

        if ($pieces->contains(fn (PieceJointe $piece) => $piece->type_document === null)) {
            return 'MSG-BC-016';
        }
        if ($bon->type_bon === 'BD' && !$pieces->contains(fn (PieceJointe $piece) => in_array($piece->type_document, PieceJointe::JUSTIFICATIFS_BD, true)
            && $piece->qualite !== QualitePiece::ILLISIBLE)) {
            return 'MSG-BC-017';
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Outils
     * ------------------------------------------------------------------ */

    /** Données du bon dans le format de la saisie */
    public static function donneesDu(BonCaisse $bon): array
    {
        $donnees = [];
        foreach (self::champs() as $champ) {
            $valeur = $bon->getAttribute($champ);
            $donnees[$champ] = $valeur instanceof \Carbon\CarbonInterface ? $valeur->toDateString() : $valeur;
        }
        $donnees['montant'] = $bon->montant !== null ? (int) round((float) $bon->montant) : null;

        return $donnees;
    }

    /** Première étape incomplète (reprise d'un brouillon — US-BC-11) ; 5 si tout est complet */
    public static function premiereEtapeIncomplete(BonCaisse $bon): int
    {
        $donnees = self::donneesDu($bon);
        foreach ([1, 2, 3] as $etape) {
            if (self::erreursCompletes($donnees, [$etape])->isNotEmpty()) {
                return $etape;
            }
        }

        return self::erreurPieces($bon) ? 4 : 5;
    }

    /** Téléphone guinéen : 9 chiffres commençant par 6, enregistré +224XXXXXXXXX (E-03.4) */
    public static function normaliserTelephone(?string $telephone): ?string
    {
        if ($telephone === null || trim($telephone) === '') {
            return null;
        }
        $chiffres = preg_replace('/\D/', '', $telephone);
        if (str_starts_with($chiffres, '00224')) {
            $chiffres = substr($chiffres, 5);
        } elseif (str_starts_with($chiffres, '224') && strlen($chiffres) === 12) {
            $chiffres = substr($chiffres, 3);
        }

        return preg_match('/^6\d{8}$/', $chiffres) ? '+224' . $chiffres : $telephone;
    }

    private static function champsDesRegles(array $etapes): array
    {
        $champs = [];
        foreach ($etapes as $etape) {
            $champs = array_merge($champs, self::CHAMPS_PAR_ETAPE[$etape] ?? []);
            if ($etape === 3) {
                $champs[] = 'references_or.*';
            }
        }

        return $champs;
    }

    /** RG-BC-04 : si le service a des codes rattachés, le code doit en faire partie */
    private static function regleCodeDuService(array $d): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) use ($d) {
            $service = Service::where('nom', $d['service'] ?? null)->first();
            if (!$service) {
                return;
            }
            $codesDuService = CodeAnalytique::where('actif', true)->where('service_id', $service->id)->pluck('code');
            if ($codesDuService->isNotEmpty() && !$codesDuService->contains($valeur)) {
                $echec("Ce code analytique n'est pas rattaché au service {$service->nom}.");
            }
        };
    }

    /** RG-BC-11 : au-delà du plafond de retrait de la caisse payeuse, pas d'espèces (MSG-BC-012) */
    private static function reglePlafondRetrait(array $d): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) use ($d) {
            if ($valeur !== 'especes' || empty($d['site']) || empty($d['montant'])) {
                return;
            }
            $caisse = Caisse::payeusePour($d['site'], 'especes');
            if ($caisse && $caisse->depassePlafondRetrait((float) $d['montant'])) {
                $echec(ErreurMetier::texte('MSG-BC-012', [
                    'plafond' => (float) $caisse->plafond_retrait,
                    'caisse' => self::libelleMinuscule($caisse->libelle),
                ]));
            }
        };
    }

    /** « Caisse principale Conakry » → « caisse principale Conakry » (dans une phrase) */
    public static function libelleMinuscule(string $libelle): string
    {
        return mb_strtolower(mb_substr($libelle, 0, 1)) . mb_substr($libelle, 1);
    }
}
