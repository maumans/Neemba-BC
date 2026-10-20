<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\DemandeReapprovisionnement as Demande;
use App\Models\Parametre;
use App\Models\PvArrete;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Format;
use App\Support\HeuresOuvrees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Réapprovisionnement d'une caisse (spec v2.4, M08).
 *
 * - RG-M08-05 : la demande exige le rapprochement comptable signé et un PV d'arrêté du jour de la caisse, signé par
 *   son gérant (billetage du jour) ; l'écart du PV est affiché (MSG-M08-03).
 * - RG-M08-06 : montant proposé = plafond − solde (caisse en avance fixe : avance − solde) ; modifiable à la baisse
 *   avec motif.
 * - RG-M08-04 : le solde ne dépasse jamais le plafond de caisse (MSG-M08-02).
 * - RG-M08-07 : circuit caissier → trésorerie → Chef comptable / DAF → DP ou DP adjoint ; rien au-delà de l'habilitation
 *   du DP ; en l'absence du DP et de son adjoint (délai de l'étape écoulé, ou aucun titulaire actif), deux membres du
 *   CODIR approuvent ensemble (Q89). Personne ne vise une demande qu'il a faite.
 * - RG-M08-08 : exécution par la trésorerie, avec l'origine des fonds ; transfert depuis une autre caisse si la caisse
 *   l'autorise.
 * - RG-M08-09 : la caisse n'est créditée qu'à la confirmation de réception ; un écart entre émis et reçu alerte le DAF
 *   (MSG-M08-04).
 */
final class Reapprovisionnement
{
    public const ROLES_FINANCE = ['chef_comptable', 'daf', 'daf_adjoint'];
    public const ROLES_DP = ['directeur_pays', 'dp_adjoint'];

    public static function montantPropose(Caisse $caisse): float
    {
        $cible = $caisse->mode === 'avance_fixe' ? (float) $caisse->montant_avance : (float) $caisse->plafond_caisse;

        return max(0.0, $cible - (float) $caisse->solde);
    }

    /** PV d'arrêté du jour de la caisse, signé par son gérant */
    public static function pvDuJour(Caisse $caisse): ?PvArrete
    {
        return PvArrete::where('caisse_id', $caisse->id)->whereDate('arrete_le', today())->whereNotNull('gerant_le')->latest('arrete_le')->first();
    }

    public static function creer(User $auteur, Caisse $caisse, float $montant, ?string $motifBaisse, ?UploadedFile $rapprochement): Demande
    {
        if (!AccesCaisse::estGerant($auteur, $caisse)) {
            throw new ErreurMetier('REAPPRO_INTERDIT', 'MSG-APP-091', [], 'RG-M08-12', null, 403);
        }
        $pv = self::pvDuJour($caisse);
        if (!$pv || !$rapprochement) {
            throw new ErreurMetier('REAPPRO_PIECES', 'MSG-M08-03', [], 'RG-M08-05', $pv ? 'rapprochement' : 'pv');
        }
        if (Demande::where('caisse_id', $caisse->id)->whereNotIn('statut', ['recue', 'refusee'])->exists()) {
            throw new ErreurMetier('REAPPRO_EN_COURS', 'MSG-APP-092', [], 'RG-M08-07', null);
        }
        $propose = self::montantPropose($caisse);
        if ($montant < $propose && mb_strlen(trim((string) $motifBaisse)) < 10) {
            throw new ErreurMetier('REAPPRO_MOTIF_BAISSE', 'MSG-APP-093', [], 'RG-M08-06', 'motif_baisse');
        }
        self::controlerPlafond($caisse, $montant);
        self::controlerHabilitation($montant);

        $demande = Demande::create([
            'caisse_id' => $caisse->id,
            'numero' => self::numero($caisse),
            'statut' => 'soumise',
            'solde_a_la_demande' => (float) $caisse->solde,
            'montant_propose' => $propose,
            'montant_demande' => $montant,
            'motif_baisse' => $montant < $propose ? trim((string) $motifBaisse) : null,
            'pv_arrete_id' => $pv->id,
            'rapprochement' => $rapprochement->store('reapprovisionnements', 'public'),
            'demande_par' => $auteur->id,
        ]);
        self::notifier($demande, self::titulaires($demande, ['tresorerie']), $auteur, 'Réapprovisionnement à valider',
            "{$auteur->nom_complet} demande " . Format::montant($montant) . " pour la {$caisse->libelle} ({$demande->numero}).");

        return $demande;
    }

