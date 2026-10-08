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
        $quoi = $odm->estProlongation() ? "la prolongation {$libelle} ({$odm->libelle_prolongation})" : "l'ordre de mission {$libelle}";
        $message = "{$auteur->nom_complet} a soumis {$quoi} ("
            . self::periode($odm) . ', ' . implode(', ', $odm->destinations ?? []) . ').';

        self::envoyer(self::diffusion($odm), $auteur, $odm, 'odm_soumis', "Ordre de mission {$libelle}", $message);

        $etape = CircuitOdm::etapeEnCours($odm);
        if ($etape) {
            self::aViser($odm, $etape, $auteur);
        }
    }

    /** Valideurs de l'étape qui s'ouvre (titulaires compatibles et suppléants) */
    public static function aViser(OrdreMission $odm, \App\Models\EtapeOdm $etape, ?User $expediteur): void
    {
        $libelle = self::libelle($odm);
        self::envoyer(CircuitOdm::valideursPossibles($odm, $etape->role), $expediteur, $odm, 'odm_a_viser',
            "ODM {$libelle} à viser",
            "L'ordre de mission {$libelle} (" . self::periode($odm) . ', ' . implode(', ', $odm->destinations ?? [])
            . ") attend votre visa ({$etape->libelle}).");
    }

    /** RG-M12-24 et MSG-M12-08 : validation finale, au demandeur et à la liste de diffusion */
    public static function valide(OrdreMission $odm, User $valideur): void
    {
        $message = \App\Exceptions\ErreurMetier::texte('MSG-M12-08', ['numero' => self::libelle($odm)]);
        self::envoyer(collect([$odm->demandeur, $odm->initiateur]), $valideur, $odm, 'odm_valide', 'Ordre de mission validé', $message);
        self::envoyer(self::diffusion($odm), $valideur, $odm, 'odm_valide', 'Ordre de mission validé',
            'L\'ordre de mission ' . self::libelle($odm) . ' (' . self::periode($odm) . ') est validé.');
    }

    /** RG-M12-19, MSG-M12-07 : incohérence nuits / jours sur la mission, signalée au DAF */
    public static function incoherence(OrdreMission $odm, array $incoherences): void
    {
        $dafs = User::actifs()->where(fn ($q) => $q->whereIn('role', OrdreMission::ROLES_DEROGATION)
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', OrdreMission::ROLES_DEROGATION)))->get();
        self::envoyer($dafs, null, $odm->initial(), 'odm_incoherence', 'Mission : nuits incohérentes', implode(' ', $incoherences));
        \App\Models\HistoriqueOdm::enregistrer($odm->initial(), 'incoherence', null, null, null, 'Signalé au DAF : ' . implode(' ', $incoherences));
    }

    /** RG-M12-28 : rappel au demandeur avant la fin d'un segment, pour prolonger ou clôturer */
    public static function rappelFinDeSegment(OrdreMission $odm): void
    {
        self::envoyer(collect([$odm->demandeur, $odm->initiateur]), null, $odm, 'odm_rappel', "Mission {$odm->numero} : fin le " . \App\Support\Format::date($odm->date_retour_prevue),
            "La mission {$odm->numero} (" . implode(', ', $odm->destinations ?? []) . ') se termine le ' . \App\Support\Format::date($odm->date_retour_prevue)
            . ' : prolongez-la si elle continue, ou clôturez-la au retour.');
    }

    /** RG-M12-12 : rejet motivé, au demandeur */
    public static function rejete(OrdreMission $odm, User $valideur, string $motif): void
    {
        self::envoyer(collect([$odm->demandeur, $odm->initiateur]), $valideur, $odm, 'odm_rejete', 'Ordre de mission rejeté',
            "{$valideur->nom_complet} a rejeté l'ordre de mission " . self::libelle($odm) . " : {$motif} Corrigez-le puis soumettez-le à nouveau.");
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

    public static function periode(OrdreMission $odm): string
    {
        return 'du ' . \App\Support\Format::date($odm->date_depart) . ' au ' . \App\Support\Format::date($odm->date_retour_prevue);
    }
}
