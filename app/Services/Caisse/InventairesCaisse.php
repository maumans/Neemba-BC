<?php

namespace App\Services\Caisse;

use App\Models\Caisse;
use App\Models\Notification;
use App\Models\Parametre;
use App\Models\PvArrete;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Format;
use App\Support\JoursOuvres;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Inventaires et contrôles inopinés des caisses (spec v2.4, RG-M10-01, §5.4).
 *
 * - Inventaire obligatoire à la fin de chaque mois : un PV d'arrêté « inventaire de fin de mois » dans le mois (Q101).
 *   Le dernier jour ouvré du mois, rappel au gérant, à la trésorerie et au Chef comptable s'il manque ; le mois suivant,
 *   alerte au Chef comptable (garant) et au DAF.
 * - Inventaire avant chaque demande de réapprovisionnement : exigé par la demande (lot F1b).
 * - Contrôle inopiné : un PV « contrôle inopiné » par période (mois, ou trimestre pour Conakry, Boké, Sangarédi), établi par
 *   la trésorerie ou le Chef comptable, jamais par le gérant. Le gérant n'est pas prévenu : dans les 7 derniers jours de la
 *   période, rappel au seul Chef comptable ; période écoulée sans contrôle, alerte au Chef comptable et au DAF.
 * - Chaque alerte part une fois (clé dans la notification).
 */
final class InventairesCaisse
{
    public const FREQUENCES = ['mensuelle' => 'Mensuelle', 'trimestrielle' => 'Trimestrielle'];

    public static function frequence(Caisse $caisse): string
    {
        $f = $caisse->frequence_controle ?: (string) Parametre::valeur('frequence_controle_inopine', 'mensuelle');

        return array_key_exists($f, self::FREQUENCES) ? $f : 'mensuelle';
    }

    /** @return array{0: Carbon, 1: Carbon} début et fin de la période de contrôle qui contient la date */
    public static function periode(Caisse $caisse, CarbonInterface $date): array
    {
        $d = Carbon::instance($date);

        return self::frequence($caisse) === 'trimestrielle'
            ? [$d->copy()->firstOfQuarter()->startOfDay(), $d->copy()->lastOfQuarter()->endOfDay()]
            : [$d->copy()->startOfMonth(), $d->copy()->endOfMonth()];
    }

