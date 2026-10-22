<?php

namespace App\Services\Securite;

use App\Models\ModificationEnAttente;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Réglages de la double authentification (spec v2.4, RG-M01-07), tenus par l'administrateur dans l'onglet « Sécurité ».
 *
 * - Effet immédiat, sans second validateur : ce sont des interrupteurs de secours (panne du serveur d'email ou du
 *   fournisseur SMS), qu'un second administrateur ne pourrait pas valider s'il ne peut plus se connecter.
 * - Chaque changement est tracé dans le journal des modifications (approuvé par son auteur) ; une demande de double
 *   validation encore en attente sur le même réglage est close. Les autres administrateurs sont prévenus quand un
 *   interrupteur change.
 * - Si plus personne ne peut se connecter : `php artisan securite:double-authentification desactiver` (sur le serveur).
 */
final class ReglagesSecurite
{
    public const REGLAGES = [
        'double_authentification_active' => ['type' => 'boolean', 'libelle' => 'Code de connexion par email'],
        'otp_connexion_duree' => ['type' => 'number', 'min' => 1, 'max' => 60, 'libelle' => 'Validité du code de connexion (minutes)'],
        'otp_connexion_tentatives' => ['type' => 'number', 'min' => 1, 'max' => 10, 'libelle' => 'Essais par code'],
        /* Q108 : le titulaire d'un compte au mot de passe initial le change à sa première connexion */
        'changement_mot_de_passe_initial' => ['type' => 'boolean', 'libelle' => 'Changement du mot de passe initial à la première connexion'],
    ];

    /** Valeurs par défaut (celles de la migration du lot E2) */
    private const DEFAUTS = [
        'double_authentification_active' => true,
        'otp_connexion_duree' => 10,
        'otp_connexion_tentatives' => 5,
        'changement_mot_de_passe_initial' => true,
    ];

    public static function cles(): array
    {
        return array_keys(self::REGLAGES);
    }

    /** Règles de validation du formulaire de l'onglet « Sécurité » */
    public static function regles(): array
    {
        return collect(self::REGLAGES)->map(fn (array $r) => $r['type'] === 'boolean'
            ? ['required', 'boolean']
            : ['required', 'integer', "min:{$r['min']}", "max:{$r['max']}"])->all();
    }

    /** Valeurs en vigueur, typées */
    public static function valeurs(): array
    {
        return collect(self::REGLAGES)->map(fn (array $r, string $cle) => $r['type'] === 'boolean'
            ? (bool) Parametre::valeur($cle, self::DEFAUTS[$cle])
            : (int) Parametre::valeur($cle, self::DEFAUTS[$cle]))->all();
    }

    /**
     * Ce qu'affiche l'onglet « Sécurité » : réglages, canaux d'envoi, valideurs sans téléphone (ils ne reçoivent pas l'alerte
     * SMS des demandes urgentes), derniers changements
     */
    public static function etat(): array
    {
        $mailer = (string) config('mail.default');
        $rolesVisa = array_values(array_unique(array_merge(
            ...array_values(User::ROLES_ETAPES_BON),
            ...array_map(fn (array $niveau) => $niveau['roles'], array_values(OrdreMission::NIVEAUX)),
        )));
        $sansTelephone = User::actifs()->avecRoleDans($rolesVisa, null)
            ->where(fn ($q) => $q->whereNull('telephone')->orWhere('telephone', ''))
            ->orderBy('name')->get(['id', 'name', 'prenom']);

        $parametres = Parametre::whereIn('cle', self::cles())->pluck('cle', 'id');
        $historique = ModificationEnAttente::with('demandeur:id,name,prenom')
            ->where('type_entite', 'parametre')->whereIn('entite_id', $parametres->keys())
            ->latest('id')->limit(10)->get()
            ->map(fn (ModificationEnAttente $m) => [
                'id' => $m->id,
                'date' => $m->created_at?->format('d/m/Y H:i'),
                'auteur' => $m->demandeur?->nom_complet,
                'reglage' => self::REGLAGES[$parametres[$m->entite_id]]['libelle'] ?? $parametres[$m->entite_id],
                'avant' => self::lisible($parametres[$m->entite_id], $m->ancienne_valeur),
                'apres' => self::lisible($parametres[$m->entite_id], $m->nouvelle_valeur),
                'statut' => ModificationEnAttente::STATUTS[$m->statut] ?? $m->statut,
            ]);

        return [
            'valeurs' => self::valeurs(),
            'bornes' => collect(self::REGLAGES)->filter(fn (array $r) => $r['type'] === 'number')
                ->map(fn (array $r) => ['min' => $r['min'], 'max' => $r['max']])->all(),
            'email' => ['mailer' => $mailer, 'operationnel' => !in_array($mailer, ['log', 'array'], true)],
            'sms' => ['operationnel' => filled(config('services.nimba.auth_token'))],
            'valideursSansTelephone' => $sansTelephone->map(fn (User $u) => $u->nom_complet)->values(),
            'historique' => $historique,
        ];
    }