    /** Actions possibles de l'utilisateur sur la demande */
    public static function actions(User $utilisateur, Demande $d): array
    {
        $auteur = (int) $d->demande_par === (int) $utilisateur->id;
        $actions = [];
        $viseurs = array_filter([$d->tresorerie_par, $d->finance_par, $d->codir1_par]);
        $dejaVise = in_array($utilisateur->id, array_map('intval', $viseurs), true);
        if ($d->statut === 'soumise' && !$auteur && $utilisateur->aLeRole('tresorerie')) {
            $actions[] = 'valider_tresorerie';
        }
        if ($d->statut === 'validee_tresorerie' && !$auteur && !$dejaVise && $utilisateur->aLeRole(self::ROLES_FINANCE)) {
            $actions[] = 'viser_finance';
        }
        if ($d->statut === 'visee_finance' && !$auteur) {
            if ($utilisateur->aLeRole(self::ROLES_DP)) {
                $actions[] = 'approuver_dp';
            }
            if ($utilisateur->aLeRole('membre_codir') && !$dejaVise && self::codirPossible($d)) {
                $actions[] = 'approuver_codir';
            }
        }
        if (in_array($d->statut, ['soumise', 'validee_tresorerie', 'visee_finance'], true) && !$auteur
            && array_intersect($actions, ['valider_tresorerie', 'viser_finance', 'approuver_dp', 'approuver_codir'])) {
            $actions[] = 'refuser';
        }
        if ($d->statut === 'approuvee' && $utilisateur->aLeRole('tresorerie') && !$auteur) {
            $actions[] = 'executer';
        }
        if ($d->statut === 'en_cours' && $d->caisse && AccesCaisse::estGerant($utilisateur, $d->caisse)) {
            $actions[] = 'confirmer_reception';
        }

        return $actions;
    }

    /** Q89 : CODIR possible si le DP et son adjoint sont absents (aucun titulaire actif, ou délai de l'étape écoulé) */
    public static function codirPossible(Demande $d): bool
    {
        if ($d->statut !== 'visee_finance') {
            return false;
        }
        $dps = User::actifs()->avecRoleDans(self::ROLES_DP, $d->entite_id)->where('id', '!=', $d->demande_par)->exists();

        return !$dps || ($d->finance_le && HeuresOuvrees::echu($d->finance_le, (float) Parametre::valeur('sla_directeur_pays', 24)));
    }

    public static function agir(User $utilisateur, Demande $d, string $action, array $donnees = []): Demande
    {
        if (!in_array($action, self::actions($utilisateur, $d), true)) {
            throw new ErreurMetier('REAPPRO_ACTION_IMPOSSIBLE', 'MSG-APP-094', [], 'RG-M08-07', null, 403);
        }

        return DB::transaction(function () use ($utilisateur, $d, $action, $donnees) {
            $d = Demande::whereKey($d->id)->lockForUpdate()->firstOrFail();
            $caisse = $d->caisse;
            match ($action) {
                'valider_tresorerie' => self::validerTresorerie($utilisateur, $d, $donnees),
                'viser_finance' => self::viser($utilisateur, $d, 'finance', 'visee_finance', self::titulaires($d, self::ROLES_DP),
                    'Réapprovisionnement à approuver'),
                'approuver_dp' => self::viser($utilisateur, $d, 'dp', 'approuvee', self::titulaires($d, ['tresorerie']), 'Réapprovisionnement à exécuter'),
                'approuver_codir' => self::approuverCodir($utilisateur, $d),
                'refuser' => self::refuser($utilisateur, $d, (string) ($donnees['motif'] ?? '')),
                'executer' => self::executer($utilisateur, $d, $donnees),
                'confirmer_reception' => self::confirmerReception($utilisateur, $d, $donnees),
            };

            return $d->fresh();
        });
    }

