<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\Notification;
use App\Models\User;
use App\Services\NimbaSmsService;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Alerte de seuil de réapprovisionnement (spec v2.4, RG-M08-03, MSG-M08-01) : envoyée une fois, au franchissement du
 * seuil vers le bas, au gérant de la caisse, à la trésorerie et au Chef comptable ; rappel quotidien tant que le solde
 * reste sous le seuil. Remontée au-dessus du seuil, la caisse est de nouveau « à alerter ».
 */
final class AlerteSeuil
{
    /** Appelée après chaque écriture au registre de la caisse */
    public static function apresEcriture(Caisse $caisse, float $soldeAvant, float $soldeApres): void
    {
        $seuil = $caisse->seuilAlerteEffectif();
        if ($soldeApres <= $seuil && $soldeAvant > $seuil) {
            self::alerter($caisse, false);
            $caisse->forceFill(['alerte_seuil_le' => now(), 'alerte_seuil_rappel_le' => today()])->saveQuietly();
        } elseif ($soldeApres > $seuil && $caisse->alerte_seuil_le) {
            $caisse->forceFill(['alerte_seuil_le' => null, 'alerte_seuil_rappel_le' => null])->saveQuietly();
        }
    }

    /** Rappel quotidien (commande caisse:verifier-seuils) ; une caisse sous le seuil jamais alertée l'est maintenant */
    public static function rappels(): array
    {
        $resultats = [];
        foreach (Caisse::withoutGlobalScope('entite')->actives()->orderBy('id')->get() as $caisse) {
            if (!$caisse->sousSeuil()) {
                if ($caisse->alerte_seuil_le) {
                    $caisse->forceFill(['alerte_seuil_le' => null, 'alerte_seuil_rappel_le' => null])->saveQuietly();
                }
                continue;
            }
            if ($caisse->alerte_seuil_rappel_le?->isToday()) {
                $resultats[$caisse->libelle] = 'déjà alertée aujourd\'hui';
                continue;
            }
            $rappel = $caisse->alerte_seuil_le !== null;
            self::alerter($caisse, $rappel);
            $caisse->forceFill(['alerte_seuil_le' => $caisse->alerte_seuil_le ?? now(), 'alerte_seuil_rappel_le' => today()])->saveQuietly();
            $resultats[$caisse->libelle] = $rappel ? 'rappel envoyé' : 'alerte envoyée';
        }

        return $resultats;
    }

    /** Gérant de la caisse, trésorerie, Chef comptable */
    public static function destinataires(Caisse $caisse): Collection
    {
        return AccesCaisse::gerants($caisse)
            ->merge(User::actifs()->avecRoleDans(['tresorerie', 'chef_comptable'], $caisse->entite_id)->get())
            ->unique('id')->values();
    }

    public static function alerter(Caisse $caisse, bool $rappel, ?User $declencheur = null): void
    {
        $message = ErreurMetier::texte('MSG-M08-01', [
            'caisse' => $caisse->libelle, 'solde' => (float) $caisse->solde, 'seuil' => $caisse->seuilAlerteEffectif(),
        ]) . ' Demandez un réapprovisionnement.';
        $gerants = AccesCaisse::gerants($caisse)->pluck('id')->all();

        foreach (self::destinataires($caisse) as $destinataire) {
            $notification = Notification::create([
                'destinataire_id' => $destinataire->id,
                'bon_caisse_id' => null,
                'expediteur_id' => $declencheur?->id,
                'type' => 'alerte_solde',
                'titre' => ($rappel ? 'Rappel : ' : '') . 'solde de caisse sous le seuil',
                'message' => $message,
                'metadata' => ['caisse_id' => $caisse->id, 'caisse' => $caisse->libelle, 'solde' => (float) $caisse->solde,
                    'seuil' => $caisse->seuilAlerteEffectif(), 'rappel' => $rappel],
            ]);
            try {
                broadcast(new \App\Events\NouvelleNotification($notification));
            } catch (\Throwable $e) {
                Log::warning('Diffusion de l\'alerte de seuil échouée : ' . $e->getMessage());
            }
            /* SMS au gérant de la caisse, pour agir vite */
            if (in_array($destinataire->id, $gerants, true) && $destinataire->telephone) {
                try {
                    app(NimbaSmsService::class)->envoyerAlerteSeuil($destinataire->telephone, $caisse->libelle, $caisse->solde_format,
                        Format::montant($caisse->seuilAlerteEffectif()));
                } catch (\Throwable $e) {
                    Log::warning("SMS d'alerte de seuil non envoyé : " . $e->getMessage());
                }
            }
        }
    }
}
