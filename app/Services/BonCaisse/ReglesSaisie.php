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
        2 => ['type_beneficiaire', 'beneficiaire_id', 'visiteur_id', 'tiers_id', 'beneficiaire', 'telephone_beneficiaire'],
        3 => ['motif', 'categorie_depense', 'montant', 'mode_paiement', 'numero_om', 'motif_numero_om', 'vehicule', 'references_or', 'types_or', 'lie_mission', 'date_retour_mission'],
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
        'visiteur_id' => 'Visiteur du groupe',
        'tiers_id' => 'Tiers',
        'beneficiaire' => 'Nom du bénéficiaire',
        'telephone_beneficiaire' => 'Téléphone',
        'motif' => 'Motif de la demande',
        'categorie_depense' => 'Catégorie de dépense',
        'montant' => 'Montant',
        'mode_paiement' => 'Mode de paiement',
        'vehicule' => 'Véhicule / matériel',
        'references_or' => "N° d'OR",
        'types_or' => "Type d'OR",
        'numero_om' => 'N° Orange Money',
        'motif_numero_om' => 'Motif de la modification du n° OM',
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
        $typeBeneficiaire = $d['type_beneficiaire'] ?? 'employe';
        $employe = $typeBeneficiaire === 'employe';
        $urgent = ($d['niveau_urgence'] ?? 'normale') !== 'normale';
        $bp = ($d['type_bon'] ?? null) === 'BP';

        return [
            /* Étape 1 — RG-BC-03, RG-BC-04, RG-BC-05 */
            'type_bon' => ['required', Rule::in(['BD', 'BP'])],
            'code_analytique' => ['required', Rule::exists('codes_analytiques', 'code')->where('actif', true), self::regleCodeDuService($d)],
            'site' => ['required', Rule::exists('sites', 'nom')->where('actif', true)],
            'service' => ['required', Rule::exists('services', 'nom')->where('actif', true)],
            'niveau_urgence' => ['required', Rule::in(array_keys(BonCaisse::NIVEAUX_URGENCE))],
            /* RG-M03-14 : motif choisi dans la liste paramétrable (MSG-M03-08) ; la justification libre est facultative */
            'motif_urgence' => $urgent ? ['required', 'string', Rule::exists('motifs_urgence', 'libelle')->where('actif', true)] : ['nullable'],
            'justification_urgence' => ['nullable', 'string', 'max:300'],

            /* Étape 2 — RG-BC-06, RG-BC-07 */
            /* RG-M03-03 : salarié, visiteur du groupe ou tiers validé, aucune saisie libre ; RG-M03-04 : pas de BP pour un tiers */
            'type_beneficiaire' => ['required', Rule::in(BonCaisse::TYPES_BENEFICIAIRE_ASSISTANT),
                fn ($attribut, $valeur, $echec) => $bp && $valeur === 'tiers' ? $echec(__('MSG-M03-02')) : null],
            'beneficiaire_id' => $employe
                ? ['required', Rule::exists('users', 'id')->where('actif', true)]
                : ['nullable'],
            'visiteur_id' => $typeBeneficiaire === 'visiteur'
                ? ['required', Rule::exists('visiteurs', 'id')->where('actif', true)]
                : ['nullable'],
            'tiers_id' => $typeBeneficiaire === 'tiers' ? ['required', self::regleTiersValide()] : ['nullable'],
            'beneficiaire' => ['nullable', 'string', 'max:150'],
            'telephone_beneficiaire' => ['nullable', 'regex:/^(\+224)?6\d{8}$/'],

            /* Étape 3 — RG-BC-08 à RG-BC-14 */
            /* RG-M03-05 : 10 à 250 caractères */
            'motif' => ['required', 'string', 'min:10', 'max:250'],
            /* La catégorie « mission », non proposée dans l'assistant, est celle des bons générés depuis un ODM (M12) */
            'categorie_depense' => !empty($d['genere_par_odm'])
                ? ['required', Rule::exists('categories_depense', 'code')->where('actif', true)]
                : ['required', Rule::exists('categories_depense', 'code')->where('actif', true)->where('proposee_assistant', true)],
            'montant' => ['required', 'integer', 'min:1', 'max:999999999'],
            'mode_paiement' => array_filter(['required', Rule::in(BonCaisse::MODES_PAIEMENT_ASSISTANT), $avecPlafond ? self::reglePlafondRetrait($d) : null]),
            'vehicule' => $categorie?->vehicule_obligatoire ? ['required', 'string', 'min:3', 'max:20'] : ['nullable', 'string', 'min:3', 'max:20'],
            /* RG-M03-13 : OR facultatifs, 8 chiffres commençant par 110, type vente ou garantie */
            'references_or' => ['nullable', 'array'],
            'references_or.*' => ['regex:' . self::FORMAT_OR],
            'types_or' => ['nullable', 'array', self::regleTypesOr($d)],
            /* RG-M03-10 : n° OM obligatoire en Orange Money ; modifié par rapport au référentiel → motif */
            'numero_om' => ($d['mode_paiement'] ?? null) === 'orange_money' && empty($d['genere_par_odm']) ? ['required', 'regex:/^(\+224)?6\d{8}$/'] : ['nullable', 'regex:/^(\+224)?6\d{8}$/'],
            'motif_numero_om' => self::numeroOmModifie($d) ? ['required', 'string', 'min:10', 'max:255'] : ['nullable', 'string', 'max:255'],
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
            'visiteur_id' => ['nullable', 'integer', Rule::exists('visiteurs', 'id')],
            'tiers_id' => ['nullable', 'integer', Rule::exists('tiers', 'id')],
            'beneficiaire' => ['nullable', 'string', 'max:150'],
            'telephone_beneficiaire' => ['nullable', 'regex:/^(\+224)?6\d{8}$/'],
            'motif' => ['nullable', 'string', 'max:250'],
            'categorie_depense' => ['nullable', Rule::exists('categories_depense', 'code')],
            'montant' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'mode_paiement' => ['nullable', Rule::in(BonCaisse::MODES_PAIEMENT_ASSISTANT)],
            'vehicule' => ['nullable', 'string', 'max:20'],
            'references_or' => ['nullable', 'array'],
            'references_or.*' => ['regex:' . self::FORMAT_OR],
            'types_or' => ['nullable', 'array'],
            'types_or.*' => ['nullable', Rule::in(array_keys(\App\Models\OrdreReparationOdm::TYPES))],
            'numero_om' => ['nullable', 'regex:/^(\+224)?6\d{8}$/'],
            'motif_numero_om' => ['nullable', 'string', 'max:255'],
            'lie_mission' => ['nullable', 'boolean'],
            'date_retour_mission' => ['nullable', 'date'],
            'demandeur_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /** Messages exacts de la SFD (§5.6) */
    /** RG-M03-13 : 8 chiffres commençant par 110 (comme les OR des ODM) */
    public const FORMAT_OR = '/^110\d{5}$/';

    /** RG-M03-13 : chaque OR porte son type, vente ou garantie */
    private static function regleTypesOr(array $d): \Closure
    {
        return function ($attribut, $valeur, $echec) use ($d) {
            foreach ((array) ($d['references_or'] ?? []) as $numero) {
                if (!in_array($valeur[$numero] ?? null, array_keys(\App\Models\OrdreReparationOdm::TYPES), true)) {
                    $echec(__('MSG-APP-062', ['numero' => $numero]));

                    return;
                }
            }
        };
    }

    /** RG-M03-10 : n° OM du référentiel du bénéficiaire (salarié, visiteur ou tiers) */
    public static function numeroOmReferentiel(array $d): ?string
    {
        $numero = match ($d['type_beneficiaire'] ?? 'employe') {
            'employe' => !empty($d['beneficiaire_id']) ? \App\Models\User::find($d['beneficiaire_id'])?->numero_om : null,
            'visiteur' => !empty($d['visiteur_id']) ? \App\Models\Visiteur::find($d['visiteur_id'])?->numero_om : null,
            'tiers' => !empty($d['tiers_id']) ? \App\Models\Tiers::find($d['tiers_id'])?->numero_om : null,
            default => null,
        };

        return self::normaliserTelephone($numero);
    }

    /** Le n° OM saisi diffère-t-il de celui du référentiel ? (motif exigé, RG-M03-10) */
    public static function numeroOmModifie(array $d): bool
    {
        /* Bon d'ODM : n° OM du participant, repris de l'ODM */
        if (($d['mode_paiement'] ?? null) !== 'orange_money' || blank($d['numero_om'] ?? null) || !empty($d['genere_par_odm'])) {
            return false;
        }
        $referentiel = self::numeroOmReferentiel($d);

        return $referentiel !== null && self::normaliserTelephone($d['numero_om']) !== $referentiel;
    }

    /** RG-M02-06 : seul un tiers validé est sélectionnable (MSG-M03-03 s'il attend sa validation) */
    private static function regleTiersValide(): \Closure
    {
        return function ($attribut, $valeur, $echec) {
            $tiers = \App\Models\Tiers::find($valeur);
            if (!$tiers) {
                $echec(__('MSG-BC-001'));
            } elseif ($tiers->statut === 'propose') {
                $echec(__('MSG-M03-03'));
            } elseif (!$tiers->estValide()) {
                $echec(__('MSG-APP-060'));
            }
        };
    }

    public static function messages(): array
    {
        $obligatoire = __('MSG-BC-001');

        return [
            'required' => $obligatoire,
            'motif.min' => __('MSG-BC-002'),
            'montant.required' => $obligatoire,   // avant « montant.* » : le premier message qui correspond l'emporte
            'montant.*' => __('MSG-BC-003'),
            'type_beneficiaire.in' => __('MSG-APP-059'),
            'visiteur_id.exists' => __('MSG-APP-061'),
            'beneficiaire.min' => __('MSG-BC-004'),
            'beneficiaire.max' => __('MSG-BC-004'),
            'justification_urgence.min' => __('MSG-BC-005'),
            'telephone_beneficiaire.regex' => __('MSG-BC-006'),
            'vehicule.required' => __('MSG-BC-013'),
            'vehicule.min' => __('MSG-BC-013'),
            'vehicule.max' => __('MSG-BC-013'),
            'references_or.*.regex' => __('MSG-M03-07'),
            'motif_urgence.exists' => __('MSG-M03-08'),
            'motif_urgence.required' => __('MSG-M03-08'),
            'numero_om.regex' => __('MSG-BC-006'),
            'motif_numero_om.required' => __('MSG-APP-063'),
            'motif_numero_om.min' => __('MSG-BC-002'),
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
        /* RG-M03-15, RG-M12-29 : un BD généré depuis un ODM est justifié par l'ODM, aucun justificatif n'est exigé */
        if ($bon->type_bon === 'BD' && !$bon->genere_par_odm
            && !$pieces->contains(fn (PieceJointe $piece) => in_array($piece->type_document, PieceJointe::JUSTIFICATIFS_BD, true))) {
            return 'MSG-M03-09';
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
        $donnees['genere_par_odm'] = (bool) $bon->genere_par_odm;
        $donnees['caisse_emettrice_id'] = $bon->caisse_emettrice_id;

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

    /**
     * RG-BC-04, RG-M04-03 : si le service a des codes rattachés, le code doit en faire partie. Sert à la saisie du
     * bon comme au code corrigé par le CDG à son visa.
     */
    public static function regleCodeDuService(array $d): \Closure
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
            $caisse = Caisse::payeusePour($d['site'], 'especes', !empty($d['caisse_emettrice_id']) ? Caisse::find($d['caisse_emettrice_id']) : null);
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
