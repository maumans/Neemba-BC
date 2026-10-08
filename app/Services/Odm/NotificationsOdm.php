<?php

namespace App\Services\Odm;

use App\Models\OrdreMission;
use App\Models\Service;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;

/**
 * Notifications des ODM (RG-M12-24) : création (soumission), validation, prolongation et clôture sont notifiées
 * à la liste de diffusion du service émetteur (services.diffusion_odm : chef d'atelier, logistique, assistante…),
 * plus les personnes qui doivent agir (valideurs, DAF pour une dérogation, demandeur).
 *
 * Le lien de la notification ouvre la fiche de l'ODM (metadata.odm_id).
 */
final class NotificationsOdm
{
    /** Liste de diffusion du service émetteur */
    public static function diffusion(OrdreMission $odm): Collection
    {
        $ids = (array) (Service::where('nom', $odm->service)->value('diffusion_odm') ?? []);
        $ids = is_string($ids) ? (json_decode($ids, true) ?? []) : $ids;

        return User::actifs()->whereIn('id', array_filter($ids, 'is_numeric'))->get();
    }

    public static function soumission(OrdreMission $odm, User $auteur): void
    {
        $libelle = self::libelle($odm);
        $message = "{$auteur->nom_complet} a soumis l'ordre de mission {$libelle} ("
            . self::periode($odm) . ', ' . implode(', ', $odm->destinations ?? []) . ').';

        self::envoyer(self::diffusion($odm), $auteur, $odm, 'odm_soumis', "Ordre de mission {$libelle}", $message);

        $etape = CircuitOdm::etapeEnCours($odm);
        if ($etape) {
            self::envoyer(CircuitOdm::valideursPossibles($odm, $etape->role), $auteur, $odm, 'odm_a_viser',
                "ODM {$libelle} à viser", "{$message} Votre visa est attendu.");
        }
    }

    public static function derogationDemandee(OrdreMission $odm, User $demandeur): void
    {
        $dafs = User::actifs()->where(fn ($q) => $q->whereIn('role', ['daf', 'daf_adjoint'])
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', ['daf', 'daf_adjoint'])))->get();

        self::envoyer($dafs, $demandeur, $odm, 'odm_derogation', 'Dérogation demandée : chevauchement de missions',
            "{$demandeur->nom_complet} demande une dérogation au chevauchement pour l'ordre de mission " . self::libelle($odm)
            . " : {$odm->derogation_demande_motif}");
    }

    public static function derogationDecidee(OrdreMission $odm, User $daf): void
    {
        $accordee = $odm->derogation_statut === 'accordee';
        self::envoyer(collect([$odm->demandeur]), $daf, $odm, 'odm_derogation',
            $accordee ? 'Dérogation accordée' : 'Dérogation refusée',
            "{$daf->nom_complet} a " . ($accordee ? 'accordé' : 'refusé') . " la dérogation au chevauchement de l'ordre de mission "
            . self::libelle($odm) . " : {$odm->derogation_motif}" . ($accordee ? ' Vous pouvez le soumettre.' : ''));
    }

    public static function envoyer(Collection $destinataires, ?User $expediteur, OrdreMission $odm, string $type, string $titre, string $message): void
    {
        foreach ($destinataires->filter()->unique('id') as $destinataire) {
            NotificationService::creerEtDiffuserSimple($destinataire, $expediteur, $type, $titre, $message, [
                'odm_id' => $odm->id,
                'odm_numero' => $odm->numero,
            ]);
        }
    }

    public static function libelle(OrdreMission $odm): string
    {
        return $odm->numero ?? 'en brouillon';
    }

    private static function periode(OrdreMission $odm): string
    {
        return 'du ' . \App\Support\Format::date($odm->date_depart) . ' au ' . \App\Support\Format::date($odm->date_retour_prevue);
    }
}
