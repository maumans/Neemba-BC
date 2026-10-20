<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\EncaissementClient;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Encaissement client (spec v2.4, RG-M08-10, RG-M08-02, MSG-M08-05, MSG-M08-06) :
 * - seulement sur une caisse autorisée aux encaissements clients (Conakry principale) ;
 * - par le gérant de la caisse ou la trésorerie ;
 * - un encaissement en espèces qui porterait le solde au-dessus du plafond de la caisse est bloqué, et la trésorerie est
 *   alertée pour une remise en banque ;
 * - reçu numéroté REC-<code caisse>-<année>-<00001>.
 */
final class EncaisserClient
{
    public static function peutEncaisser(User $utilisateur, Caisse $caisse): bool
    {
        return AccesCaisse::estGerant($utilisateur, $caisse) || $utilisateur->aLeRole('tresorerie');
    }

    public static function enregistrer(User $auteur, Caisse $caisse, Client $client, string $numeroPiece, float $montant, string $mode,
        ?string $observations = null, ?UploadedFile $piece = null): EncaissementClient
    {
        if (!$caisse->encaissements_clients || !$caisse->actif) {
            throw new ErreurMetier('ENCAISSEMENT_CAISSE_INTERDITE', 'MSG-M08-05', [], 'RG-M08-10', 'caisse_id');
        }
        if (!self::peutEncaisser($auteur, $caisse)) {
            throw new ErreurMetier('ENCAISSEMENT_INTERDIT', 'MSG-APP-090', [], 'RG-M08-12', null, 403);
        }

        /* Contrôle du plafond hors transaction : l'alerte à la trésorerie doit rester même si l'encaissement est refusé */
        $apres = (float) $caisse->fresh()->solde + $montant;
        if ($mode === 'especes' && $caisse->plafond_caisse !== null && $apres > (float) $caisse->plafond_caisse) {
            self::alerterPlafond($caisse, $auteur, $montant);
            throw new ErreurMetier('ENCAISSEMENT_AU_DELA_DU_PLAFOND', 'MSG-M08-06',
                ['x' => $apres, 'plafond' => (float) $caisse->plafond_caisse], 'RG-M08-10', 'montant');
        }

        return DB::transaction(function () use ($auteur, $caisse, $client, $numeroPiece, $montant, $mode, $observations, $piece) {
            $caisse = Caisse::whereKey($caisse->id)->lockForUpdate()->firstOrFail();

            $encaissement = EncaissementClient::create([
                'caisse_id' => $caisse->id,
                'numero' => self::numero($caisse),
                'client_id' => $client->id,
                'numero_piece' => mb_strtoupper(trim($numeroPiece)),
                'montant' => $montant,
                'mode' => $mode,
                'observations' => filled($observations) ? trim($observations) : null,
                'piece' => $piece?->store('encaissements_clients', 'public'),
                'encaisse_par' => $auteur->id,
                'encaisse_le' => now(),
            ]);
            if ($mode === 'especes') {
                $ecriture = $caisse->crediter($montant, 'encaissement_client', [
                    'utilisateur_id' => $auteur->id,
                    'libelle' => "Encaissement client {$encaissement->numero} — {$client->raison_sociale} ({$encaissement->numero_piece})",
                    'piece' => $encaissement->numero,
                ]);
                $encaissement->update(['ecriture_caisse_id' => $ecriture->id]);
            }

            return $encaissement;
        });
    }

    /** MSG-M08-06 : la trésorerie est alertée pour une remise en banque */
    private static function alerterPlafond(Caisse $caisse, User $auteur, float $montant): void
    {
        $message = "Un encaissement client de " . \App\Support\Format::montant($montant) . " a été refusé sur la {$caisse->libelle} : il dépasserait le plafond de caisse ("
            . \App\Support\Format::montant($caisse->plafond_caisse) . '). Remise en banque nécessaire.';
        foreach (User::actifs()->avecRoleDans(['tresorerie'], $caisse->entite_id)->get() as $tresorerie) {
            NotificationService::creerEtDiffuserSimple($tresorerie, $auteur, 'remise_en_banque', 'Remise en banque nécessaire', $message,
                ['caisse_id' => $caisse->id]);
        }
    }

    private static function numero(Caisse $caisse): string
    {
        $prefixe = 'REC-' . $caisse->codeNumerotation() . '-' . now()->year . '-';
        $dernier = EncaissementClient::withoutGlobalScope('entite')->where('numero', 'like', $prefixe . '%')->orderByDesc('numero')->value('numero');

        return $prefixe . str_pad((string) ((int) substr((string) $dernier, strlen($prefixe)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
