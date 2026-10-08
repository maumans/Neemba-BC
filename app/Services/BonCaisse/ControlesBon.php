<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Parametre;
use App\Models\PieceJointe;
use App\Services\LectureTicket\LectureTickets;
use App\Support\Format;

/**
 * Écran Contrôle (US-BC-10, SFD §5.4.7) : les 12 contrôles du bon avant soumission.
 * Recalculés à l'ouverture de l'étape 5 et refaits par le serveur à la soumission (RG-BC-24, RG-BC-27).
 *
 * Niveau : « ok » (vert), « avertissement » (orange, n'empêche pas la soumission), « bloquant » (rouge).
 */
class ControlesBon
{
    public const OK = 'ok';
    public const AVERTISSEMENT = 'avertissement';
    public const BLOQUANT = 'bloquant';

    /** Règle de gestion en cause, selon le message (renvoyée dans le refus de soumission, SFD §5.7) */
    private const REGLES = [
        'MSG-BC-040' => 'RG-BC-24',
        'MSG-BC-012' => 'RG-BC-11',
        'MSG-BC-041' => 'RG-BC-12',
        'MSG-BC-016' => 'RG-BC-18',
        'MSG-BC-017' => 'RG-BC-15',
        'MSG-BC-018' => 'RG-BC-16',
        'MSG-BC-019' => 'RG-BC-19',
        'MSG-BC-006' => 'RG-BC-07',
        'MSG-BC-042' => 'RG-BC-24',
        'MSG-BC-033' => 'RG-BC-29',
        'MSG-APP-005' => 'RG-BC-21',
        'MSG-BC-022' => 'RG-BC-22',
        'MSG-BC-023' => 'RG-BC-22',
        'MSG-BC-024' => 'RG-BC-22',
        'MSG-BC-025' => 'RG-BC-22',
        'MSG-BC-026' => 'RG-BC-22',
    ];

    /**
     * @return array<int, array{numero: int, code: string, libelle: string, niveau: string, message: string,
     *                          regle: ?string, message_cle: ?string, valeurs: array, etape: ?int, champ: ?string}>
     */
    public static function executer(BonCaisse $bon, ?\App\Models\User $auteur = null): array
    {
        $bon->loadMissing(['piecesActives.lectureTicket', 'piecesActives.doublonDe.bonCaisse']);
        $caisse = Caisse::payeusePour((string) $bon->site, $bon->mode_paiement);
        $montant = (float) $bon->montant;

        return [
            self::champsObligatoires($bon),
            self::montantEtMode($bon, $caisse, $montant),
            self::soldeCaisse($caisse, $montant),
            self::piecesJustificatives($bon),
            self::qualitePieces($bon),
            self::piecesDejaUtilisees($bon),
            self::lectureTickets($bon),
            self::telephoneRetrait($bon),
            self::circuit($bon),
            self::visaDirecteurPays($bon),
            self::suiviApresPaiement($bon),
            self::delegation($bon, $auteur),
        ];
    }

    /** Un contrôle bloquant empêche la soumission (RG-BC-24) */
    public static function bloquants(array $controles): array
    {
        return array_values(array_filter($controles, fn (array $controle) => $controle['niveau'] === self::BLOQUANT));
    }

    /* ------------------------------------------------------------------ */

    private static function champsObligatoires(BonCaisse $bon): array
    {
        $erreurs = ReglesSaisie::erreursCompletes(ReglesSaisie::donneesDu($bon), [1, 2, 3], false);
        if ($erreurs->isEmpty()) {
            return self::controle(1, 'CHAMPS_OBLIGATOIRES', 'Champs obligatoires', self::OK, 'Tous les champs requis sont remplis');
        }

        $champs = array_values(array_unique(array_map(fn ($cle) => explode('.', $cle)[0], $erreurs->keys())));
        $liste = implode(', ', array_map(fn ($champ) => ReglesSaisie::LIBELLES[$champ] ?? $champ, $champs));

        return self::controle(1, 'CHAMPS_OBLIGATOIRES', 'Champs obligatoires', self::BLOQUANT,
            ErreurMetier::texte('MSG-BC-040', ['liste' => $liste]), 'MSG-BC-040', ['liste' => $liste],
            ReglesSaisie::etapeDe($champs[0]), $champs[0]);
    }

