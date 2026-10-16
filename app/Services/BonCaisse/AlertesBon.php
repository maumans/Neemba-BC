<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\Parametre;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * Alertes anti-fraude du bon (spec v2.3) :
 * - RG-M03-20 : doublon probable (même bénéficiaire, même montant, sur N jours) ; le demandeur confirme, l'alerte reste
 *   visible des valideurs (RG-M04-05) ;
 * - RG-M03-21, RG-M07-06 : BP du bénéficiaire en retard de régularisation ; selon le paramètre « blocage BP en retard »
 *   (aucun, partiel, total), le bon est signalé ou bloqué ; le DAF peut accorder une dérogation motivée pour ce bon.
 */
final class AlertesBon
{
    /** Rôles qui décident d'une dérogation « BP en retard » (RG-M07-06 : le DAF) */
    public const ROLES_DEROGATION = ['daf', 'daf_adjoint'];

    /**
     * RG-M04-05 : alertes du bon telles qu'elles s'affichent, sur la fiche du bon comme sur l'écran de visa.
     * Doublon probable confirmé par le demandeur (RG-M03-20), BP du bénéficiaire en retard (RG-M03-21),
     * dérogation du DAF (RG-M07-06).
     */
    public static function pourAffichage(BonCaisse $bon): array
    {
        return [
            'doublon' => $bon->doublon_probable ? $bon->doublon_probable + [
                'texte' => ErreurMetier::texte('MSG-M03-12', array_intersect_key($bon->doublon_probable, array_flip(['montant', 'beneficiaire', 'numero', 'date']))),
                'confirme_le_format' => Format::dateHeure($bon->doublon_probable['confirme_le'] ?? null),
            ] : null,
            'bp_en_retard' => self::bpEnRetard($bon)->map(fn (BonCaisse $bp) => [
                'id' => $bp->id,
                'numero' => $bp->numero,
                'montant' => (float) $bp->montant,
                'echeance' => Format::date($bp->date_limite_regularisation),
            ])->values()->all(),
            'derogation' => $bon->derogation_bp ? $bon->derogation_bp + [
                'demande_le_format' => Format::dateHeure($bon->derogation_bp['demande_le'] ?? null),
                'decide_le_format' => isset($bon->derogation_bp['decide_le']) ? Format::dateHeure($bon->derogation_bp['decide_le']) : null,
            ] : null,
        ];
    }

    /** Autre bon soumis du même bénéficiaire et du même montant, dans les N derniers jours (paramètre) */
    public static function doublonProbable(BonCaisse $bon): ?BonCaisse
    {
        if ($bon->genere_par_odm || !(float) $bon->montant
            || (!$bon->beneficiaire_id && !$bon->tiers_id && !$bon->visiteur_id && blank($bon->beneficiaire))) {
            return null;
        }
        $jours = (int) Parametre::valeur('doublon_delai_jours', 7);
        $reference = $bon->date_soumission && !in_array($bon->statut, ['BROUILLON', 'REJETE'], true) ? $bon->date_soumission : now();

        return BonCaisse::whereKeyNot($bon->id)
            ->whereNotIn('statut', ['BROUILLON', 'ANNULE'])
            ->where('montant', $bon->montant)
            ->whereBetween('date_soumission', [$reference->copy()->subDays($jours), $reference])
            ->where(fn ($q) => match (true) {
                (bool) $bon->beneficiaire_id => $q->where('beneficiaire_id', $bon->beneficiaire_id),
                (bool) $bon->tiers_id => $q->where('tiers_id', $bon->tiers_id),
                (bool) $bon->visiteur_id => $q->where('visiteur_id', $bon->visiteur_id),
                default => $q->whereNull('beneficiaire_id')->where('beneficiaire', $bon->beneficiaire),
            })
            ->latest('date_soumission')
            ->first();
    }

    /** Valeurs de MSG-M03-12 */
    public static function valeursDoublon(BonCaisse $autre): array
    {
        return [
            'bon_id' => $autre->id,
            'montant' => (float) $autre->montant,
            'beneficiaire' => $autre->beneficiaire,
            'numero' => $autre->numero,
            'date' => $autre->date_soumission?->toDateString(),
        ];
    }

    /** Le demandeur confirme qu'il ne s'agit pas d'un doublon ; la confirmation est conservée pour les valideurs */
    public static function confirmerDoublon(BonCaisse $bon, User $auteur): BonCaisse
    {
        $autre = self::doublonProbable($bon);
        if (!$autre) {
            return $bon;
        }
        $bon->update(['doublon_probable' => self::valeursDoublon($autre) + [
            'confirme_par' => $auteur->nom_complet,
            'confirme_le' => now()->toIso8601String(),
        ]]);
        HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_DOUBLON, $bon->statut, $bon->statut, $auteur->id,
            'Doublon probable confirmé par le demandeur : bon ' . ($autre->numero ?? '') . ' du ' . Format::date($autre->date_soumission)
            . ' (' . Format::montant($autre->montant) . ').');