    public static function inventaire(Caisse $caisse, CarbonInterface $mois): ?PvArrete
    {
        $m = Carbon::instance($mois);

        return PvArrete::where('caisse_id', $caisse->id)->where('motif', 'inventaire_mensuel')
            ->whereBetween('arrete_le', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])->latest('arrete_le')->first();
    }

    public static function controle(Caisse $caisse, CarbonInterface $debut, CarbonInterface $fin): ?PvArrete
    {
        return PvArrete::where('caisse_id', $caisse->id)->where('motif', 'controle_inopine')
            ->whereBetween('arrete_le', [$debut, $fin])->latest('arrete_le')->first();
    }

    /** Suivi par caisse (rôles transverses seulement : le contrôle reste inopiné pour le gérant) */
    public static function suivi(Collection $caisses): array
    {
        return $caisses->map(function (Caisse $c) {
            $inventaire = self::inventaire($c, now());
            $precedent = self::inventaire($c, now()->subMonthNoOverflow());
            [$debut, $fin] = self::periode($c, now());
            $controle = self::controle($c, $debut, $fin);
            $dernier = PvArrete::where('caisse_id', $c->id)->where('motif', 'controle_inopine')->latest('arrete_le')->first();

            return [
                'id' => $c->id,
                'caisse' => $c->libelle,
                'inventaire_mois' => $inventaire ? Format::date($inventaire->arrete_le) : null,
                'inventaire_mois_precedent' => $precedent ? Format::date($precedent->arrete_le) : null,
                'frequence' => self::FREQUENCES[self::frequence($c)],
                'periode' => Format::date($debut) . ' – ' . Format::date($fin),
                'controle_periode' => $controle ? Format::date($controle->arrete_le) : null,
                'dernier_controle' => $dernier ? Format::date($dernier->arrete_le) : null,
            ];
        })->values()->all();
    }

    /** Rappels et alertes du jour ; renvoie le nombre de notifications envoyées */
    public static function verifier(?CarbonInterface $jour = null): int
    {
        $jour = Carbon::instance($jour ?? now());
        $envois = 0;
        foreach (Caisse::actives()->orderBy('id')->get() as $caisse) {
            $mois = $jour->format('Y-m');
            /* Dernier jour ouvré du mois : inventaire à faire */
            if ($jour->isSameDay(self::dernierJourOuvre($jour)) && !self::inventaire($caisse, $jour)) {
                $envois += self::alerter("inventaire:{$caisse->id}:{$mois}:rappel", self::destinataires($caisse, ['gerants', 'tresorerie', 'chef_comptable']),
                    'Inventaire de fin de mois à faire', "La {$caisse->libelle} n'a pas encore son inventaire de fin de mois (PV d'arrêté « inventaire de fin de mois »).",
                    route('pv-arrete.create', ['caisse' => $caisse->id]));
            }
            /* Mois écoulé sans inventaire : alerte au garant */
            $precedent = $jour->copy()->subMonthNoOverflow();
            if (!self::inventaire($caisse, $precedent) && self::existaitLe($caisse, $precedent->copy()->endOfMonth())) {
                $envois += self::alerter("inventaire:{$caisse->id}:{$precedent->format('Y-m')}:retard", self::destinataires($caisse, ['chef_comptable', 'daf']),
                    'Inventaire de fin de mois manquant', "Aucun inventaire de fin de mois pour la {$caisse->libelle} en " . $precedent->translatedFormat('F Y') . '.',
                    route('pv-arrete.index', ['caisse' => $caisse->id]));
            }
            /* Contrôle inopiné : fin de période sans contrôle (Chef comptable seul), puis période écoulée */
            [$debut, $fin] = self::periode($caisse, $jour);
            if ($jour->diffInDays($fin, false) < 7 && !self::controle($caisse, $debut, $fin)) {
                $envois += self::alerter("controle:{$caisse->id}:{$debut->toDateString()}:rappel", self::destinataires($caisse, ['chef_comptable']),
                    'Contrôle inopiné à faire', "Aucun contrôle inopiné de la {$caisse->libelle} sur la période en cours (jusqu'au " . Format::date($fin) . '). Ne prévenez pas le gérant.',
                    route('pv-arrete.create', ['caisse' => $caisse->id]));
            }
            [$debutPrecedent, $finPrecedente] = self::periode($caisse, $debut->copy()->subDay());
            if (!self::controle($caisse, $debutPrecedent, $finPrecedente) && self::existaitLe($caisse, $finPrecedente)) {
                $envois += self::alerter("controle:{$caisse->id}:{$debutPrecedent->toDateString()}:retard", self::destinataires($caisse, ['chef_comptable', 'daf']),
                    'Contrôle inopiné non fait', "Aucun contrôle inopiné de la {$caisse->libelle} du " . Format::date($debutPrecedent) . ' au ' . Format::date($finPrecedente) . '.',
                    route('pv-arrete.index', ['caisse' => $caisse->id]));
            }
        }

        return $envois;
    }

    public static function dernierJourOuvre(CarbonInterface $jour): Carbon
    {
        $d = Carbon::instance($jour)->endOfMonth()->startOfDay();
        while (!JoursOuvres::estOuvre($d)) {
            $d->subDay();
        }

        return $d;
    }

    /** Pas de retard pour une période terminée avant la mise en service du suivi, ou avant la création de la caisse */
    private static function existaitLe(Caisse $caisse, CarbonInterface $date): bool
    {
        $depuis = Parametre::valeur('inventaires_suivis_depuis');
        if ($depuis && Carbon::parse($depuis)->startOfDay()->gt($date)) {
            return false;
        }

        return $caisse->created_at === null || $caisse->created_at->lte($date);
    }

    private static function destinataires(Caisse $caisse, array $cibles): Collection
    {
        $roles = array_values(array_intersect($cibles, ['tresorerie', 'chef_comptable', 'daf']));
        if (in_array('daf', $roles, true)) {
            $roles[] = 'daf_adjoint';
        }
        $liste = $roles ? User::actifs()->avecRoleDans($roles, $caisse->entite_id)->get() : collect();
        if (in_array('gerants', $cibles, true)) {
            $liste = AccesCaisse::gerants($caisse)->merge($liste);
        }

        return $liste->unique('id')->values();
    }

    private static function alerter(string $cle, Collection $destinataires, string $titre, string $message, string $lien): int
    {
        if (Notification::where('type', 'inventaire_caisse')->where('metadata->cle', $cle)->exists()) {
            return 0;
        }
        foreach ($destinataires as $u) {
            NotificationService::creerEtDiffuserSimple($u, null, 'inventaire_caisse', $titre, $message, ['lien' => $lien, 'cle' => $cle]);
        }

        return $destinataires->count();
    }
}
