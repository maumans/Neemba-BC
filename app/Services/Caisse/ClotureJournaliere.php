<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Models\EcritureCaisse;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clôture journalière par caisse (spec v2.4, RG-M09-02 à RG-M09-04).
 *
 * - Le gérant de la caisse clôture sa journée ; à défaut, la plateforme la clôture à 23 h 45 (Q99). Les journées oubliées
 *   (planificateur arrêté) sont rattrapées, sur 31 jours au plus, jamais avant la mise en service (`clotures_caisse_depuis`).
 * - La clôture fige les soldes et les totaux du rapport « REPORTING CAISSE » et le contrôle avec la veille.
 * - Aucune écriture n'est plus possible sur une journée close (contrôle dans Caisse::ecrire, MSG-APP-105) : une correction
 *   passe le lendemain.
 * - Destinataires du rapport : ceux de la caisse (écran des caisses, référentiel de Neemba) ; à défaut, le gestionnaire
 *   et le suppléant (sinon les caissiers de la caisse) et la trésorerie (PO-18).
 */
final class ClotureJournaliere
{
    public const RATTRAPAGE_JOURS = 31;

    public static function cloturer(?User $auteur, Caisse $caisse, CarbonInterface $jour, ?string $observations = null): ClotureCaisse
    {
        $jour = Carbon::instance($jour)->startOfDay();
        if ($auteur && !AccesCaisse::estGerant($auteur, $caisse)) {
            throw new ErreurMetier('CLOTURE_INTERDITE', 'MSG-APP-106', [], 'RG-M09-03', null, 403);
        }
        if ($jour->isAfter(today())) {
            throw new ErreurMetier('CLOTURE_FUTURE', 'MSG-APP-108', [], 'RG-M09-03', 'date');
        }

        return DB::transaction(function () use ($auteur, $caisse, $jour, $observations) {
            /* Verrou sur la caisse : pas d'écriture pendant la clôture */
            $caisse = Caisse::whereKey($caisse->id)->lockForUpdate()->firstOrFail();
            if (ClotureCaisse::estClose($caisse->id, $jour)) {
                throw new ErreurMetier('CLOTURE_EXISTANTE', 'MSG-APP-107', ['date' => $jour], 'RG-M09-03', 'date');
            }
            $r = ReportingCaisse::journee($caisse, $jour);

            return ClotureCaisse::create([
                'caisse_id' => $caisse->id,
                'date_journee' => $jour->toDateString(),
                'solde_initial' => $r['solde_initial'],
                'total_encaissements' => $r['total_encaissements'],
                'total_decaissements' => $r['total_decaissements'],
                'solde_final' => $r['solde_final'],
                'solde_veille' => $r['solde_veille'],
                'ecart_controle' => $r['ecart'],
                'nombre_operations' => $r['nombre_operations'],
                'automatique' => $auteur === null,
                'cloture_par' => $auteur?->id,
                'cloture_le' => now(),
                'observations' => trim((string) $observations) ?: null,
            ]);
        });
    }

    /**
     * Clôture automatique (23 h 45) : chaque caisse active, chaque journée non close depuis la dernière clôture (ou la
     * première écriture), jusqu'à $jusqua compris.
     *
     * @return Collection<int, ClotureCaisse>
     */
    public static function cloturerEnAttente(CarbonInterface $jusqua): Collection
    {
        $faites = collect();
        foreach (Caisse::withoutGlobalScope('entite')->where('actif', true)->orderBy('id')->get() as $caisse) {
            foreach (self::journeesOuvertes($caisse, $jusqua) as $jour) {
                $faites->push(self::cloturer(null, $caisse, $jour));
            }
        }

        return $faites;
    }

    /** Journées non closes d'une caisse, de la dernière clôture (ou de sa première écriture) à $jusqua */
    public static function journeesOuvertes(Caisse $caisse, CarbonInterface $jusqua): array
    {
        $fin = Carbon::instance($jusqua)->startOfDay();
        $derniere = ClotureCaisse::withoutGlobalScope('entite')->where('caisse_id', $caisse->id)->max('date_journee');
        $premiere = EcritureCaisse::where('caisse_id', $caisse->id)->min('date_ecriture');
        $debut = $derniere ? Carbon::parse($derniere)->addDay()->startOfDay() : ($premiere ? Carbon::parse($premiere)->startOfDay() : $fin->copy());
        $debut = $debut->max($fin->copy()->subDays(self::RATTRAPAGE_JOURS - 1));
        /* Pas de clôture après coup des journées antérieures à la mise en service (Q99) */
        if ($depuis = \App\Models\Parametre::valeur('clotures_caisse_depuis')) {
            $debut = $debut->max(Carbon::parse($depuis)->startOfDay());
        }

        $jours = [];
        for ($j = $debut->copy(); $j->lte($fin); $j->addDay()) {
            if (!ClotureCaisse::estClose($caisse->id, $j)) {
                $jours[] = $j->copy();
            }
        }

        return $jours;
    }

    /** RG-M09-04, PO-18 : destinataires du rapport de la caisse */
    public static function destinataires(Caisse $caisse): Collection
    {
        $choisis = array_filter((array) ($caisse->destinataires_rapport ?? []));
        if ($choisis !== []) {
            return User::actifs()->whereIn('id', $choisis)->whereNotNull('email')->get();
        }
        $gerants = User::actifs()->whereIn('id', array_filter([$caisse->gestionnaire_id, $caisse->suppleant_id]))->get();
        if ($gerants->isEmpty()) {
            $gerants = AccesCaisse::gerants($caisse);
        }

        return $gerants->merge(User::actifs()->avecRoleDans(['tresorerie'], $caisse->entite_id)->get())
            ->filter(fn (User $u) => filled($u->email))->unique('id')->values();
    }
}
