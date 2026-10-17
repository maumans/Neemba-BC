<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\User;
use App\Models\Validation;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un bon, avec un motif (US-BC-15 ; spec v2.3 RG-M04-07) :
 * - par le demandeur (ou l'initiateur) tant qu'aucun visa n'est donné : brouillon, rejeté, ou soumis sans visa ;
 * - ensuite par le DAF seulement (DAF ou DAF adjoint), jusqu'à l'approbation ;
 * - un bon payé ne s'annule jamais.
 * Le bon n'est plus modifiable ; s'il avait un numéro, ce numéro n'est jamais réutilisé
 * (le compteur ne revient pas en arrière). Sans auteur : annulation automatique d'un brouillon abandonné (RG-BC-26).
 */
class AnnulerBon
{
    /** Rôles qui annulent un bon déjà visé (RG-M04-07) */
    public const ROLES_DAF = ['daf', 'daf_adjoint'];

    /** Statuts d'un bon soumis, pas encore payé */
    public const STATUTS_EN_CIRCUIT = ['EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP', 'APPROUVE'];

    public static function executer(BonCaisse $bon, ?User $auteur, string $motif): BonCaisse
    {
        return DB::transaction(function () use ($bon, $auteur, $motif) {
            $bon = BonCaisse::whereKey($bon->id)->lockForUpdate()->firstOrFail();

            if (!($auteur ? self::peutAnnuler($bon, $auteur) : in_array($bon->statut, ['BROUILLON', 'REJETE'], true))) {
                throw new ErreurMetier('ANNULATION_IMPOSSIBLE', 'MSG-BC-034', [], 'RG-M04-07', null, 409);
            }

            $statutAvant = $bon->statut;
            $parLeDaf = $auteur && !self::estAuteur($bon, $auteur);
            $bon->statut = 'ANNULE';
            $bon->motif_annulation = $motif;
            $bon->date_annulation = now();
            $bon->save();

            HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_ANNULATION, $statutAvant, 'ANNULE', $auteur?->id,
                ($parLeDaf ? 'Annulé par le DAF : ' : '') . $motif);

            return $bon;
        });
    }

    /** RG-M04-07 : l'utilisateur peut-il annuler ce bon maintenant ? */
    public static function peutAnnuler(BonCaisse $bon, User $utilisateur): bool
    {
        if (self::estAuteur($bon, $utilisateur)
            && (in_array($bon->statut, ['BROUILLON', 'REJETE'], true) || (str_starts_with($bon->statut, 'EN_ATTENTE_') && !self::aUnVisa($bon)))) {
            return true;
        }

        return $utilisateur->aLeRole(self::ROLES_DAF)
            && in_array($bon->statut, array_merge(['REJETE'], self::STATUTS_EN_CIRCUIT), true);
    }

    /** Un visa a-t-il été donné sur la version en cours ? (une étape sautée n'est pas un visa) */
    public static function aUnVisa(BonCaisse $bon): bool
    {
        return Validation::where('bon_caisse_id', $bon->id)
            ->where('statut', 'approuve')
            ->when($bon->version, fn ($q) => $q->where('version', $bon->version))
            ->exists();
    }

    private static function estAuteur(BonCaisse $bon, User $utilisateur): bool
    {
        return in_array($utilisateur->id, array_filter([$bon->demandeur_id, $bon->initiateur_id]), true);
    }
}