    private static function validerTresorerie(User $u, Demande $d, array $donnees): void
    {
        $montant = isset($donnees['montant_valide']) && $donnees['montant_valide'] !== '' ? (float) $donnees['montant_valide'] : (float) $d->montant_demande;
        if ($montant > (float) $d->montant_demande) {
            throw new ErreurMetier('REAPPRO_HAUSSE', 'MSG-APP-095', [], 'RG-M08-07', 'montant_valide');
        }
        if ($montant < (float) $d->montant_demande && mb_strlen(trim((string) ($donnees['motif_reduction'] ?? ''))) < 10) {
            throw new ErreurMetier('REAPPRO_MOTIF_REDUCTION', 'MSG-APP-093', [], 'RG-M08-07', 'motif_reduction');
        }
        self::controlerPlafond($d->caisse, $montant);
        $d->update(['montant_valide' => $montant, 'motif_reduction' => $montant < (float) $d->montant_demande ? trim($donnees['motif_reduction']) : null]);
        self::viser($u, $d, 'tresorerie', 'validee_tresorerie', self::titulaires($d, self::ROLES_FINANCE), 'Réapprovisionnement à viser');
    }

    private static function viser(User $u, Demande $d, string $etape, string $statut, Collection $suivants, string $titre): void
    {
        $d->update(["{$etape}_par" => $u->id, "{$etape}_le" => now(), 'statut' => $statut]);
        self::notifier($d, $suivants, $u, $titre, "Réapprovisionnement {$d->numero} de la {$d->caisse->libelle} : " . Format::montant($d->montantRetenu()) . '.');
    }

    private static function approuverCodir(User $u, Demande $d): void
    {
        if (!$d->codir1_par) {
            $d->update(['codir1_par' => $u->id, 'codir1_le' => now()]);
            self::notifier($d, User::actifs()->avecRoleDans(['membre_codir'], $d->entite_id)->where('id', '!=', $u->id)->get(), $u,
                'Second visa du CODIR attendu', "Réapprovisionnement {$d->numero} : un membre du CODIR a approuvé ; un second membre doit approuver.");

            return;
        }
        $d->update(['codir2_par' => $u->id, 'codir2_le' => now(), 'statut' => 'approuvee']);
        self::notifier($d, self::titulaires($d, ['tresorerie']), $u, 'Réapprovisionnement à exécuter',
            "Réapprovisionnement {$d->numero} approuvé par deux membres du CODIR, en l'absence du DP.");
    }

    private static function refuser(User $u, Demande $d, string $motif): void
    {
        if (mb_strlen(trim($motif)) < 10) {
            throw new ErreurMetier('REAPPRO_MOTIF_REFUS', 'MSG-APP-093', [], 'RG-M08-07', 'motif');
        }
        $d->update(['statut' => 'refusee', 'refuse_par' => $u->id, 'refuse_le' => now(), 'motif_refus' => trim($motif)]);
        self::notifier($d, collect([User::find($d->demande_par)]), $u, 'Réapprovisionnement refusé', "Réapprovisionnement {$d->numero} refusé : " . trim($motif));
    }

    private static function executer(User $u, Demande $d, array $donnees): void
    {
        $origine = (string) ($donnees['origine_fonds'] ?? '');
        $caisse = $d->caisse;
        if (!array_key_exists($origine, Demande::ORIGINES) || ($origine === 'transfert' && !$caisse->reappro_par_transfert)
            || ($origine === 'virement' && $caisse->type !== 'orange_money')) {
            throw new ErreurMetier('REAPPRO_ORIGINE', 'MSG-APP-096', [], 'RG-M08-08', 'origine_fonds');
        }
        $montant = (float) ($donnees['montant_emis'] ?? $d->montantRetenu());
        $source = null;
        if ($origine === 'transfert') {
            $source = Caisse::find($donnees['caisse_source_id'] ?? null);
            if (!$source || $source->id === $caisse->id || !$source->actif || !$source->peutPayer($montant)) {
                throw new ErreurMetier('REAPPRO_SOURCE', 'MSG-APP-097', [], 'RG-M08-08', 'caisse_source_id');
            }
            $source->debiter($montant, 'transfert_emis', ['utilisateur_id' => $u->id, 'piece' => $d->numero,
                'libelle' => "Transfert vers la {$caisse->libelle} (réapprovisionnement {$d->numero})"]);
        }
        $d->update([
            'statut' => 'en_cours', 'origine_fonds' => $origine, 'caisse_source_id' => $source?->id,
            'reference_execution' => trim((string) ($donnees['reference'] ?? '')) ?: null,
            'piece_execution' => ($donnees['piece'] ?? null) instanceof UploadedFile ? $donnees['piece']->store('reapprovisionnements', 'public') : null,
            'montant_emis' => $montant, 'execute_par' => $u->id, 'execute_le' => now(),
        ]);
        self::notifier($d, AccesCaisse::gerants($caisse), $u, 'Réapprovisionnement à réceptionner',
            "Réapprovisionnement {$d->numero} exécuté (" . Demande::ORIGINES[$origine] . ', ' . Format::montant($montant) . ') : confirmez le montant reçu.');
    }

