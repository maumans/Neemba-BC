<?php

namespace App\Services\Referentiels;

use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\ModificationEnAttente;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\BonCaisse\ReglesSaisie;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import des référentiels de paramétrage de Neemba (classeur des points 11 à 14), réexécutable à chaque nouvelle
 * version du classeur.
 *
 * Principes :
 * - seules les valeurs sûres sont appliquées ; une valeur marquée « (à confirmer) » est mise de côté et listée ;
 * - rien n'est supprimé ni vidé : une cellule vide ne change rien ;
 * - plafonds, seuils et avances de caisse passent par la double validation (Paramétrage › Modifications en attente) ;
 * - un rôle à privilèges n'est ajouté directement qu'à un compte créé par l'import ; sur un compte existant,
 *   c'est une action de l'administrateur (comme un changement de rôle à l'écran) ;
 * - en simulation, tout est fait dans une transaction annulée : le compte rendu est celui de l'import réel.
 */
class ImportReferentiels
{
    /** Rôles qui donnent des droits de validation, de paiement ou d'administration */
    private const ROLES_PRIVILEGIES = ['administrateur', 'directeur_pays', 'dp_adjoint', 'daf', 'controle_gestion', 'responsable_service', 'chef_atelier', 'caissier'];

    /** Rôle principal (users.role) d'un compte créé : le plus élevé de ses rôles */
    private const PRIORITE = ['administrateur', 'directeur_pays', 'daf', 'controle_gestion', 'responsable_service', 'caissier', 'demandeur'];

    private RapportImport $rapport;

    /** @var array<int, true> comptes créés par cet import */
    private array $crees = [];

    /** @var array<string, int[]> clé « NOM PRÉNOM » (dans les deux ordres) => identifiants */
    private array $index = [];

    /** @var array<string, Site> libellé de site du classeur (« CORICA ») => site de l'application */
    private array $sitesDuClasseur = [];

    /** @var array<int, true> comptes rencontrés dans le classeur */
    private array $rencontres = [];

    public function __construct(
        private ClasseurReferentiels $classeur,
        private ?User $auteur = null,
        private bool $doubleValidation = true,
    ) {}

    public function executer(bool $appliquer): RapportImport
    {
        $this->rapport = new RapportImport();

        DB::beginTransaction();
        try {
            $this->services();
            $this->codesAnalytiques();
            $this->sitesDesCaisses();
            $this->utilisateurs();
            $this->valideurs();
            $this->caisses();
            $this->rolesPrincipaux();
            $this->comptesAbsents();
        } catch (\Throwable $erreur) {
            DB::rollBack();
            throw $erreur;
        }
        $appliquer ? DB::commit() : DB::rollBack();

        return $this->rapport;
    }

    /* ------------------------------------------------------------------
     * Onglet 3 — services
     * ------------------------------------------------------------------ */

    private function services(): void
    {
        $onglet = $this->classeur->titre('3-') ?? '3-Services';

        foreach ($this->classeur->lignes('3-') as $numero => $ligne) {
            $nom = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Service'))['valeur'];
            if ($nom === null) {
                continue;
            }
            $commentaire = ClasseurReferentiels::colonne($ligne, 'Commentaire');

            $service = Service::all()->first(fn (Service $s) => Normalisation::cle($s->nom) === Normalisation::cle($nom));
            if (!$service) {
                $service = Service::create(['nom' => $nom, 'actif' => true]);
                $this->rapport->applique($onglet, $nom, 'Service créé');
            }

            $code = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Code numérique'));
            if ($code['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $service->nom, 'Code comptable', $code['valeur']);
            } elseif ($code['valeur'] !== null && $code['valeur'] !== (string) $service->code) {
                $this->rapport->applique($onglet, $service->nom, "Code comptable : " . ($service->code ?: 'aucun') . " → {$code['valeur']}");
                $service->update(['code' => $code['valeur']]);
            }

            /* Une correspondance « proposée » ou « à confirmer » dans le commentaire n'est pas encore celle de Neemba */
            $odm = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Équivalent'));
            $proposition = (bool) preg_match('/propos|confirmer/iu', $commentaire);
            if ($odm['valeur'] !== null && ($odm['a_confirmer'] || $proposition)) {
                $this->rapport->aConfirmer($onglet, $numero, $service->nom, 'Équivalent sur la fiche ODM', $odm['valeur']);
            } elseif ($odm['valeur'] !== null && $odm['valeur'] !== $service->equivalent_odm) {
                $service->update(['equivalent_odm' => $odm['valeur']]);
                $this->rapport->applique($onglet, $service->nom, "Équivalent ODM : {$odm['valeur']}");
            }

            $chef = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Chef de service'));
            if ($chef['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $service->nom, 'Chef de service à Conakry', $chef['valeur']);
            } elseif ($chef['valeur'] !== null) {
                $this->chefDeService($chef['valeur'], 'Conakry', $service->nom, $onglet, $numero);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Onglet 2 — codes analytiques
     * ------------------------------------------------------------------ */

    private function codesAnalytiques(): void
    {
        $onglet = $this->classeur->titre('2-') ?? '2-Codes analytiques';

        foreach ($this->classeur->lignes('2-') as $numero => $ligne) {
            $radical = strtoupper(trim(ClasseurReferentiels::colonne($ligne, 'Radical')));
            $statut = ClasseurReferentiels::colonne($ligne, 'Statut');
            $commentaire = ClasseurReferentiels::colonne($ligne, 'Point');

            if ($radical === '') {
                $libelle = ClasseurReferentiels::colonne($ligne, 'Libellé') ?: 'Nouveau code';
                $this->rapport->aTrancher($onglet, $libelle, trim("{$statut} : " . ($commentaire ?: 'radical à fournir'), ' :'));
                continue;
            }
            if (!preg_match('/^[A-Z0-9]{6}$/', $radical)) {
                $this->rapport->anomalie($onglet, $numero, $radical, 'Un radical analytique comporte 6 caractères (lettres et chiffres).');
                continue;
            }

            $code = CodeAnalytique::where('code', $radical)->first();

            /* Codes hors de la liste de référence du CDG : ni créés ni modifiés, la décision revient au CDG */
            if (!str_contains(Normalisation::cle($statut), 'REFERENCE')) {
                $this->rapport->aTrancher($onglet, $radical, "{$statut} : " . ($commentaire ?: 'à ajouter ou à interdire ?')
                    . ($code ? " (présent dans l'application)" : " (absent de l'application, non créé)"));
                continue;
            }

            $libelle = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Libellé'));
            $serviceCdg = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Code service'));
            $actif = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Actif'));
            $valide = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Validé'));
            foreach (['Libellé' => $libelle, 'Code service' => $serviceCdg, 'Actif' => $actif, 'Validé par le CDG' => $valide] as $champ => $valeur) {
                if ($valeur['a_confirmer']) {
                    $this->rapport->aConfirmer($onglet, $numero, $radical, $champ, $valeur['valeur']);
                }
            }

            $voulu = array_filter([
                'libelle' => $libelle['a_confirmer'] ? null : $libelle['valeur'],
                'code_service_comptable' => $serviceCdg['a_confirmer'] ? null : $serviceCdg['valeur'],
                'actif' => $actif['a_confirmer'] ? null : self::ouiNon($actif['valeur']),
                'valide_cdg' => $valide['a_confirmer'] ? null : self::ouiNon($valide['valeur']),
            ], fn ($valeur) => $valeur !== null);

            if (!$code) {
                CodeAnalytique::create($voulu + [
                    'code' => $radical,
                    'libelle' => "{$radical} (libellé à compléter par le CDG)",
                    'actif' => true,
                ]);
                $this->rapport->applique($onglet, $radical, 'Code créé (liste de référence du CDG)');
            } else {
                $this->mettreAJour($code, $voulu, $onglet, $radical, [
                    'libelle' => 'Libellé', 'code_service_comptable' => 'Code service (CDG)', 'actif' => 'Actif', 'valide_cdg' => 'Validé par le CDG',
                ]);
            }

            if ($commentaire !== '') {
                $this->rapport->aTrancher($onglet, $radical, $commentaire);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Onglet 1 — utilisateurs
     * ------------------------------------------------------------------ */

    private const CHAMPS_UTILISATEUR = [
        'matricule' => 'Matricule',
        'email' => 'Email',
        'telephone' => 'Téléphone',
        'entite' => 'Entité',
        'site' => 'Site',
        'service' => 'Service',
        'poste' => 'Fonction',
        'statut_cadre' => 'Statut',
        'actif' => 'Actif',
    ];

    private function utilisateurs(): void
    {
        $onglet = $this->classeur->titre('1-') ?? '1-Utilisateurs';
        $this->indexer();
        $responsables = [];

        foreach ($this->classeur->lignes('1-') as $numero => $ligne) {
            $nom = trim(ClasseurReferentiels::colonne($ligne, 'Nom'));
            $prenom = trim(ClasseurReferentiels::colonne($ligne, 'Prénom'));
            if ($nom === '' || str_starts_with(Normalisation::cle($nom), 'EXEMPLE')) {
                continue;
            }
            $nom = mb_strtoupper($nom);
            $element = trim("{$nom} {$prenom}");

            /* Valeurs sûres de la ligne ; les valeurs « à confirmer » sont listées */
            $attributs = [];
            foreach (self::CHAMPS_UTILISATEUR as $champ => $colonne) {
                $valeur = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, $colonne));
                if ($valeur['a_confirmer']) {
                    $this->rapport->aConfirmer($onglet, $numero, $element, $colonne, $valeur['valeur']);
                } elseif ($valeur['valeur'] !== null) {
                    $converti = $this->convertir($champ, $valeur['valeur'], $onglet, $numero, $element);
                    if ($converti !== null) {
                        $attributs[$champ] = $converti;
                    }
                }
            }

            $roles = $this->roles(ClasseurReferentiels::colonne($ligne, 'Rôle'), (string) ($attributs['poste'] ?? ''), $onglet, $numero, $element);
            [$utilisateur, $rapprochement] = $this->trouver($attributs['email'] ?? null, $attributs['matricule'] ?? null, $nom, $prenom);

            if (!$utilisateur) {
                $utilisateur = $this->creerCompte($nom, $prenom, $attributs, $roles, $onglet, $numero, $element);
                if (!$utilisateur) {
                    continue;
                }
            } else {
                if ($rapprochement) {
                    $this->rapport->applique($onglet, $element, "Rapproché du compte {$utilisateur->email} ({$rapprochement})");
                }
                $this->mettreAJourCompte($utilisateur, $nom, $prenom, $attributs, $onglet, $numero, $element);
            }
            $this->rencontres[$utilisateur->id] = true;
            $this->attribuerRoles($utilisateur, $roles, $onglet);

            $responsable = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Responsable'));
            if ($responsable['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, 'Responsable N+1', $responsable['valeur']);
            } elseif ($responsable['valeur'] !== null) {
                $responsables[] = [$utilisateur, $responsable['valeur'], $numero, $element];
            }

            $commentaire = ClasseurReferentiels::colonne($ligne, 'Commentaire');
            if (str_contains($commentaire, '?')) {
                $this->rapport->aTrancher($onglet, $element, $commentaire);
            }
        }

        /* Responsables N+1, une fois tous les comptes connus */
        foreach ($responsables as [$utilisateur, $texte, $numero, $element]) {
            $responsable = $this->personne($texte);
            if (!$responsable) {
                $this->rapport->anomalie($onglet, $numero, $element, "Responsable N+1 « {$texte} » introuvable parmi les comptes.");
            } elseif ($utilisateur->responsable_id !== $responsable->id) {
                $utilisateur->update(['responsable_id' => $responsable->id]);
                $this->rapport->applique($onglet, $element, 'Responsable N+1 : ' . self::libelle($responsable));
            }
        }

        if ($this->crees !== []) {
            $this->rapport->action(count($this->crees) . ' compte(s) créé(s)', "Aucun mot de passe n'est communiqué : chaque personne définit le sien par « Mot de passe oublié » "
                . "sur la page de connexion, ou l'administrateur le réinitialise (Utilisateurs).");
        }
    }

    private function creerCompte(string $nom, string $prenom, array $attributs, array $roles, string $onglet, int $numero, string $element): ?User
    {
        if (empty($attributs['email'])) {
            $this->rapport->anomalie($onglet, $numero, $element, 'Compte non créé : adresse e-mail manquante (indispensable pour se connecter).');

            return null;
        }
        if (!empty($attributs['matricule']) && User::where('matricule', $attributs['matricule'])->exists()) {
            $this->rapport->anomalie($onglet, $numero, $element, "Matricule {$attributs['matricule']} déjà attribué à un autre compte : non repris.");
            unset($attributs['matricule']);
        }

        $principal = collect(self::PRIORITE)->first(fn (string $role) => in_array($role, $roles, true)) ?? 'demandeur';
        $utilisateur = User::create($attributs + [
            'name' => $nom,
            'prenom' => $prenom,
            'password' => Str::random(40),
            'role' => $principal,
            'actif' => true,
        ]);
        $this->crees[$utilisateur->id] = true;
        $this->indexer();
        $this->rapport->applique($onglet, $element, "Compte créé ({$utilisateur->email}) — rôle(s) : " . self::libellesRoles($utilisateur->listeRoles()));

        return $utilisateur;
    }

    private function mettreAJourCompte(User $utilisateur, string $nom, string $prenom, array $attributs, string $onglet, int $numero, string $element): void
    {
        /* Orthographe du nom et du prénom : celle du référentiel (ex. « Saoudou » → « Souadou », décision Q11) */
        $identite = [];
        if ($nom !== '' && $nom !== $utilisateur->name) {
            $identite['name'] = $nom;
        }
        if ($prenom !== '' && $prenom !== $utilisateur->prenom) {
            $identite['prenom'] = $prenom;
        }

        if (isset($attributs['email']) && strtolower($attributs['email']) !== strtolower($utilisateur->email)) {
            if (User::whereRaw('LOWER(email) = ?', [strtolower($attributs['email'])])->exists()) {
                $this->rapport->anomalie($onglet, $numero, $element, "Adresse {$attributs['email']} déjà utilisée par un autre compte : non reprise.");
                unset($attributs['email']);
            } else {
                $this->rapport->action($element, "Adresse de connexion modifiée : {$utilisateur->email} → {$attributs['email']}. Prévenir la personne.");
            }
        }
        if (isset($attributs['matricule']) && $attributs['matricule'] !== $utilisateur->matricule
            && User::where('matricule', $attributs['matricule'])->whereKeyNot($utilisateur->id)->exists()) {
            $this->rapport->anomalie($onglet, $numero, $element, "Matricule {$attributs['matricule']} déjà attribué à un autre compte : non repris.");
            unset($attributs['matricule']);
        }

        $this->mettreAJour($utilisateur, $identite + $attributs, $onglet, $element, [
            'name' => 'Nom', 'prenom' => 'Prénom', 'matricule' => 'Matricule', 'email' => 'Email', 'telephone' => 'Téléphone',
            'entite' => 'Entité', 'site' => 'Site', 'service' => 'Service', 'poste' => 'Fonction',
            'statut_cadre' => 'Statut', 'actif' => 'Actif',
        ]);
        $this->indexer();
    }

    /** Valeur d'une cellule de l'onglet 1 dans le format de l'application (null : anomalie signalée) */
    private function convertir(string $champ, string $valeur, string $onglet, int $numero, string $element): mixed
    {
        switch ($champ) {
            case 'email':
                $email = strtolower($valeur);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->rapport->anomalie($onglet, $numero, $element, "Adresse e-mail invalide : {$valeur}");

                    return null;
                }

                return $email;
            case 'telephone':
                $telephone = ReglesSaisie::normaliserTelephone($valeur);
                if (!preg_match('/^\+2246\d{8}$/', (string) $telephone)) {
                    $this->rapport->anomalie($onglet, $numero, $element, "Téléphone non guinéen ou incomplet : {$valeur} (repris tel quel)");
                }

                return $telephone;
            case 'site':
                $site = $this->site($valeur);
                if (!$site) {
                    $this->rapport->anomalie($onglet, $numero, $element, "Site inconnu : {$valeur}");
                }

                return $site?->nom;
            case 'service':
                $service = Service::all()->first(fn (Service $s) => Normalisation::cle($s->nom) === Normalisation::cle($valeur));
                if (!$service) {
                    $this->rapport->anomalie($onglet, $numero, $element, "Service inconnu : {$valeur}");
                }

                return $service?->nom;
            case 'statut_cadre':
                $cle = Normalisation::cle($valeur);
                if (in_array($cle, ['CADRE', 'NON CADRE'], true)) {
                    return $cle === 'CADRE' ? 'cadre' : 'non_cadre';
                }
                $this->rapport->anomalie($onglet, $numero, $element, "Statut attendu « Cadre » ou « Non-cadre » : {$valeur}");

                return null;
            case 'actif':
                return self::ouiNon($valeur);
            default:
                return $valeur;
        }
    }

    /* ------------------------------------------------------------------
     * Rôles
     * ------------------------------------------------------------------ */

    /**
     * Rôles de la colonne « Rôle(s) dans la plateforme » de l'onglet 1.
     * Un chef de service se désigne dans l'onglet 5 (site et service) : il n'est pas attribué d'ici.
     *
     * @return string[]
     */
    private function roles(string $cellule, string $fonction, string $onglet, int $numero, string $element): array
    {
        $roles = [];
        foreach (preg_split('/[,;]/', $cellule) as $morceau) {
            $morceau = trim($morceau);
            if ($morceau === '') {
                continue;
            }
            if (Normalisation::valeur($morceau)['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, 'Rôle', $morceau);
                continue;
            }

            $cle = Normalisation::cle($morceau);
            $role = match (true) {
                str_starts_with($cle, 'FINANCE') => str_contains($cle, 'VISA DAF') ? 'daf' : $this->roleFinance($fonction),
                str_starts_with($cle, 'DP ADJOINT'), str_starts_with($cle, 'DIRECTEUR PAYS ADJOINT') => 'dp_adjoint',
                str_starts_with($cle, 'DIRECTEUR PAYS') => 'directeur_pays',
                str_starts_with($cle, 'CHEF D ATELIER'), str_starts_with($cle, 'CHEF D EQUIPE') => 'chef_atelier',
                str_starts_with($cle, 'LOGISTIQUE') => 'logistique',
                $cle === 'CDG', str_starts_with($cle, 'CONTROLE DE GESTION') => 'controle_gestion',
                str_starts_with($cle, 'TRESORERIE') => 'tresorerie',
                str_starts_with($cle, 'CAISSIER') => 'caissier',
                str_starts_with($cle, 'CHEF DE SERVICE') => 'responsable_service',
                str_starts_with($cle, 'DEMANDEUR') => 'demandeur',
                $cle === 'RH', str_starts_with($cle, 'RESSOURCES HUMAINES') => 'rh',
                str_starts_with($cle, 'ADMINISTRATEUR') => 'administrateur',
                default => null,
            };

            if ($role === null) {
                $this->rapport->anomalie($onglet, $numero, $element, "Rôle non reconnu : « {$morceau} »");
            } elseif ($role === 'caissier' && preg_match('/caisse\s+\S.*$/iu', $morceau, $caisse)) {
                /* « Caissier — caisse Atelier » : aujourd'hui, le rôle caissier ouvre la caisse principale du site */
                $this->rapport->action($element, "Caissier de la {$caisse[0]} seulement : le rôle caissier n'est pas attribué, car il donne aujourd'hui "
                    . "accès à la caisse principale du site. La personne est enregistrée comme gestionnaire de la caisse ; le rôle viendra avec la "
                    . 'gestion par caisse (M07).');
            } elseif ($role === 'responsable_service') {
                $this->rapport->aConfirmer($onglet, $numero, $element, 'Chef de service', "{$morceau} : à désigner dans l'onglet 5 (site et service)");
            } elseif ($role === 'chef_atelier') {
                $this->rapport->aConfirmer($onglet, $numero, $element, "Chef d'atelier (ODM)", "{$morceau} : à désigner dans l'onglet 5 (site et service)");
            } else {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }

    /** Visa Finance : DAF, DAF adjoint ou chef comptable (« un seul des trois suffit ») */
    private function roleFinance(string $fonction): ?string
    {
        $cle = Normalisation::cle($fonction);

        return match (true) {
            str_contains($cle, 'ADJOINT') => 'daf_adjoint',
            str_contains($cle, 'COMPTABLE') => 'chef_comptable',
            $cle === 'DAF', str_contains($cle, 'DIRECTEUR ADMINISTRATIF') => 'daf',
            default => null,
        };
    }

    private function attribuerRoles(User $utilisateur, array $roles, string $onglet): void
    {
        $nouveaux = array_values(array_diff(array_filter($roles), $utilisateur->listeRoles()));
        if ($nouveaux === []) {
            return;
        }

        $compteCree = isset($this->crees[$utilisateur->id]);
        $directs = $compteCree ? $nouveaux : array_values(array_diff($nouveaux, self::ROLES_PRIVILEGIES));
        $manuels = $compteCree ? [] : array_values(array_intersect($nouveaux, self::ROLES_PRIVILEGIES));

        if ($directs !== []) {
            $utilisateur->ajouterRoles($directs);
            $this->rapport->applique($onglet, self::libelle($utilisateur), 'Rôle(s) ajouté(s) : ' . self::libellesRoles($directs));
        }
        foreach ($manuels as $role) {
            $this->rapport->action(self::libelle($utilisateur), 'Attribuer le rôle « ' . (User::ROLES[$role] ?? $role) . " » (Utilisateurs) : "
                . "sur un compte existant, un rôle à privilèges s'attribue à l'écran, avec sa double validation.");
        }
    }

    /** Rôle principal d'un compte créé : le plus élevé de ses rôles (certains écrans lisent encore users.role) */
    private function rolesPrincipaux(): void
    {
        foreach (array_keys($this->crees) as $id) {
            $utilisateur = User::find($id);
            $principal = collect(self::PRIORITE)->first(fn (string $role) => $utilisateur->aLeRole($role)) ?? 'demandeur';
            if ($principal !== $utilisateur->role) {
                $utilisateur->update(['role' => $principal]);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Onglet 5 — valideurs
     * ------------------------------------------------------------------ */

    private function valideurs(): void
    {
        $onglet = $this->classeur->titre('5-') ?? '5-Valideurs';

        foreach ($this->classeur->lignes('5-') as $numero => $ligne) {
            $site = ClasseurReferentiels::colonne($ligne, 'Site');
            $service = ClasseurReferentiels::colonne($ligne, 'Service');
            $niveau = ClasseurReferentiels::colonne($ligne, 'Niveau');
            $cleNiveau = Normalisation::cle($niveau);

            foreach (['Titulaire', 'Suppléant 1', 'Suppléant 2'] as $qualite) {
                $texte = ClasseurReferentiels::colonne($ligne, $qualite);
                if ($texte === '') {
                    continue;
                }
                $valeur = Normalisation::valeur($texte);
                $quoi = "{$niveau} — {$qualite}" . ($site !== 'Tous' ? " ({$site}, {$service})" : '');
                if ($valeur['a_confirmer']) {
                    $this->rapport->aConfirmer($onglet, $numero, $valeur['valeur'] ?? $texte, $quoi, $texte);
                    continue;
                }
                if ($valeur['valeur'] === null) {
                    continue;
                }

                [$nomComplet, $precision] = Normalisation::personne($valeur['valeur']);
                if (str_starts_with($cleNiveau, 'CHEF DE SERVICE')) {
                    $qualite === 'Titulaire'
                        ? $this->chefDeService($valeur['valeur'], $site, $service, $onglet, $numero)
                        : $this->rapport->action($nomComplet, "Suppléant du chef de service ({$site}, {$service}) : à mettre en place par une délégation lors des absences du titulaire.");
                    continue;
                }
                /* M12 : le chef d'atelier ou chef d'équipe vise les ODM de son service (premier niveau du circuit) */
                if (str_starts_with($cleNiveau, 'CHEF D ATELIER') || str_starts_with($cleNiveau, 'CHEF D EQUIPE')) {
                    $qualite === 'Titulaire'
                        ? $this->chefDeService($valeur['valeur'], $site, $service, $onglet, $numero, 'chef_atelier')
                        : $this->rapport->action($nomComplet, "Suppléant du chef d'atelier ({$site}, {$service}) : à mettre en place par une délégation lors des absences du titulaire.");
                    continue;
                }

                $role = match (true) {
                    str_starts_with($cleNiveau, 'CONTROLE DE GESTION') => 'controle_gestion',
                    str_starts_with($cleNiveau, 'FINANCE') => $precision ? $this->roleFinance($precision) : 'daf',
                    str_starts_with($cleNiveau, 'DIRECTEUR PAYS ADJOINT'), str_starts_with($cleNiveau, 'DP ADJOINT') => 'dp_adjoint',
                    str_starts_with($cleNiveau, 'DIRECTEUR PAYS') => str_contains(Normalisation::cle((string) $precision), 'ADJOINT') ? 'dp_adjoint' : 'directeur_pays',
                    str_starts_with($cleNiveau, 'LOGISTIQUE') => 'logistique',
                    str_starts_with($cleNiveau, 'TRESORERIE') => 'tresorerie',
                    str_starts_with($cleNiveau, 'RH') => 'rh',
                    default => null,
                };
                if ($role === null) {
                    $this->rapport->aTrancher($onglet, $nomComplet, "Niveau « {$niveau} » non reconnu : noté sans effet.");
                    continue;
                }

                $personne = $this->personne($nomComplet);
                if (!$personne) {
                    $this->rapport->anomalie($onglet, $numero, $nomComplet, "Personne introuvable parmi les comptes (niveau « {$niveau} ») : à créer dans l'onglet 1 avec son adresse e-mail.");
                    continue;
                }

                /* Finance et Trésorerie : les trois personnes valent titulaires ; ailleurs, un suppléant passe par une délégation */
                if ($qualite !== 'Titulaire' && !in_array($role, ['daf', 'daf_adjoint', 'chef_comptable', 'tresorerie'], true)) {
                    $this->rapport->action(self::libelle($personne), "Suppléant ({$niveau}) : à mettre en place par une délégation lors des absences du titulaire.");
                    continue;
                }
                $this->attribuerRoles($personne, [$role], $onglet);
            }
        }
    }

    /**
     * Chef de service (rôle responsable_service) ou chef d'atelier / chef d'équipe (rôle chef_atelier, M12) d'un site et
     * d'un service, si le compte est bien rattaché à ce site et ce service
     */
    private function chefDeService(string $texte, string $site, string $service, string $onglet, int $numero, string $role = 'responsable_service'): void
    {
        $fonction = $role === 'chef_atelier' ? "Chef d'atelier" : 'Chef de service';
        [$nomComplet] = Normalisation::personne($texte);
        $personne = $this->personne($nomComplet);
        if (!$personne) {
            $this->rapport->anomalie($onglet, $numero, $nomComplet, "{$fonction} ({$site}, {$service}) introuvable parmi les comptes.");

            return;
        }

        $siteVoulu = $this->site($site);
        $serviceVoulu = Normalisation::cle(Normalisation::personne($service)[0]);
        if (($siteVoulu && $personne->site !== $siteVoulu->nom) || Normalisation::cle($personne->service) !== $serviceVoulu) {
            $this->rapport->anomalie($onglet, $numero, self::libelle($personne), 'Désigné ' . mb_strtolower($fonction) . " {$service} à {$site}, mais son compte est rattaché à "
                . ($personne->service ?: 'aucun service') . ' à ' . ($personne->site ?: 'aucun site') . ' : corriger le compte ou la désignation.');

            return;
        }
        $this->attribuerRoles($personne, [$role], $onglet);
    }

    /* ------------------------------------------------------------------
     * Onglet 4 — caisses
     * ------------------------------------------------------------------ */

    /** Libellés de site du classeur (« CORICA », « Mandiana (Siguiri Technique) ») rattachés par leur code */
    private function sitesDesCaisses(): void
    {
        foreach ($this->classeur->lignes('4-') as $ligne) {
            $libelle = ClasseurReferentiels::colonne($ligne, 'Site');
            $code = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Code site'));
            if ($libelle !== '' && $code['valeur'] !== null && !$code['a_confirmer'] && ($site = $this->siteParCode($code['valeur']))) {
                $this->sitesDuClasseur[Normalisation::cle($libelle)] = $site;
            }
        }
    }

    private const CHAMPS_SENSIBLES = [
        'plafond_caisse' => 'Plafond de caisse',
        'seuil_alerte' => 'Seuil de réapprovisionnement',
        'plafond_retrait' => 'Plafond de retrait',
    ];

    private function caisses(): void
    {
        $onglet = $this->classeur->titre('4-') ?? '4-Caisses';

        foreach ($this->classeur->lignes('4-') as $numero => $ligne) {
            $libelle = ClasseurReferentiels::colonne($ligne, 'Caisse');
            $code = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Code site'));
            $entite = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Entité'));
            $commentaire = ClasseurReferentiels::colonne($ligne, 'Commentaire');
            $element = $libelle ?: ClasseurReferentiels::colonne($ligne, 'Site');

            if ($code['a_confirmer'] || $entite['a_confirmer'] || $code['valeur'] === null) {
                $this->rapport->aTrancher($onglet, $element, 'Caisse non importée : site ou entité à confirmer. ' . $commentaire);
                continue;
            }
            $site = $this->siteParCode($code['valeur']);
            if (!$site) {
                $this->rapport->anomalie($onglet, $numero, $element, "Aucun site de code {$code['valeur']} dans l'application.");
                continue;
            }

            $type = str_contains(Normalisation::cle(ClasseurReferentiels::colonne($ligne, 'Nature')), 'ORANGE') ? 'orange_money' : 'especes';
            $caisse = $this->caisse($site, $type, $libelle, ClasseurReferentiels::colonne($ligne, 'Mode de réapprovisionnement'));
            if (!$caisse) {
                $this->rapport->anomalie($onglet, $numero, $element, "Caisse introuvable sur le site {$site->nom} : la créer dans Paramétrage › Caisses, puis relancer l'import.");
                continue;
            }
            $element = "{$caisse->code} — " . ($libelle ?: $caisse->libelle);

            if ($libelle !== '' && $libelle !== $caisse->libelle) {
                $this->rapport->applique($onglet, $element, "Libellé : {$caisse->libelle} → {$libelle}");
                $caisse->update(['libelle' => $libelle]);
            }

            foreach (self::CHAMPS_SENSIBLES as $champ => $colonne) {
                $valeur = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, $colonne));
                if ($valeur['a_confirmer']) {
                    $this->rapport->aConfirmer($onglet, $numero, $element, $colonne, $valeur['valeur']);
                    continue;
                }
                if ($valeur['valeur'] === null) {
                    continue;
                }
                $sansPlafond = str_contains(Normalisation::cle($valeur['valeur']), 'SANS');
                $montant = $sansPlafond ? null : Normalisation::montant($valeur['valeur']);
                if (!$sansPlafond && $montant === null) {
                    $this->rapport->anomalie($onglet, $numero, $element, "{$colonne} illisible : {$valeur['valeur']}");
                    continue;
                }
                $this->modifierSensible($caisse, $champ, $montant, $element, $colonne, $onglet);
            }

            $this->responsablesDeCaisse($caisse, $ligne, $onglet, $numero, $element);
            $this->reapprovisionnement($caisse, $ligne, $onglet, $numero, $element);

            $encaissements = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Encaissements'));
            $oui = self::ouiNon($encaissements['valeur']);
            if ($encaissements['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, 'Encaissements clients', $encaissements['valeur']);
            } elseif ($oui !== null && $oui !== $caisse->encaissements_clients) {
                $caisse->update(['encaissements_clients' => $oui]);
                $this->rapport->applique($onglet, $element, 'Encaissements clients : ' . ($oui ? 'oui' : 'non'));
            }

            if ($commentaire !== '') {
                $this->rapport->aTrancher($onglet, $element, $commentaire);
            }
        }
    }

    private function responsablesDeCaisse(Caisse $caisse, array $ligne, string $onglet, int $numero, string $element): void
    {
        foreach (['gestionnaire_id' => 'Gestionnaire', 'suppleant_id' => 'Suppléant'] as $champ => $colonne) {
            $valeur = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, $colonne));
            if ($valeur['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, $colonne, $valeur['valeur']);
                continue;
            }
            if ($valeur['valeur'] === null) {
                continue;
            }
            $personne = $this->personne($valeur['valeur']);
            if (!$personne) {
                $this->rapport->anomalie($onglet, $numero, $element, "{$colonne} « {$valeur['valeur']} » introuvable parmi les comptes.");
            } elseif ($caisse->$champ !== $personne->id) {
                $caisse->update([$champ => $personne->id]);
                $this->rapport->applique($onglet, $element, "{$colonne} : " . self::libelle($personne));
            }
        }

        $cellule = ClasseurReferentiels::colonne($ligne, 'Destinataires');
        if ($cellule === '') {
            return;
        }
        $ids = [];
        $noms = [];
        foreach (preg_split('/[,;]/', $cellule) as $texte) {
            $valeur = Normalisation::valeur($texte);
            if ($valeur['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, 'Destinataire du rapport journalier', $valeur['valeur']);
            } elseif ($valeur['valeur'] !== null) {
                $personne = $this->personne($valeur['valeur']);
                if ($personne) {
                    $ids[] = $personne->id;
                    $noms[] = self::libelle($personne);
                } else {
                    $this->rapport->anomalie($onglet, $numero, $element, "Destinataire du rapport « {$valeur['valeur']} » introuvable parmi les comptes : non repris.");
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== [] && $ids != ($caisse->destinataires_rapport ?? [])) {
            $caisse->update(['destinataires_rapport' => $ids]);
            $this->rapport->applique($onglet, $element, 'Destinataires du rapport journalier : ' . implode(', ', $noms));
        }
    }

    private function reapprovisionnement(Caisse $caisse, array $ligne, string $onglet, int $numero, string $element): void
    {
        $mode = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Mode de réapprovisionnement'));
        $origine = Normalisation::valeur(ClasseurReferentiels::colonne($ligne, 'Caisse ou compte'));
        foreach (['Mode de réapprovisionnement' => $mode, 'Origine du réapprovisionnement' => $origine] as $colonne => $valeur) {
            if ($valeur['a_confirmer']) {
                $this->rapport->aConfirmer($onglet, $numero, $element, $colonne, $valeur['valeur']);
            }
        }
        if ($mode['a_confirmer'] || $mode['valeur'] === null) {
            return;
        }

        $texte = $mode['valeur'] . ($origine['valeur'] !== null && !$origine['a_confirmer'] ? " — {$origine['valeur']}" : '');
        if ($texte !== $caisse->reapprovisionnement) {
            $caisse->update(['reapprovisionnement' => $texte]);
            $this->rapport->applique($onglet, $element, "Réapprovisionnement : {$texte}");
        }
    }

    /** Plafond, seuil, avance : double validation (comme à l'écran), sauf installation initiale */
    private function modifierSensible(Caisse $caisse, string $champ, ?float $nouvelle, string $element, string $libelle, string $onglet): void
    {
        $ancienne = $caisse->$champ === null ? null : (float) $caisse->$champ;
        if ($ancienne === $nouvelle) {
            return;
        }
        $avant = $ancienne === null ? 'aucun' : Format::montant($ancienne);
        $apres = $nouvelle === null ? 'sans plafond' : Format::montant($nouvelle);

        if (!$this->doubleValidation) {
            $caisse->update([$champ => $nouvelle]);
            $this->rapport->applique($onglet, $element, "{$libelle} : {$avant} → {$apres} (sans double validation : installation initiale)");

            return;
        }

        $dejaDemandee = ModificationEnAttente::where('type_entite', 'caisse')
            ->where('entite_id', $caisse->id)
            ->where('champ', $champ)
            ->where('statut', 'en_attente')
            ->get()
            ->contains(fn (ModificationEnAttente $m) => ($m->nouvelle_valeur === null ? null : (float) $m->nouvelle_valeur) === $nouvelle);

        if (!$dejaDemandee) {
            if (!$this->auteur) {
                throw new \RuntimeException("Indiquez l'administrateur qui demande les modifications sensibles (option --auteur).");
            }
            ModificationEnAttente::create([
                'type_entite' => 'caisse',
                'entite_id' => $caisse->id,
                'champ' => $champ,
                'ancienne_valeur' => $ancienne,
                'nouvelle_valeur' => $nouvelle,
                'demandeur_id' => $this->auteur->id,
                'statut' => 'en_attente',
                'commentaire' => "Import du référentiel {$this->classeur->nomFichier}",
            ]);
        }
        $this->rapport->enDoubleValidation($element, $libelle, $avant, $apres . ($dejaDemandee ? ' (déjà en attente)' : ''));
    }

    /** Caisse du classeur : la seule du site pour ce type, sinon par libellé, sinon par mode (avance fixe ou standard) */
    private function caisse(Site $site, string $type, string $libelle, string $modeReapprovisionnement): ?Caisse
    {
        $caisses = Caisse::where('site_id', $site->id)->where('type', $type)->get();
        if ($caisses->count() === 1) {
            return $caisses->first();
        }
        $parLibelle = $caisses->first(fn (Caisse $c) => Normalisation::cle($c->libelle) === Normalisation::cle($libelle));
        if ($parLibelle) {
            return $parLibelle;
        }

        $avanceFixe = str_contains(Normalisation::cle($modeReapprovisionnement), 'AVANCE FIXE') || str_contains(Normalisation::cle($libelle), 'ATELIER');
        $parMode = $caisses->where('mode', $avanceFixe ? 'avance_fixe' : 'standard');

        return $parMode->count() === 1 ? $parMode->first() : null;
    }

    /* ------------------------------------------------------------------
     * Personnes et sites
     * ------------------------------------------------------------------ */

    private function indexer(): void
    {
        $this->index = [];
        foreach (User::all(['id', 'name', 'prenom']) as $utilisateur) {
            foreach ([$utilisateur->name . ' ' . $utilisateur->prenom, $utilisateur->prenom . ' ' . $utilisateur->name] as $nom) {
                $this->index[Normalisation::cle($nom)][] = $utilisateur->id;
            }
        }
    }

    /**
     * Compte d'une personne du classeur : par e-mail, par matricule, par nom exact, sinon par nom proche
     * (même nom de famille, prénom à deux lettres près, une seule possibilité : « Saoudou » / « Souadou »).
     *
     * @return array{0: ?User, 1: ?string}
     */
    private function trouver(?string $email, ?string $matricule, string $nom, string $prenom): array
    {
        if ($email && ($utilisateur = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first())) {
            return [$utilisateur, null];
        }
        if ($matricule && ($utilisateur = User::where('matricule', $matricule)->first())) {
            return [$utilisateur, 'même matricule'];
        }
        $proche = false;
        $utilisateur = $this->personne("{$nom} {$prenom}", $proche);

        return [$utilisateur, $utilisateur && $proche ? 'orthographe du prénom proche' : null];
    }

    /** « DIAKITE Mohamed » ou « Mohamed DIAKITE » → compte (null si inconnu ou homonymes) */
    private function personne(string $texte, bool &$proche = false): ?User
    {
        $cle = Normalisation::cle(Normalisation::personne($texte)[0]);
        if ($cle === '') {
            return null;
        }
        $ids = array_values(array_unique($this->index[$cle] ?? []));
        if (count($ids) === 1) {
            return User::find($ids[0]);
        }
        if (count($ids) > 1) {
            return null;
        }

        $candidats = User::all()->filter(function (User $utilisateur) use ($cle) {
            $nom = Normalisation::cle($utilisateur->name);
            $prenom = Normalisation::cle($utilisateur->prenom);
            if ($nom === '' || $prenom === '') {
                return false;
            }
            foreach ([[$nom . ' ', true], [' ' . $nom, false]] as [$morceau, $enTete]) {
                $reste = $enTete
                    ? (str_starts_with($cle, $morceau) ? substr($cle, strlen($morceau)) : null)
                    : (str_ends_with($cle, $morceau) ? substr($cle, 0, -strlen($morceau)) : null);
                if ($reste !== null && $reste !== '' && levenshtein($reste, $prenom) <= 2) {
                    return true;
                }
            }

            return false;
        });

        if ($candidats->count() === 1) {
            $proche = true;

            return $candidats->first();
        }

        return null;
    }

    private function site(string $texte): ?Site
    {
        $cle = Normalisation::cle($texte);
        if (isset($this->sitesDuClasseur[$cle])) {
            return $this->sitesDuClasseur[$cle];
        }
        $sansPrecision = Normalisation::cle(Normalisation::personne($texte)[0]);

        return Site::all()->first(fn (Site $site) => in_array(Normalisation::cle($site->nom), [$cle, $sansPrecision], true)
            || in_array(Normalisation::cle($site->ville), [$cle, $sansPrecision], true));
    }

    private function siteParCode(string $code): ?Site
    {
        return Site::all()->first(fn (Site $site) => ltrim((string) $site->code, '0') === ltrim($code, '0'));
    }

    /* ------------------------------------------------------------------ */

    /** Comptes actifs de l'application absents du classeur : à ajouter à l'onglet 1 ou à désactiver */
    private function comptesAbsents(): void
    {
        $absents = User::where('actif', true)->get()->reject(fn (User $u) => isset($this->rencontres[$u->id]));
        foreach ($absents as $utilisateur) {
            $this->rapport->action(self::libelle($utilisateur), "Compte actif ({$utilisateur->email}) absent du classeur : l'ajouter à l'onglet 1, ou le désactiver s'il n'a plus lieu d'être.");
        }
    }

    /** Applique les valeurs qui changent et les inscrit au compte rendu */
    private function mettreAJour(\Illuminate\Database\Eloquent\Model $modele, array $voulu, string $onglet, string $element, array $libelles): void
    {
        $changements = [];
        foreach ($voulu as $champ => $valeur) {
            $actuelle = $modele->$champ;
            $identique = is_bool($valeur) ? (bool) $actuelle === $valeur : (string) $actuelle === (string) $valeur;
            if (!$identique) {
                $changements[$champ] = $valeur;
                $avant = is_bool($actuelle) ? ($actuelle ? 'oui' : 'non') : ($actuelle === null || $actuelle === '' ? 'vide' : $actuelle);
                $apres = is_bool($valeur) ? ($valeur ? 'oui' : 'non') : $valeur;
                $this->rapport->applique($onglet, $element, ($libelles[$champ] ?? $champ) . " : {$avant} → {$apres}");
            }
        }
        if ($changements !== []) {
            $modele->update($changements);
        }
    }

    private static function ouiNon(?string $valeur): ?bool
    {
        return match (Normalisation::cle($valeur)) {
            'O', 'OUI' => true,
            'N', 'NON' => false,
            default => null,
        };
    }

    /** « BARRY Souadou », comme dans le classeur */
    private static function libelle(User $utilisateur): string
    {
        return trim(mb_strtoupper($utilisateur->name) . ' ' . $utilisateur->prenom);
    }

    private static function libellesRoles(array $roles): string
    {
        return implode(', ', array_map(fn ($role) => User::ROLES[$role] ?? $role, $roles));
    }
}