    private static function montantEtMode(BonCaisse $bon, ?Caisse $caisse, float $montant): array
    {
        if ($bon->mode_paiement === 'especes' && $caisse && $caisse->depassePlafondRetrait($montant)) {
            $valeurs = ['plafond' => (float) $caisse->plafond_retrait, 'caisse' => ReglesSaisie::libelleMinuscule($caisse->libelle)];

            return self::controle(2, 'PLAFOND_RETRAIT_DEPASSE', 'Montant et mode de paiement', self::BLOQUANT,
                ErreurMetier::texte('MSG-BC-012', $valeurs), 'MSG-BC-012', $valeurs, 3, 'mode_paiement');
        }

        $mode = BonCaisse::MODES_PAIEMENT[$bon->mode_paiement] ?? '—';

        return self::controle(2, 'MONTANT_MODE', 'Montant et mode de paiement', self::OK,
            Format::montant($montant) . " · {$mode} autorisé");
    }

    private static function soldeCaisse(?Caisse $caisse, float $montant): array
    {
        if (!$caisse) {
            return self::controle(3, 'SOLDE_CAISSE', 'Solde de la caisse', self::OK, 'Sans objet (paiement hors caisse)');
        }
        if (!$caisse->peutPayer($montant)) {
            $valeurs = ['caisse' => ReglesSaisie::libelleMinuscule($caisse->libelle)];

            return self::controle(3, 'SOLDE_INSUFFISANT', 'Solde de la caisse', self::AVERTISSEMENT,
                ErreurMetier::texte('MSG-BC-041', $valeurs), 'MSG-BC-041', $valeurs);
        }

        /* Le montant du solde n'est pas affiché à un simple demandeur (OP-BC-3) */
        return self::controle(3, 'SOLDE_CAISSE', 'Solde de la caisse', self::OK, 'Solde disponible suffisant');
    }

    private static function piecesJustificatives(BonCaisse $bon): array
    {
        $pieces = $bon->piecesActives;
        $erreur = ReglesSaisie::erreurPieces($bon);

        if ($erreur) {
            return self::controle(4, 'PIECES', 'Pièces justificatives', self::BLOQUANT,
                __($erreur), $erreur, [], 4, 'pieces');
        }
        if ($bon->type_bon === 'BP' && $pieces->isEmpty()) {
            return self::controle(4, 'PIECES', 'Pièces justificatives', self::AVERTISSEMENT,
                'Bon provisoire sans pièce : les justificatifs seront à fournir à la régularisation.');
        }
        if ($bon->type_bon === 'BP') {
            return self::controle(4, 'PIECES', 'Pièces justificatives', self::OK, $pieces->count() . ' fichier(s)');
        }

        return self::controle(4, 'PIECES', 'Pièces justificatives', self::OK,
            $pieces->count() . ' fichier(s) dont au moins un justificatif');
    }

    /** RG-BC-16 : une pièce illisible bloque ; une pièce de qualité moyenne est signalée */
    private static function qualitePieces(BonCaisse $bon): array
    {
        $pieces = $bon->piecesActives;
        if ($pieces->isEmpty()) {
            return self::controle(5, 'QUALITE', 'Qualité des pièces', self::OK, 'Sans objet');
        }

        $illisibles = $pieces->filter(fn (PieceJointe $piece) => $piece->qualite === QualitePiece::ILLISIBLE || $piece->qualite_ok === false);
        if ($illisibles->isNotEmpty()) {
            return self::controle(5, 'QUALITE', 'Qualité des pièces', self::BLOQUANT,
                __('MSG-BC-018'), 'MSG-BC-018', [], 4, 'pieces');
        }

        $moyennes = $pieces->where('qualite', QualitePiece::MOYENNE)->count();
        if ($moyennes > 0) {
            return self::controle(5, 'QUALITE_MOYENNE', 'Qualité des pièces', self::AVERTISSEMENT,
                "{$moyennes} pièce(s) de qualité moyenne : lisible(s), mais une photo plus nette évite un rejet.", null, [], 4, 'pieces');
        }

        return self::controle(5, 'QUALITE', 'Qualité des pièces', self::OK,
            $pieces->every(fn (PieceJointe $piece) => $piece->qualite === QualitePiece::CONFORME)
                ? 'Toutes conformes'
                : 'Aucune pièce illisible ni de qualité moyenne');
    }

