<?php

namespace App\Services\LectureTicket;

use App\Exceptions\ErreurMetier;
use App\Jobs\LireTicketJob;
use App\Models\BonCaisse;
use App\Models\LectureTicket;
use App\Models\Parametre;
use App\Models\PieceJointe;
use App\Models\User;
use App\Support\MontantEnLettres;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lecture assistée des tickets carburant (US-BC-09, RG-BC-20 à RG-BC-23).
 *
 * - RG-BC-20 : la lecture démarre dès qu'une pièce est un ticket carburant ; elle est asynchrone et abandonnée
 *   après 60 secondes (le panneau s'ouvre alors avec des champs vides).
 * - RG-BC-21 : aucune valeur n'est retenue sans confirmation ; les champs corrigés sont conservés.
 * - RG-BC-22, RG-BC-23 : contrôles de cohérence d'un ticket validé, tous en avertissement.
 */
class LectureTickets
{
    /** Au-delà, la lecture est abandonnée au profit de la saisie manuelle (RG-BC-20) */
    public const DELAI_MAX_SECONDES = 60;

    /** Écart toléré entre le prix au litre et le prix de référence (RG-BC-22) */
    private const ECART_PRIX = 0.10;

    /** Écart toléré entre le total des tickets et le montant du bon (contrôle 7, MSG-BC-024) */
    public const ECART_TOTAL = 0.02;

    /** Lecture d'une pièce typée « Ticket carburant » : démarrée une seule fois */
    public static function demarrer(PieceJointe $piece): LectureTicket
    {
        if ($lecture = $piece->lectureTicket()->first()) {
            return $lecture;
        }

        $lecteur = app(LecteurTicket::class);
        $lecture = LectureTicket::create([
            'piece_jointe_id' => $piece->id,
            'statut' => LectureTicket::EN_COURS,
            'lecteur' => $lecteur->nom(),
            'demarree_le' => now(),
        ]);

        /* Sans lecteur automatique, inutile de passer par la file : le panneau s'ouvre tout de suite */
        if ($lecteur instanceof LecteurManuel) {
            self::executer($lecture);
        } else {
            LireTicketJob::dispatch($lecture->id);
        }

        return $lecture->fresh();
    }

    /** Lecture proprement dite (tâche de fond) */
    public static function executer(LectureTicket $lecture): void
    {
        try {
            $resultat = app(LecteurTicket::class)->lire($lecture->pieceJointe);
        } catch (\Throwable $erreur) {
            report($erreur);
            $resultat = null;
        }

        $lecture->refresh();
        if ($lecture->statut !== LectureTicket::EN_COURS) {
            return;   // validée à la main entre-temps
        }

        $lecture->update($resultat ? [
            'statut' => LectureTicket::TERMINEE,
            'valeurs_lues' => self::normaliser($resultat['valeurs'] ?? []),
            'confiances' => array_map('intval', array_intersect_key($resultat['confiances'] ?? [], array_flip(LectureTicket::CHAMPS))),
            'terminee_le' => now(),
        ] : [
            'statut' => LectureTicket::INDISPONIBLE,
            'terminee_le' => now(),
        ]);
    }

    /** Une lecture en cours depuis plus de 60 secondes est abandonnée (« Lecture automatique indisponible ») */
    public static function actualiser(LectureTicket $lecture): LectureTicket
    {
        if ($lecture->statut === LectureTicket::EN_COURS && $lecture->demarree_le?->lt(now()->subSeconds(self::DELAI_MAX_SECONDES))) {
            $lecture->update(['statut' => LectureTicket::INDISPONIBLE, 'terminee_le' => now()]);
        }

        return $lecture;
    }

    /**
     * Validation par l'utilisateur (RG-BC-21) : chaque champ confirmé (case cochée) ou corrigé.
     *
     * @param  array<string, mixed>  $valeurs
     * @param  string[]  $confirmes
     */
    public static function valider(LectureTicket $lecture, array $valeurs, array $confirmes, User $auteur): LectureTicket
    {
        $valeurs = array_intersect_key($valeurs, array_flip(LectureTicket::CHAMPS));
        $erreurs = Validator::make($valeurs, [
            'station' => ['required', 'string', 'min:2', 'max:80'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'litres' => ['nullable', 'numeric', 'gt:0', 'lte:500'],
            'montant' => ['required', 'integer', 'min:1', 'max:999999999'],
            'montant_lettres' => ['nullable', 'string', 'max:255'],
            'immatriculation' => ['nullable', 'string', 'max:20'],
        ], [
            /* « champ.required » avant « champ.* » : le premier message qui correspond l'emporte */
            'station.required' => __('MSG-BC-001'),
            'station.*' => __('MSG-APP-010'),
            'date.before_or_equal' => __('MSG-APP-006'),
            'date.*' => __('MSG-BC-001'),
            'litres.*' => __('MSG-APP-007'),
            'montant.required' => __('MSG-BC-001'),
            'montant.*' => __('MSG-BC-003'),
        ])->errors();
        if ($erreurs->isNotEmpty()) {
            throw ValidationException::withMessages($erreurs->toArray());
        }

        if (array_diff(LectureTicket::CHAMPS, $confirmes) !== []) {
            throw new ErreurMetier('LECTURE_NON_CONFIRMEE', 'MSG-APP-008', [], 'RG-BC-21', 'lecture');
        }

        $validees = self::normaliser($valeurs);
        $lues = $lecture->valeurs_lues ?? [];
        $corriges = array_values(array_filter(LectureTicket::CHAMPS,
            fn (string $champ) => ($lues[$champ] ?? null) !== null && $lues[$champ] != $validees[$champ]));

        $lecture->update([
            'statut' => LectureTicket::VALIDEE,
            'valeurs_validees' => $validees,
            'champs_corriges' => $corriges,
            'validee_par' => $auteur->id,
            'validee_le' => now(),
        ]);

        return $lecture;
    }

    /**
     * RG-BC-22 : contrôles d'un ticket validé, en avertissement.
     *
     * @return array<int, array{message_cle: string, valeurs: array, message: string}>
     */
    public static function avertissements(LectureTicket $lecture, BonCaisse $bon): array
    {
        if (!$lecture->estValidee()) {
            return [];
        }
        $v = $lecture->valeurs_validees;
        $avertissements = [];

        /* Prix au litre (RG-BC-23 : prix de référence paramétrable) */
        if (!empty($v['litres']) && !empty($v['montant'])) {
            $prix = (int) round($v['montant'] / $v['litres']);
            $reference = Parametre::prixLitreReference();
            if ($reference > 0 && abs($prix - $reference) / $reference > self::ECART_PRIX) {
                $avertissements[] = self::avertissement('MSG-BC-022', ['prix' => $prix, 'reference' => $reference]);
            }
        }

        /* Montant en chiffres et en lettres */
        if (!empty($v['montant_lettres']) && MontantEnLettres::lire($v['montant_lettres']) !== (int) $v['montant']) {
            $avertissements[] = self::avertissement('MSG-BC-023');
        }

        /* Immatriculation lue et véhicule du bon */
        $lue = self::immatriculation($v['immatriculation'] ?? null);
        $vehicule = self::immatriculation($bon->vehicule);
        if ($lue !== '' && $vehicule !== '' && $lue !== $vehicule) {
            $avertissements[] = self::avertissement('MSG-BC-026', ['lue' => $v['immatriculation'], 'vehicule' => $bon->vehicule]);
        }

        /* Date du ticket et mission : au plus un jour après le retour (le départ viendra avec les ordres de mission, M12) */
        if ($bon->lie_mission && $bon->date_retour_mission && !empty($v['date'])
            && $bon->date_retour_mission->copy()->addDay()->lt(\Illuminate\Support\Carbon::parse($v['date']))) {
            $avertissements[] = self::avertissement('MSG-BC-025');
        }

        return $avertissements;
    }

    /* ------------------------------------------------------------------ */

    /** Valeurs ramenées à un format unique (comparaison des corrections, contrôles) */
    private static function normaliser(array $valeurs): array
    {
        $texte = fn ($valeur) => is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
        $nombre = fn ($valeur) => is_numeric(str_replace([' ', ','], ['', '.'], (string) $valeur))
            ? str_replace([' ', ','], ['', '.'], (string) $valeur) : null;

        $date = $texte($valeurs['date'] ?? null);
        $litres = $nombre($valeurs['litres'] ?? null);
        $montant = $nombre($valeurs['montant'] ?? null);
        $immatriculation = $texte($valeurs['immatriculation'] ?? null);

        return [
            'station' => $texte($valeurs['station'] ?? null),
            'date' => $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null,
            'litres' => $litres !== null ? round((float) $litres, 2) : null,
            'montant' => $montant !== null ? (int) round((float) $montant) : null,
            'montant_lettres' => $texte($valeurs['montant_lettres'] ?? null),
            'immatriculation' => $immatriculation ? mb_strtoupper($immatriculation) : null,
        ];
    }

    private static function immatriculation(?string $valeur): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $valeur));
    }

    private static function avertissement(string $cle, array $valeurs = []): array
    {
        return ['message_cle' => $cle, 'valeurs' => $valeurs, 'message' => ErreurMetier::texte($cle, $valeurs)];
    }
}