        return $bon;
    }

    /** La confirmation enregistrée porte-t-elle sur le doublon trouvé aujourd'hui ? */
    public static function doublonConfirme(BonCaisse $bon, BonCaisse $autre): bool
    {
        return ($bon->doublon_probable['bon_id'] ?? null) === $autre->id && !empty($bon->doublon_probable['confirme_le']);
    }

    /** BP du bénéficiaire (salarié ou visiteur du groupe) non régularisés au-delà de leur échéance */
    public static function bpEnRetard(BonCaisse $bon): Collection
    {
        if (!$bon->beneficiaire_id && !$bon->visiteur_id) {
            return collect();
        }

        return BonCaisse::enRetardRegularisation()
            ->where('type_bon', 'BP')
            ->when($bon->beneficiaire_id, fn ($q) => $q->where('beneficiaire_id', $bon->beneficiaire_id),
                fn ($q) => $q->where('visiteur_id', $bon->visiteur_id))
            ->whereKeyNot($bon->id)
            ->orderBy('date_limite_regularisation')
            ->get();
    }

    /** Paramètre « blocage BP en retard » : aucun, partiel (défaut) ou total */
    public static function modeBlocage(): string
    {
        $mode = (string) Parametre::valeur('blocage_bp_en_retard', 'partiel');

        return in_array($mode, ['aucun', 'partiel', 'total'], true) ? $mode : 'partiel';
    }

    /** Le bon serait-il bloqué par un BP en retard du bénéficiaire (hors dérogation) ? */
    public static function bloqueParBpEnRetard(BonCaisse $bon, ?Collection $retards = null): bool
    {
        $retards ??= self::bpEnRetard($bon);
        if ($retards->isEmpty() || $bon->genere_par_odm) {
            return false;
        }

        return match (self::modeBlocage()) {
            'total' => true,
            'partiel' => $bon->type_bon === 'BP',
            default => false,
        };
    }

    public static function derogationAccordee(BonCaisse $bon): bool
    {
        return ($bon->derogation_bp['statut'] ?? null) === 'accordee';
    }

    /** Valeurs de MSG-M03-13 / MSG-M03-14 pour le plus ancien BP en retard */
    public static function valeursRetard(BonCaisse $bon, BonCaisse $retard): array
    {
        return [
            'beneficiaire' => $bon->beneficiaire,
            'numero' => $retard->numero,
            'date' => $retard->date_limite_regularisation?->toDateString(),
        ];
    }

    /** Le demandeur d'un bon bloqué demande une dérogation au DAF (RG-M07-06) */
    public static function demanderDerogation(BonCaisse $bon, User $auteur, string $motif): BonCaisse
    {
        if (!in_array($bon->statut, ['BROUILLON', 'REJETE'], true) || !self::bloqueParBpEnRetard($bon)) {
            throw new ErreurMetier('DEROGATION_INUTILE', 'MSG-APP-054', [], 'RG-M07-06', 'derogation', 409);
        }
        if (in_array($bon->derogation_bp['statut'] ?? null, ['demandee', 'accordee'], true)) {
            throw new ErreurMetier('DEROGATION_DEJA_DEMANDEE', 'MSG-APP-055', [], 'RG-M07-06', 'derogation', 409);
        }
        $bon->update(['derogation_bp' => [
            'statut' => 'demandee',
            'demande_motif' => trim($motif),
            'demande_par' => $auteur->nom_complet,
            'demande_le' => now()->toIso8601String(),
        ]]);
        HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_DEROGATION, $bon->statut, $bon->statut, $auteur->id,
            'Dérogation « BP en retard » demandée au DAF : ' . trim($motif));

        $dafs = User::actifs()->where(fn ($q) => $q->whereIn('role', self::ROLES_DEROGATION)
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', self::ROLES_DEROGATION)))->get();
        NotificationService::notifierDerogationBp($bon, $auteur, $dafs, 'Dérogation demandée : BP en retard',
            "{$auteur->nom_complet} demande une dérogation pour un bon de " . Format::montant($bon->montant)
            . " au profit de {$bon->beneficiaire}, qui a un bon provisoire en retard : " . trim($motif));

        return $bon;
    }

    /** Le DAF accorde ou refuse la dérogation, avec un motif (tracé) */
    public static function deciderDerogation(BonCaisse $bon, User $daf, bool $accorder, string $motif): BonCaisse
    {
        if (($bon->derogation_bp['statut'] ?? null) !== 'demandee') {
            throw new ErreurMetier('DEROGATION_ABSENTE', 'MSG-APP-056', [], 'RG-M07-06', null, 409);
        }
        $bon->update(['derogation_bp' => array_merge($bon->derogation_bp, [
            'statut' => $accorder ? 'accordee' : 'refusee',
            'decision_motif' => trim($motif),
            'decide_par' => $daf->nom_complet,
            'decide_le' => now()->toIso8601String(),
        ])]);
        HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_DEROGATION, $bon->statut, $bon->statut, $daf->id,
            'Dérogation « BP en retard » ' . ($accorder ? 'accordée' : 'refusée') . ' : ' . trim($motif));

        $bon->loadMissing(['demandeur', 'initiateur']);
        NotificationService::notifierDerogationBp($bon, $daf, collect([$bon->initiateur ?? $bon->demandeur])->filter(),
            'Dérogation ' . ($accorder ? 'accordée' : 'refusée'),
            "{$daf->nom_complet} a " . ($accorder ? 'accordé' : 'refusé') . " la dérogation « BP en retard » : " . trim($motif)
            . ($accorder ? ' Vous pouvez soumettre le bon.' : ''));

        return $bon;
    }
}