    /**
     * RG-BC-19 : pièce identique à une pièce d'un autre bon non annulé.
     * Rouge tant que le demandeur n'a pas confirmé et justifié ; orange ensuite (visible du CDG).
     */
    private static function piecesDejaUtilisees(BonCaisse $bon): array
    {
        $doublons = $bon->piecesActives->filter(fn (PieceJointe $piece) => $piece->doublon_de_id !== null);
        $nonConfirme = $doublons->first(fn (PieceJointe $piece) => !$piece->doublon_confirme);

        if ($nonConfirme) {
            $valeurs = self::valeursDoublon($nonConfirme);

            return self::controle(6, 'PIECE_DEJA_UTILISEE', 'Pièces déjà utilisées', self::BLOQUANT,
                ErreurMetier::texte('MSG-BC-019', $valeurs), 'MSG-BC-019', $valeurs, 4, 'pieces');
        }
        if ($doublons->isNotEmpty()) {
            return self::controle(6, 'DOUBLON_JUSTIFIE', 'Pièces déjà utilisées', self::AVERTISSEMENT,
                "{$doublons->count()} pièce(s) déjà présentée(s) sur un autre bon, confirmée(s) et justifiée(s) : le contrôle de gestion en sera informé.");
        }

        return self::controle(6, 'PIECES_UNIQUES', 'Pièces déjà utilisées', self::OK, 'Aucune pièce déjà présentée sur un autre bon');
    }

    /** Numéro et date du bon qui porte déjà la pièce (MSG-BC-019) */
    public static function valeursDoublon(PieceJointe $piece): array
    {
        $autre = $piece->doublonDe?->bonCaisse;

        return [
            'numero' => $autre?->numero ?? 'en brouillon',
            'date' => ($autre?->date_soumission ?? $autre?->created_at)?->toDateString(),
        ];
    }

    /**
     * RG-BC-20 à RG-BC-22 : lecture validée de chaque ticket carburant ; cohérence des tickets (avertissements)
     * et total des tickets comparé au montant du bon (écart au-delà de 2 %, MSG-BC-024).
     */
    private static function lectureTickets(BonCaisse $bon): array
    {
        $tickets = $bon->piecesActives->where('type_document', 'recu_carburant');
        if ($tickets->isEmpty()) {
            return self::controle(7, 'TICKETS', 'Lecture des tickets', self::OK, 'Sans objet');
        }

        $lectures = $tickets->map(fn (PieceJointe $piece) => $piece->lectureTicket ? LectureTickets::actualiser($piece->lectureTicket) : null);
        if ($lectures->contains(fn ($lecture) => !$lecture?->estValidee())) {
            return self::controle(7, 'LECTURE_NON_VALIDEE', 'Lecture des tickets', self::BLOQUANT,
                __('MSG-APP-005'), 'MSG-APP-005', [], 4, 'pieces');
        }

        $avertissements = $lectures->flatMap(fn ($lecture) => LectureTickets::avertissements($lecture, $bon))->values()->all();
        $total = $lectures->sum(fn ($lecture) => (int) ($lecture->valeurs_validees['montant'] ?? 0));
        $montant = (int) round((float) $bon->montant);
        if ($montant > 0 && abs($total - $montant) / $montant > LectureTickets::ECART_TOTAL) {
            $valeurs = ['total' => $total, 'montant' => $montant, 'ecart' => abs($montant - $total)];
            array_unshift($avertissements, ['message_cle' => 'MSG-BC-024', 'valeurs' => $valeurs, 'message' => ErreurMetier::texte('MSG-BC-024', $valeurs)]);
        }

        if ($avertissements) {
            $premier = $avertissements[0];

            return self::controle(7, $premier['message_cle'] === 'MSG-BC-024' ? 'TICKETS_TOTAL' : 'TICKETS_INCOHERENTS', 'Lecture des tickets',
                self::AVERTISSEMENT, implode(' ', array_unique(array_column($avertissements, 'message'))),
                $premier['message_cle'], $premier['valeurs'], 4, 'pieces');
        }

        return self::controle(7, 'TICKETS', 'Lecture des tickets', self::OK, "{$tickets->count()} ticket(s) validé(s) et cohérent(s)");
    }

    private static function telephoneRetrait(BonCaisse $bon): array
    {
        $externe = $bon->type_beneficiaire !== 'employe';
        if ($externe && $bon->mode_paiement === 'especes' && blank($bon->telephone_beneficiaire)) {
            return self::controle(8, 'TELEPHONE_MANQUANT', 'Téléphone pour le code de retrait', self::BLOQUANT,
                __('MSG-BC-006'), 'MSG-BC-006', [], 2, 'telephone_beneficiaire');
        }

        return self::controle(8, 'TELEPHONE', 'Téléphone pour le code de retrait', self::OK,
            blank($bon->telephone_beneficiaire) ? 'Sans objet' : 'Présent');
    }