    private static function confirmerReception(User $u, Demande $d, array $donnees): void
    {
        $recu = (float) ($donnees['montant_recu'] ?? 0);
        if ($recu <= 0 || !(($donnees['piece'] ?? null) instanceof UploadedFile)) {
            throw new ErreurMetier('REAPPRO_RECEPTION', 'MSG-APP-098', [], 'RG-M08-09', $recu <= 0 ? 'montant_recu' : 'piece');
        }
        $caisse = $d->caisse;
        $caisse->crediter($recu, 'approvisionnement', ['utilisateur_id' => $u->id, 'piece' => $d->numero,
            'libelle' => "Réapprovisionnement {$d->numero} reçu (" . (Demande::ORIGINES[$d->origine_fonds] ?? $d->origine_fonds) . ')']);
        $d->update(['statut' => 'recue', 'montant_recu' => $recu, 'piece_reception' => $donnees['piece']->store('reapprovisionnements', 'public'),
            'recu_par' => $u->id, 'recu_le' => now()]);

        if (abs($recu - (float) $d->montant_emis) > 0.004) {
            $message = \App\Exceptions\ErreurMetier::texte('MSG-M08-04', ['numero' => $d->numero, 'x' => (float) $d->montant_emis, 'y' => $recu]);
            self::notifier($d, User::actifs()->avecRoleDans(['daf', 'daf_adjoint'], $d->entite_id)->get(), $u, 'Écart de réception', $message);
        }
    }

    private static function controlerPlafond(Caisse $caisse, float $montant): void
    {
        $apres = (float) $caisse->fresh()->solde + $montant;
        $plafond = $caisse->mode === 'avance_fixe' ? (float) $caisse->montant_avance : $caisse->plafond_caisse;
        if ($plafond !== null && $apres > (float) $plafond) {
            throw new ErreurMetier('REAPPRO_PLAFOND', 'MSG-M08-02', ['x' => $apres, 'plafond' => (float) $plafond], 'RG-M08-04', 'montant');
        }
    }

    private static function controlerHabilitation(float $montant): void
    {
        $habilitation = (float) Parametre::valeur('habilitation_dp_reappro', 500000000);
        if ($montant > $habilitation) {
            throw new ErreurMetier('REAPPRO_HABILITATION', 'MSG-APP-099', ['max' => $habilitation], 'RG-M08-07', 'montant');
        }
    }

    private static function titulaires(Demande $d, array $roles): Collection
    {
        return User::actifs()->avecRoleDans($roles, $d->entite_id)->where('id', '!=', $d->demande_par)->get();
    }

    private static function notifier(Demande $d, Collection $destinataires, ?User $auteur, string $titre, string $message): void
    {
        foreach ($destinataires->filter() as $destinataire) {
            NotificationService::creerEtDiffuserSimple($destinataire, $auteur, 'reapprovisionnement', $titre, $message,
                ['lien' => route('reapprovisionnements.show', $d)]);
        }
    }

    private static function numero(Caisse $caisse): string
    {
        $prefixe = 'DRA-' . $caisse->codeNumerotation() . '-' . now()->year . '-';
        $dernier = Demande::withoutGlobalScope('entite')->where('numero', 'like', $prefixe . '%')->orderByDesc('numero')->value('numero');

        return $prefixe . str_pad((string) ((int) substr((string) $dernier, strlen($prefixe)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
