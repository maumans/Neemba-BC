<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\HistoriqueAction;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Soumission d'un bon (US-BC-12, RG-BC-27, RG-BC-28 ; resoumission après rejet RG-BC-30).
 *
 * Dans une seule transaction, bon verrouillé :
 * - les 12 contrôles sont refaits ; un contrôle bloquant refuse la soumission sans consommer de numéro ;
 * - le numéro BC-AAAA-NNNN est attribué (un bon resoumis garde le sien et passe à la version suivante) ;
 * - les étapes de validation sont créées, statut EN_ATTENTE_CHEF_SERVICE.
 * La même clé d'idempotence rejouée renvoie le bon déjà soumis (double clic).
 * Les valideurs sont notifiés après la validation de la transaction.
 */
class SoumettreBon
{
    /**
     * @return array{0: BonCaisse, 1: array} le bon soumis et ses contrôles
     */
    public static function executer(BonCaisse $bon, User $auteur, ?string $cleIdempotence = null): array
    {
        [$bon, $controles, $nouvelle] = DB::transaction(function () use ($bon, $auteur, $cleIdempotence) {
            $bon = BonCaisse::whereKey($bon->id)->lockForUpdate()->firstOrFail();

            /* Double clic / nouvel envoi de la même demande : résultat déjà acquis */
            if ($cleIdempotence && $bon->cle_soumission === $cleIdempotence && !in_array($bon->statut, ['BROUILLON', 'REJETE'], true)) {
                return [$bon, ControlesBon::executer($bon), false];
            }

            if (!in_array($bon->statut, ['BROUILLON', 'REJETE'], true)) {
                throw new ErreurMetier('BON_DEJA_SOUMIS', 'MSG-APP-001', [], 'RG-BC-27', null, 409);
            }

            $controles = ControlesBon::executer($bon);
            $bloquants = ControlesBon::bloquants($controles);
            if ($bloquants) {
                $premier = $bloquants[0];
                throw new ErreurMetier(
                    $premier['code'],
                    $premier['message_cle'] ?? 'MSG-BC-040',
                    $premier['valeurs'],
                    $premier['regle'] ?? 'RG-BC-24',   // règle du contrôle en échec (SFD §5.7)
                    $premier['champ'],
                    422,
                    ['controles' => $controles],
                );
            }

            $statutAvant = $bon->statut;
            $resoumission = $statutAvant === 'REJETE';

            $bon->numero ??= NumeroteurBon::prochain();
            if ($resoumission) {
                $bon->version = ($bon->version ?? 1) + 1;
            }
            $bon->caisse_id = Caisse::payeusePour((string) $bon->site, $bon->mode_paiement)?->id;
            $bon->statut = 'EN_ATTENTE_CHEF_SERVICE';
            $bon->date_soumission = now();
            $bon->cle_soumission = $cleIdempotence;
            $bon->save();

            $bon->creerEtapesValidation();

            HistoriqueAction::enregistrer(
                $bon,
                HistoriqueAction::ACTION_SOUMISSION,
                $statutAvant,
                $bon->statut,
                $auteur->id,
                $resoumission ? "Bon corrigé et resoumis (version {$bon->version})." : 'Bon soumis pour validation.',
            );

            return [$bon, $controles, true];
        });

        if ($nouvelle) {
            NotificationService::notifierSoumission($bon->fresh(['demandeur']), $bon->demandeur);
        }

        return [$bon, $controles];
    }
}