    private static function circuit(BonCaisse $bon): array
    {
        $circuit = CircuitPrevisionnel::pour($bon);
        $sansValideur = collect($circuit)->first(fn (array $niveau) => empty($niveau['valideurs']));

        if ($sansValideur) {
            $valeurs = ['niveau' => $sansValideur['libelle']];

            return self::controle(9, 'CIRCUIT', 'Circuit de validation', self::AVERTISSEMENT,
                ErreurMetier::texte('MSG-BC-042', $valeurs) . ' — ' . CircuitPrevisionnel::texte($circuit), 'MSG-BC-042', $valeurs);
        }

        return self::controle(9, 'CIRCUIT', 'Circuit de validation', self::OK, CircuitPrevisionnel::texte($circuit));
    }

    private static function visaDirecteurPays(BonCaisse $bon): array
    {
        return self::controle(10, 'VISA_DP', 'Visa du Directeur Pays', self::OK, $bon->necessite_validation_dp
            ? 'Requis (montant supérieur à ' . Format::montant(Parametre::seuilDP()) . ')'
            : 'Non requis');
    }

    private static function suiviApresPaiement(BonCaisse $bon): array
    {
        if ($bon->type_bon !== 'BP') {
            return self::controle(11, 'SUIVI', 'Suivi après paiement', self::OK, 'Bon définitif : pas de régularisation');
        }
        if ($bon->lie_mission && $bon->date_retour_mission) {
            return self::controle(11, 'SUIVI', 'Suivi après paiement', self::OK,
                'À régulariser avant le ' . Format::date($bon->dateLimiteRegularisation()));
        }
        $delai = (int) Parametre::valeur('delai_regularisation_autre', BonCaisse::DELAI_REGULARISATION_AUTRE);

        return self::controle(11, 'SUIVI', 'Suivi après paiement', self::OK,
            "À régulariser dans les {$delai} jours ouvrés suivant le paiement");
    }

    /** US-BC-13 (lot 5) : bon saisi pour le compte d'un collègue absent */
    /**
     * Contrôle 12 — RG-BC-29 : un bon initié pour le compte d'un collègue exige une délégation d'initiation encore active
     * au moment où l'initiateur le soumet (MSG-BC-033). Le titulaire peut toujours soumettre son propre bon.
     */
    private static function delegation(BonCaisse $bon, ?\App\Models\User $auteur): array
    {
        if (!$bon->initiateur_id || $bon->initiateur_id === $bon->demandeur_id) {
            return self::controle(12, 'DELEGATION', 'Délégation', self::OK, 'Sans objet');
        }

        $bon->loadMissing(['demandeur', 'initiateur']);
        $acteurId = $auteur?->id ?? $bon->initiateur_id;
        if ($acteurId === $bon->demandeur_id) {
            return self::controle(12, 'DELEGATION', 'Délégation', self::OK,
                'Bon initié pour votre compte par ' . ($bon->initiateur?->nom_complet ?? 'un collègue'));
        }

        $delegation = \App\Models\Delegation::initiationActiveEntre($bon->demandeur_id, $acteurId);
        if (!$delegation) {
            $valeurs = ['titulaire' => $bon->demandeur?->nom_complet ?? 'le titulaire'];

            return self::controle(12, 'DELEGATION_EXPIREE', 'Délégation', self::BLOQUANT,
                ErreurMetier::texte('MSG-BC-033', $valeurs), 'MSG-BC-033', $valeurs, 1, 'demandeur_id');
        }

        return self::controle(12, 'DELEGATION', 'Délégation', self::OK,
            "Délégation de {$bon->demandeur->nom_complet} active jusqu'au " . Format::date($delegation->date_fin));
    }

    private static function controle(int $numero, string $code, string $libelle, string $niveau, string $message,
        ?string $messageCle = null, array $valeurs = [], ?int $etape = null, ?string $champ = null): array
    {
        return compact('numero', 'code', 'libelle', 'niveau', 'message') + [
            'regle' => self::REGLES[$messageCle] ?? null,
            'message_cle' => $messageCle,
            'valeurs' => $valeurs,
            'etape' => $etape,
            'champ' => $champ,
        ];
    }
}
