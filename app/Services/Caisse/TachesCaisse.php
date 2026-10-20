<?php

namespace App\Services\Caisse;

use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Models\DemandeReapprovisionnement;
use App\Models\MouvementCaisse;
use App\Models\PvArrete;
use App\Models\ReconciliationOm;
use App\Models\TauxChange;
use App\Models\User;

/**
 * Travail en attente d'un utilisateur côté caisses et trésorerie, pour le bloc « À traiter » du tableau de bord : chacun
 * voit ce que lui seul peut faire maintenant (trésorerie, Chef comptable, DAF, DP, membres du CODIR, gérants de caisse).
 */
final class TachesCaisse
{
    /** @return array<int, array{cle: string, libelle: string, nombre: int, lien: string}> */
    public static function pour(User $u): array
    {
        $taches = [];
        $ajouter = function (string $cle, string $libelle, int $nombre, string $lien) use (&$taches) {
            if ($nombre > 0) {
                $taches[] = compact('cle', 'libelle', 'nombre', 'lien');
            }
        };

        /* Réapprovisionnements : validation, visa, approbation, exécution, réception */
        $demandes = DemandeReapprovisionnement::with('caisse')->whereNotIn('statut', ['recue', 'refusee'])->get()
            ->filter(fn (DemandeReapprovisionnement $d) => Reapprovisionnement::actions($u, $d) !== []);
        $ajouter('reapprovisionnements', 'Demandes de réapprovisionnement à traiter', $demandes->count(), route('reapprovisionnements.index'));

        /* PV d'arrêté à signer (90 derniers jours) */
        $pvs = PvArrete::with('caisse')->where('arrete_le', '>=', now()->subDays(90))->get()
            ->filter(fn (PvArrete $pv) => !$pv->estSigne() && ArreteCaisse::signaturesPossibles($u, $pv) !== []);
        $ajouter('pv', "PV d'arrêté à signer", $pvs->count(), route('pv-arrete.index'));

        /* Réconciliations Orange Money à valider */
        $reconciliations = ReconciliationOm::where('statut', 'a_valider')->get()
            ->filter(fn (ReconciliationOm $r) => ReconciliationOrangeMoney::signaturesPossibles($u, $r) !== []);
        $ajouter('reconciliations', 'Réconciliations Orange Money à valider', $reconciliations->count(), route('reconciliations-om.index'));

        /* Ajustements d'inventaire en attente du DAF */
        if ($u->aLeRole(['daf', 'daf_adjoint'])) {
            $ajouter('ajustements', "Ajustements d'inventaire à valider", MouvementCaisse::where('statut', 'en_attente')->where('type', 'ajustement_inventaire')->count(),
                route('mouvements-caisse.index', ['statut' => 'en_attente']));
        }

        /* Taux de change du jour (paiement des ODM extérieurs) */
        if ($u->aLeRole('tresorerie') && !TauxChange::duJour()) {
            $ajouter('taux', 'Taux de change FCFA → GNF du jour à saisir', 1, route('tresorerie.taux.index'));
        }

        /* Caisses sous leur seuil ; journée du gérant à clôturer */
        $caisses = AccesCaisse::caissesVisibles($u);
        if ($u->aLeRole(['tresorerie', 'chef_comptable']) || $caisses->contains(fn (Caisse $c) => AccesCaisse::estGerant($u, $c))) {
            $ajouter('seuils', 'Caisses sous leur seuil de réapprovisionnement', $caisses->filter(fn (Caisse $c) => $c->sousSeuil())->count(),
                route('mouvements-caisse.index'));
        }
        /* En fin de journée seulement (à défaut, clôture automatique à 23 h 45) */
        if (now()->hour >= 17) {
            $aCloturer = $caisses->filter(fn (Caisse $c) => AccesCaisse::estGerant($u, $c) && !ClotureCaisse::estClose($c->id, today()))->count();
            $ajouter('cloture', 'Journée de caisse à clôturer', $aCloturer, route('clotures-caisse.index'));
        }

        return $taches;
    }
}
