<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un ODM (RG-M12-22).
 *
 * - Par le demandeur, tant qu'aucun bon n'est généré.
 * - Ensuite, par le DAF (ou le DAF adjoint) seulement, avec annulation des bons non payés.
 * - Un ODM dont un bon est payé ne s'annule pas : il se clôture avec régularisation.
 * Les étapes encore ouvertes du circuit sont closes.
 */
final class AnnulerOdm
{
    public const STATUTS_ANNULABLES_PAR_LE_DEMANDEUR = ['BROUILLON', 'SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE'];

    public const STATUTS_ANNULABLES_PAR_LE_DAF = ['SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE', 'BONS_GENERES'];

    public static function parLeDemandeur(OrdreMission $odm, User $auteur, ?string $motif): OrdreMission
    {
        return DB::transaction(function () use ($odm, $auteur, $motif) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();

            if (!in_array($auteur->id, [$odm->demandeur_id, $odm->initiateur_id], true)) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-020', [], 'RG-M12-22', null, 403);
            }
            if (!in_array($odm->statut, self::STATUTS_ANNULABLES_PAR_LE_DEMANDEUR, true) || $odm->bons()->exists()) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-020', [], 'RG-M12-22', null, 409);
            }

            return self::annuler($odm, $auteur, $motif ?: 'Brouillon annulé par le demandeur.');
        });
    }

    /** Le DAF peut annuler un ODM dont les bons sont générés, si aucun n'est payé */
    public static function peutAnnulerParLeDaf(OrdreMission $odm, User $utilisateur): bool
    {
        return $utilisateur->aLeRole(OrdreMission::ROLES_DEROGATION)
            && in_array($odm->statut, self::STATUTS_ANNULABLES_PAR_LE_DAF, true)
            && !self::aUnBonPaye($odm);
    }

    public static function parLeDaf(OrdreMission $odm, User $daf, ?string $motif): OrdreMission
    {
        $motif = trim((string) $motif);
        if (mb_strlen($motif) < 10) {
            throw new ErreurMetier('MOTIF_ANNULATION', 'MSG-APP-039', [], 'RG-M12-22', 'motif');
        }

        return DB::transaction(function () use ($odm, $daf, $motif) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            if (!$daf->aLeRole(OrdreMission::ROLES_DEROGATION)) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-038', [], 'RG-M12-22', null, 403);
            }
            if (self::aUnBonPaye($odm)) {
                throw new ErreurMetier('BON_DEJA_PAYE', 'MSG-APP-037', [], 'RG-M12-22', null, 409);
            }
            if (!in_array($odm->statut, self::STATUTS_ANNULABLES_PAR_LE_DAF, true)) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-038', [], 'RG-M12-22', null, 409);
            }

            $annules = self::annulerBonsNonPayes($odm->bons()->get(), $daf, "Annulation de l'ordre de mission {$odm->numero} par le DAF : {$motif}");

            return self::annuler($odm, $daf, $motif . ($annules->isNotEmpty() ? ' Bons annulés : ' . $annules->pluck('numero')->implode(', ') . '.' : ''));
        });
    }

    /**
     * Annule les bons non payés (et non déjà annulés) : étapes de validation en attente retirées, journal du bon.
     *
     * @param Collection<int, BonCaisse> $bons
     * @return Collection<int, BonCaisse> bons annulés
     */
    public static function annulerBonsNonPayes(Collection $bons, ?User $auteur, string $motif): Collection
    {
        $annules = collect();
        foreach ($bons as $bon) {
            if ($bon->statut === 'ANNULE' || in_array($bon->statut, PaiementOdm::STATUTS_PAYES, true)) {
                continue;
            }
            $statutAvant = $bon->statut;
            $bon->validations()->where('statut', 'en_attente')->delete();
            $bon->forceFill(['statut' => 'ANNULE', 'motif_annulation' => mb_substr($motif, 0, 1000), 'date_annulation' => now()])->saveQuietly();
            HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_ANNULATION, $statutAvant, 'ANNULE', $auteur?->id, $motif);
            $annules->push($bon);
        }

        return $annules;
    }

    private static function aUnBonPaye(OrdreMission $odm): bool
    {
        return $odm->bons()->whereIn('statut', PaiementOdm::STATUTS_PAYES)->exists();
    }

    private static function annuler(OrdreMission $odm, User $auteur, string $motif): OrdreMission
    {
        $statutAvant = $odm->statut;
        $odm->etapes()->whereIn('statut', ['en_attente', 'a_venir'])->update(['statut' => 'annulee']);
        $odm->update([
            'statut' => 'ANNULE',
            'date_annulation' => now(),
            'annule_par_id' => $auteur->id,
            'motif_annulation' => $motif,
        ]);
        HistoriqueOdm::enregistrer($odm, 'annulation', $statutAvant, 'ANNULE', $auteur->id, $motif);

        return $odm;
    }
}