    /**
     * Applique les réglages modifiés ; renvoie le libellé de chacun.
     *
     * @param  User|null  $auteur  null : commande de secours sur le serveur (tracée dans le journal applicatif)
     */
    public static function appliquer(array $valeurs, ?User $auteur): array
    {
        $avant = self::valeurs();
        $modifies = [];

        DB::transaction(function () use ($valeurs, $auteur, $avant, &$modifies) {
            foreach (self::REGLAGES as $cle => $reglage) {
                if (!array_key_exists($cle, $valeurs)) {
                    continue;
                }
                $nouvelle = $reglage['type'] === 'boolean'
                    ? (filter_var($valeurs[$cle], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                    : (string) (int) $valeurs[$cle];
                $ancienne = $reglage['type'] === 'boolean' ? ($avant[$cle] ? 'true' : 'false') : (string) $avant[$cle];
                if ($nouvelle === $ancienne) {
                    continue;
                }

                $parametre = Parametre::where('cle', $cle)->first();
                if (!$parametre) {
                    continue;
                }
                Parametre::majValeur($cle, $nouvelle);
                $modifies[$cle] = $reglage['libelle'];

                if ($auteur) {
                    /* Une double validation encore en attente sur ce réglage n'a plus d'objet */
                    ModificationEnAttente::where('type_entite', 'parametre')->where('entite_id', $parametre->id)
                        ->where('statut', 'en_attente')
                        ->update(['statut' => 'refusee', 'valideur_id' => $auteur->id, 'date_validation' => now(),
                            'commentaire' => 'Remplacée par un réglage direct de l\'onglet Sécurité']);
                    ModificationEnAttente::create([
                        'type_entite' => 'parametre',
                        'entite_id' => $parametre->id,
                        'champ' => 'valeur',
                        'ancienne_valeur' => $ancienne,
                        'nouvelle_valeur' => $nouvelle,
                        'demandeur_id' => $auteur->id,
                        'valideur_id' => $auteur->id,
                        'statut' => 'approuvee',
                        'commentaire' => 'Réglage de sécurité appliqué directement par l\'administrateur (RG-M01-07)',
                        'date_validation' => now(),
                    ]);
                }
                Log::notice("Réglage de sécurité « {$reglage['libelle']} » : {$ancienne} → {$nouvelle}",
                    ['auteur' => $auteur?->id ?? 'commande artisan']);
            }
        });

        if ($auteur) {
            self::prevenirAdministrateurs($modifies, $auteur);
        }

        return array_values($modifies);
    }

    /** Un interrupteur a changé : les autres administrateurs sont prévenus */
    private static function prevenirAdministrateurs(array $modifies, User $auteur): void
    {
        $interrupteurs = array_intersect_key($modifies, ['double_authentification_active' => 1, 'changement_mot_de_passe_initial' => 1]);
        if (!$interrupteurs) {
            return;
        }
        $valeurs = self::valeurs();
        $phrases = collect($interrupteurs)->map(fn (string $libelle, string $cle) => "{$libelle} : " . ($valeurs[$cle] ? 'activé' : 'désactivé'));
        $objet = isset($interrupteurs['double_authentification_active']) ? 'la double authentification' : 'la sécurité de la connexion';
        $message = "{$auteur->nom_complet} a modifié {$objet}. " . $phrases->implode(' ; ') . '.';

        foreach (User::actifs()->avecRoleDans(['administrateur'], null)->get() as $administrateur) {
            NotificationService::creerEtDiffuserSimple($administrateur, $auteur, 'securite', 'Double authentification', $message,
                ['lien' => route('parametrage.index')]);
        }
    }

    private static function lisible(string $cle, ?string $valeur): string
    {
        if ((self::REGLAGES[$cle]['type'] ?? null) === 'boolean') {
            return in_array(strtolower((string) $valeur), ['true', '1', 'oui'], true) ? 'Activé' : 'Désactivé';
        }

        return (string) $valeur;
    }
}
