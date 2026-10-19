<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\Parametre;
use App\Models\TypeOr;
use App\Models\User;
use App\Support\JoursOuvres;
use Illuminate\Support\Facades\DB;

/**
 * « OR à régulariser » (spec v2.4, RG-M12-39, RG-M12-40, SC-38) : une mission technique dont l'OR n'est pas encore connu
 * est soumise avec l'indicateur ; l'ODM suit son circuit et ses bons peuvent être générés. L'OR est saisi après coup par
 * le demandeur ou le chef d'atelier, chaque saisie étant tracée ; l'indicateur tombe au premier OR rattaché. Les OR
 * ajoutés sont reportés sur les bons de l'ODM (Q80). Distinct de la régularisation des BP (M07).
 */
final class RegulariserOr
{
    /** Statuts où un OR se saisit après coup (avant : le formulaire de l'ODM) */
    public const STATUTS = ['SOUMIS', 'EN_VALIDATION', 'VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE'];

    /** RG-M12-40 : échéance de régularisation, en jours ouvrés après la validation finale */
    public static function echeance(\Carbon\CarbonInterface $validation): \Carbon\CarbonImmutable
    {
        return JoursOuvres::ajouter($validation, max(1, (int) Parametre::valeur('odm_delai_or', 5)));
    }

    /** Demandeur (ou initiateur) de l'ODM, ou chef d'atelier titulaire de son étape */
    public static function peutAjouter(OrdreMission $odm, User $utilisateur): bool
    {
        if (!in_array($odm->statut, self::STATUTS, true)) {
            return false;
        }

        return in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
            || CircuitOdm::titulaires($odm, 'chef_atelier')->contains('id', $utilisateur->id);
    }

    /**
     * OR saisi après coup ; l'indicateur « OR à régulariser » tombe ; les bons de l'ODM le reprennent.
     *
     * @throws ErreurMetier
     */
    public static function ajouter(OrdreMission $odm, User $auteur, string $numero, string $type, ?int $clientId = null): OrdreReparationOdm
    {
        if (!self::peutAjouter($odm, $auteur)) {
            throw new ErreurMetier('OR_AJOUT_INTERDIT', 'MSG-APP-087', [], 'RG-M12-39', null, 403);
        }
        $numero = preg_replace('/\D/', '', $numero);
        if (!preg_match(OrdreReparationOdm::FORMAT, $numero)) {
            throw new ErreurMetier('OR_INVALIDE', 'MSG-M03-07', [], 'RG-M03-13', 'numero');
        }
        if (!in_array($type, TypeOr::codesActifs(), true)) {
            throw new ErreurMetier('TYPE_OR_INCONNU', 'MSG-APP-062', ['numero' => $numero], 'RG-M03-13', 'type');
        }
        if ($odm->ordresReparation()->where('numero', $numero)->exists()) {
            throw new ErreurMetier('OR_DEJA_RATTACHE', 'MSG-APP-086', ['numero' => $numero], 'RG-M12-39', 'numero');
        }

        return DB::transaction(function () use ($odm, $auteur, $numero, $type, $clientId) {
            $avant = $odm->ordresReparation()->pluck('numero')->all();
            $or = $odm->ordresReparation()->create([
                'numero' => $numero, 'type' => $type, 'client_id' => $clientId,
                'saisi_apres_coup_le' => now(), 'saisi_par' => $auteur->id,
            ]);
            $regularise = $odm->or_a_regulariser;
            if ($regularise) {
                $odm->update(['or_a_regulariser' => false]);
            }
            HistoriqueOdm::enregistrer($odm, 'or_ajoute', $odm->statut, $odm->statut, $auteur->id,
                "OR {$numero} (" . TypeOr::libelle($type) . ") rattaché après coup par {$auteur->nom_complet}" . ($regularise ? ' ; OR régularisé.' : '.'),
                ['avant' => $avant, 'apres' => [...$avant, $numero]]);

            /* Q80 : l'OR est reporté sur les bons de l'ODM, avec trace dans leur journal */
            foreach (BonCaisse::where('odm_id', $odm->id)->where('statut', '!=', 'ANNULE')->get() as $bon) {
                $references = array_values(array_unique([...((array) $bon->references_or), $numero]));
                $types = [...((array) $bon->types_or), $numero => $type];
                $bon->modifierAvecJournal(['references_or' => $references, 'types_or' => $types], HistoriqueAction::ACTION_OR_AJOUTE,
                    "OR {$numero} rattaché après coup à l'ODM {$odm->numero} par {$auteur->nom_complet}");
            }

            return $or;
        });
    }

    /** RG-M12-40 : ODM du demandeur dont l'OR à régulariser est en retard (blocage du prochain ODM, si le paramètre est actif) */
    public static function odmEnRetard(User $demandeur): ?OrdreMission
    {
        return OrdreMission::withoutGlobalScope('entite')
            ->where('demandeur_id', $demandeur->id)
            ->where('or_a_regulariser', true)
            ->whereNotNull('or_echeance')
            ->whereDate('or_echeance', '<', today())
            ->whereNotIn('statut', ['ANNULE', 'REJETE', 'BROUILLON'])
            ->orderBy('or_echeance')
            ->first();
    }

    /** MSG-M12-11 si le blocage est activé et qu'un ODM du demandeur a un OR en retard */
    public static function verifierBlocage(User $demandeur): void
    {
        if (!(bool) Parametre::valeur('odm_blocage_or_retard', false)) {
            return;
        }
        if ($enRetard = self::odmEnRetard($demandeur)) {
            throw new ErreurMetier('OR_EN_RETARD', 'MSG-M12-11', ['numero' => $enRetard->numero], 'RG-M12-40', null);
        }
    }
}
