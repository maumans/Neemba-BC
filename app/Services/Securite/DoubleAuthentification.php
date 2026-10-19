<?php

namespace App\Services\Securite;

use App\Exceptions\ErreurMetier;
use App\Mail\CodeConnexion;
use App\Models\CodeOtp;
use App\Models\Parametre;
use App\Models\User;
use App\Services\NimbaSmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Double authentification (spec v2.4, RG-M01-07, SC-40).
 *
 * - Connexion : après le mot de passe, un code à 6 chiffres est envoyé par email ; la session n'est ouverte qu'après sa
 *   saisie. Durée et nombre d'essais paramétrables.
 * - Visa d'une demande urgente ou très urgente (bon ou ODM) : le valideur reçoit un code par SMS et le saisit pour viser
 *   (l'approbation seulement, Q77). Distinct de l'OTP de paiement (RG-M06-02).
 * - Paramètres de secours : chaque mécanisme se désactive (panne SMTP ou SMS), sous double validation.
 * - Sans fournisseur SMS (poste de développement, tests, recette), le code de visa est écrit dans le journal ; en
 *   production, l'envoi est refusé.
 */
final class DoubleAuthentification
{
    public const NIVEAUX_URGENTS = ['urgente', 'tres_urgente'];

    public static function connexionActive(): bool
    {
        return (bool) Parametre::valeur('double_authentification_active', true);
    }

    /** Le visa d'une demande de ce niveau d'urgence exige-t-il un code SMS ? */
    public static function exigeCodeVisa(?string $niveauUrgence): bool
    {
        return in_array($niveauUrgence, self::NIVEAUX_URGENTS, true) && (bool) Parametre::valeur('otp_visa_urgent_actif', true);
    }

    /** Code de connexion envoyé par email */
    public static function envoyerCodeConnexion(User $utilisateur): void
    {
        $duree = max(1, (int) Parametre::valeur('otp_connexion_duree', 10));
        $code = self::emettre($utilisateur, 'connexion', null, 'email', $duree);
        try {
            Mail::to($utilisateur->email)->send(new CodeConnexion($utilisateur, $code, $duree));
        } catch (\Throwable $e) {
            Log::error('Code de connexion non envoyé : ' . $e->getMessage(), ['user_id' => $utilisateur->id]);
            self::invalider($utilisateur, 'connexion', null);
            throw new ErreurMetier('ENVOI_CODE_IMPOSSIBLE', 'MSG-APP-080', [], 'RG-M01-07', 'email', 503);
        }
    }

    /** Code de visa envoyé par SMS au valideur ; renvoie le message à afficher */
    public static function envoyerCodeVisa(User $valideur, Model $cible, string $objet, string $libelle): string
    {
        if (blank($valideur->telephone)) {
            throw new ErreurMetier('TELEPHONE_MANQUANT', 'MSG-APP-082', [], 'RG-M01-07', 'code_visa');
        }
        $duree = max(1, (int) Parametre::valeur('otp_visa_duree', 5));
        $code = self::emettre($valideur, $objet, $cible, 'sms', $duree);
        $resultat = app(NimbaSmsService::class)->envoyerSms($valideur->telephone,
            "NEEMBA - Code de visa {$code} pour {$libelle}. Valable {$duree} min. Ne le communiquez a personne.");

        if (empty($resultat['success'])) {
            if (!app()->environment(['local', 'testing', 'e2e'])) {
                self::invalider($valideur, $objet, $cible);
                throw new ErreurMetier('ENVOI_CODE_IMPOSSIBLE', 'MSG-APP-080', [], 'RG-M01-07', 'code_visa', 503);
            }
            /* Poste de développement, tests, recette : pas de fournisseur SMS, le code est lu dans le journal */
            Log::info("Code de visa (SMS non envoyé, environnement " . app()->environment() . ") : {$code}", ['user_id' => $valideur->id, 'objet' => $objet]);
        }

        return __('MSG-APP-083', ['telephone' => self::masquer($valideur->telephone), 'minutes' => $duree]);
    }

    /**
     * Vérifie le code saisi ; le code juste est consommé. Un code faux compte un essai ; au dernier essai, le code tombe.
     *
     * @throws ErreurMetier
     */
    public static function verifier(User $utilisateur, string $objet, ?Model $cible, ?string $saisi, string $champ): void
    {
        $otp = self::actif($utilisateur, $objet, $cible);
        if (!$otp) {
            throw new ErreurMetier('CODE_EXPIRE', $objet === 'connexion' ? 'MSG-APP-079' : 'MSG-APP-081', [], 'RG-M01-07', $champ);
        }
        if (!hash_equals($otp->code_hash, CodeOtp::empreinte(preg_replace('/\D/', '', (string) $saisi)))) {
            $otp->increment('tentatives');
            $restants = self::tentativesMax() - $otp->tentatives;
            if ($restants <= 0) {
                $otp->update(['consomme_le' => now()]);
                throw new ErreurMetier('CODE_EPUISE', 'MSG-APP-079', [], 'RG-M01-07', $champ);
            }
            throw new ErreurMetier('CODE_INCORRECT', 'MSG-APP-078', ['restants' => $restants], 'RG-M01-07', $champ);
        }
        $otp->update(['consomme_le' => now()]);
    }

    /** Masque une adresse ou un numéro : s•••••@neemba.com, +224 6•• •• •• 45 */
    public static function masquer(string $valeur): string
    {
        if (str_contains($valeur, '@')) {
            [$nom, $domaine] = explode('@', $valeur, 2);

            return mb_substr($nom, 0, 1) . str_repeat('•', max(1, mb_strlen($nom) - 1)) . '@' . $domaine;
        }
        $chiffres = preg_replace('/\D/', '', $valeur);

        return '••• ' . substr($chiffres, -2);
    }

    private static function tentativesMax(): int
    {
        return max(1, (int) Parametre::valeur('otp_connexion_tentatives', 5));
    }

    private static function emettre(User $utilisateur, string $objet, ?Model $cible, string $canal, int $duree): string
    {
        self::invalider($utilisateur, $objet, $cible);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        CodeOtp::create([
            'user_id' => $utilisateur->id,
            'objet' => $objet,
            'cible_type' => $cible?->getMorphClass(),
            'cible_id' => $cible?->getKey(),
            'canal' => $canal,
            'code_hash' => CodeOtp::empreinte($code),
            'expire_le' => now()->addMinutes($duree),
        ]);

        return $code;
    }

    private static function requete(User $utilisateur, string $objet, ?Model $cible)
    {
        return CodeOtp::where('user_id', $utilisateur->id)->where('objet', $objet)
            ->where('cible_type', $cible?->getMorphClass())->where('cible_id', $cible?->getKey())
            ->whereNull('consomme_le');
    }

    private static function actif(User $utilisateur, string $objet, ?Model $cible): ?CodeOtp
    {
        return self::requete($utilisateur, $objet, $cible)->where('expire_le', '>', now())->latest('id')->first();
    }

    private static function invalider(User $utilisateur, string $objet, ?Model $cible): void
    {
        self::requete($utilisateur, $objet, $cible)->update(['consomme_le' => now()]);
    }
}
