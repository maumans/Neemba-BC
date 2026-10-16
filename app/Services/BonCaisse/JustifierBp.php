<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Delegation;
use App\Models\HistoriqueAction;
use App\Models\JustificationBp;
use App\Models\JustificationVisa;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Format;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Justification et régularisation d'un bon provisoire (spec v2.3, M07) :
 * - RG-M07-03 : écran de l'Annexe 3 — jusqu'à 10 lignes (intitulé, code analytique, n° de compte, montant, pièce) ;
 *   calculs : montant justifié, montant à retourner en caisse, restant à justifier, trop justifié ; visas
 *   initiateur → N+1 de l'initiateur → Contrôle de gestion → Comptabilité ; un rejet renvoie à l'initiateur ;
 * - RG-M07-01 : pour un visiteur du groupe, l'initiateur est le directeur du département d'accueil ;
 * - RG-M07-08 : au dernier visa, le BP est régularisé ; un trop justifié est versé par un BD de complément lié au BP ;
 *   le reliquat est rétrocédé en caisse (encaissement par le caissier).
 * Une étape sans valideur possible (incompatibilité, aucun titulaire) est sautée, sauf la Comptabilité.
 */
final class JustifierBp
{
    /** Initiateur de la justification : le directeur d'accueil pour un visiteur (RG-M07-01), sinon le demandeur du BP */
    public static function initiateurDe(BonCaisse $bp): ?User
    {
        return $bp->visiteur_id ? ($bp->visiteur?->directeur ?? $bp->demandeur) : $bp->demandeur;
    }

    /** Justification du BP, créée en brouillon à la première ouverture par son initiateur */
    public static function ouvrir(BonCaisse $bp, User $auteur): JustificationBp
    {
        if ($j = JustificationBp::where('bon_caisse_id', $bp->id)->first()) {
            return $j;
        }
        if ($bp->type_bon !== 'BP' || $bp->statut !== 'EN_ATTENTE_REGULARISATION') {
            throw new ErreurMetier('JUSTIFICATION_IMPOSSIBLE', 'MSG-APP-066', [], 'RG-M07-03', null, 409);
        }

        /* L'avance est le montant du bon (les frais Orange Money ne sont pas à justifier) */
        return JustificationBp::create([
            'bon_caisse_id' => $bp->id,
            'initiateur_id' => self::initiateurDe($bp)?->id ?? $auteur->id,
            'statut' => 'brouillon',
            'montant_avance' => (float) $bp->montant,
        ]);
    }

    public static function peutModifier(JustificationBp $j, User $utilisateur): bool
    {
        $bp = $j->bon;

        return $j->estModifiable() && $bp->statut === 'EN_ATTENTE_REGULARISATION'
            && in_array($utilisateur->id, array_filter([$j->initiateur_id, $bp->demandeur_id, $bp->initiateur_id]), true);
    }

    /**
     * Calculs de l'Annexe 3. Le montant à retourner est celui que la personne déclare rendre en caisse (au plus l'écart,
     * zéro par défaut) ; le restant à justifier doit être nul pour soumettre.
     *
     * @return array{justifie: float, a_retourner: float, restant: float, trop_justifie: float}
     */
    public static function calculs(float $avance, float $justifie, ?float $aRetourner = null): array
    {
        $ecart = max(0.0, $avance - $justifie);
        $retour = min(max(0.0, (float) $aRetourner), $ecart);

        return [
            'justifie' => $justifie,
            'a_retourner' => $retour,
            'restant' => round($ecart - $retour, 2),
            'trop_justifie' => max(0.0, $justifie - $avance),
        ];
    }

    /** Enregistrement des lignes (brouillon) ; les pièces doivent appartenir au BP */
    public static function enregistrer(JustificationBp $j, array $lignes, ?float $aRetourner, User $auteur): JustificationBp
    {
        if (!self::peutModifier($j, $auteur)) {
            throw new ErreurMetier('JUSTIFICATION_VERROUILLEE', 'MSG-APP-067', [], 'RG-M07-03', null, 403);
        }
        if (count($lignes) > JustificationBp::LIGNES_MAX) {
            throw new ErreurMetier('TROP_DE_LIGNES', 'MSG-APP-068', [], 'RG-M07-03', 'lignes', 422);
        }
        $piecesDuBon = $j->bon->piecesJointes()->pluck('id')->all();

        return DB::transaction(function () use ($j, $lignes, $aRetourner, $piecesDuBon) {
            $j->lignes()->delete();
            $total = 0.0;
            foreach (array_values($lignes) as $ordre => $ligne) {
                $piece = isset($ligne['piece_jointe_id']) && in_array((int) $ligne['piece_jointe_id'], $piecesDuBon, true) ? (int) $ligne['piece_jointe_id'] : null;
                $montant = round((float) ($ligne['montant'] ?? 0));
                $total += $montant;
                $j->lignes()->create([
                    'ordre' => $ordre,
                    'intitule' => mb_substr(trim((string) ($ligne['intitule'] ?? '')), 0, 150),
                    'code_analytique' => $ligne['code_analytique'] ?? null,
                    'compte' => filled($ligne['compte'] ?? null) ? mb_substr(trim($ligne['compte']), 0, 20) : null,
                    'montant' => $montant,
                    'piece_jointe_id' => $piece,
                ]);
            }
            $calculs = self::calculs((float) $j->montant_avance, $total, $aRetourner);
            $j->update(['montant_justifie' => $total, 'montant_a_retourner' => $calculs['a_retourner'], 'trop_justifie' => $calculs['trop_justifie']]);

            return $j->fresh('lignes');
        });
    }

    /** Pièce d'une ligne : ajoutée au BP, avec son empreinte et sa qualité (RG-M03-26, RG-M03-16) */
    public static function ajouterPiece(JustificationBp $j, UploadedFile $fichier, User $auteur): PieceJointe
    {
        if (!self::peutModifier($j, $auteur)) {
            throw new ErreurMetier('JUSTIFICATION_VERROUILLEE', 'MSG-APP-067', [], 'RG-M07-03', null, 403);
        }
        /* Une pièce déjà jointe à un autre bon est signalée sur la ligne et bloque la soumission (erreurs()) */
        return PiecesBon::ajouter($j->bon, $fichier, 'justificatif_bp', $auteur);
    }

    /** Erreurs qui empêchent la soumission (lignes incomplètes, pièce manquante, restant à justifier) */
    public static function erreurs(JustificationBp $j): array
    {
        $j->loadMissing('lignes.piece');
        $erreurs = [];
        if ($j->lignes->isEmpty()) {
            $erreurs[] = __('MSG-APP-069');
        }
        $codes = \App\Models\CodeAnalytique::where('actif', true)->pluck('code')->all();
        foreach ($j->lignes as $i => $ligne) {
            $n = $i + 1;
            if (mb_strlen(trim($ligne->intitule)) < 3 || (float) $ligne->montant <= 0 || !in_array($ligne->code_analytique, $codes, true) || !$ligne->piece_jointe_id) {
                $erreurs[] = __('MSG-APP-070', ['ligne' => $n]);
            } elseif ($ligne->piece?->doublon_de_id) {
                $erreurs[] = ErreurMetier::texte('MSG-M03-16', ControlesBon::valeursDoublon($ligne->piece));
            }
        }
        $calculs = self::calculs((float) $j->montant_avance, (float) $j->montant_justifie, (float) $j->montant_a_retourner);
        if ($calculs['restant'] > 0) {
            $erreurs[] = __('MSG-APP-071', ['restant' => Format::nombre($calculs['restant'])]);
        }

        return $erreurs;
    }

    public static function soumettre(JustificationBp $j, User $auteur): JustificationBp
    {
        if (!self::peutModifier($j, $auteur)) {
            throw new ErreurMetier('JUSTIFICATION_VERROUILLEE', 'MSG-APP-067', [], 'RG-M07-03', null, 403);
        }
        if ($erreurs = self::erreurs($j)) {
            throw new ErreurMetier('JUSTIFICATION_INCOMPLETE', 'MSG-APP-072', ['detail' => implode(' ', $erreurs)], 'RG-M07-03', 'lignes', 422);
        }

        return DB::transaction(function () use ($j, $auteur) {
            $version = $j->statut === 'rejetee' ? $j->version + 1 : $j->version;
            $j->update(['statut' => 'en_validation', 'version' => $version, 'soumise_le' => now(), 'etape' => null]);
            self::visa($j, 'initiateur', 'soumise', $auteur);
            HistoriqueAction::enregistrer($j->bon, HistoriqueAction::ACTION_REGULARISATION, $j->bon->statut, $j->bon->statut, $auteur->id,
                'Justification du BP soumise (version ' . $version . ') : ' . Format::montant($j->montant_justifie) . ' justifiés.');
            self::etapeSuivante($j, null, $auteur);

            return $j->fresh();
        });
    }

    /** Personnes qui peuvent viser une étape : titulaires compatibles, puis leurs suppléants (délégation « validation ») */
    public static function valideurs(JustificationBp $j, string $etape): Collection
    {
        $bp = $j->bon;
        $exclus = array_filter([$j->initiateur_id, $bp->demandeur_id, $bp->initiateur_id, $bp->beneficiaire_id]);
        $roles = JustificationBp::ROLES_ETAPES[$etape];
        $service = $etape === 'n1' ? ($j->initiateur?->service ?? $bp->service) : null;
        $titulaires = User::actifs()
            ->where(fn ($q) => $q->whereIn('role', $roles)->orWhereHas('roles', fn ($r) => $r->whereIn('role', $roles)))
            ->when($service, fn ($q) => $q->where('service', $service))
            ->get();
        $valideurs = $titulaires->reject(fn (User $u) => in_array($u->id, $exclus, true))->map(fn (User $u) => ['user' => $u, 'au_titre_de' => null]);
        foreach (Delegation::actives()->whereIn('delegant_id', $titulaires->pluck('id'))->with(['delegue', 'delegant'])->get() as $d) {
            if ($d->autorise('validation') && $d->delegue?->actif && !in_array($d->delegue_id, $exclus, true)
                && !$valideurs->contains(fn ($v) => $v['user']->id === $d->delegue_id)) {
                $valideurs->push(['user' => $d->delegue, 'au_titre_de' => $d->delegant]);
            }
        }

        return $valideurs->values();
    }

    /** L'utilisateur peut-il viser l'étape en cours ? (null sinon ; sinon le titulaire dont il est suppléant) */
    public static function peutViser(JustificationBp $j, User $utilisateur): ?array
    {
        if ($j->statut !== 'en_validation' || !$j->etape) {
            return null;
        }

        return self::valideurs($j, $j->etape)->first(fn ($v) => $v['user']->id === $utilisateur->id);
    }

    public static function viser(JustificationBp $j, User $valideur, bool $vise, ?string $commentaire = null): JustificationBp
    {
        $droit = self::peutViser($j, $valideur);
        if (!$droit) {
            throw new ErreurMetier('VISA_IMPOSSIBLE', 'MSG-APP-073', [], 'RG-M07-03', null, 403);
        }
        if (!$vise && mb_strlen(trim((string) $commentaire)) < 10) {
            throw new ErreurMetier('MOTIF_REJET', 'MSG-BC-002', [], 'RG-M07-03', 'commentaire', 422);
        }

        return DB::transaction(function () use ($j, $valideur, $vise, $commentaire, $droit) {
            $etape = $j->etape;
            self::visa($j, $etape, $vise ? 'visee' : 'rejetee', $valideur, $commentaire, $droit['au_titre_de'] ?? null);
            if (!$vise) {
                $j->update(['statut' => 'rejetee', 'etape' => null]);
                HistoriqueAction::enregistrer($j->bon, HistoriqueAction::ACTION_REJET, $j->bon->statut, $j->bon->statut, $valideur->id,
                    'Justification du BP rejetée (' . JustificationBp::ETAPES[$etape] . ') : ' . trim($commentaire));
                NotificationService::notifierJustification($j, $valideur, collect([$j->initiateur])->filter(), 'Justification rejetée',
                    "{$valideur->nom_complet} a rejeté la justification du BP {$j->bon->numero} : " . trim($commentaire));

                return $j->fresh();
            }
            self::etapeSuivante($j, $etape, $valideur);

            return $j->fresh();
        });
    }

    /** Passe à l'étape suivante, en sautant celles sans valideur (sauf la Comptabilité) ; finalise après la dernière */
    private static function etapeSuivante(JustificationBp $j, ?string $actuelle, User $auteur): void
    {
        $etapes = array_keys(JustificationBp::ETAPES);
        $index = $actuelle === null ? 0 : array_search($actuelle, $etapes, true) + 1;
        for (; $index < count($etapes); $index++) {
            $etape = $etapes[$index];
            $valideurs = self::valideurs($j, $etape);
            if ($valideurs->isNotEmpty() || $etape === 'comptabilite') {
                $j->update(['etape' => $etape]);
                NotificationService::notifierJustification($j, $auteur, $valideurs->pluck('user'), 'Justification de BP à viser',
                    "Justification du BP {$j->bon->numero} (" . Format::montant($j->montant_justifie) . ") à viser : " . JustificationBp::ETAPES[$etape] . '.');

                return;
            }
            self::visa($j, $etape, 'sautee', null, 'Aucun valideur possible (demandeur, bénéficiaire ou initiateur, sans suppléant) : étape sautée.');
        }
        self::finaliser($j, $auteur);
    }

    /** RG-M07-08 : BP régularisé ; trop justifié versé par un BD de complément lié ; reliquat attendu en caisse */
    private static function finaliser(JustificationBp $j, User $auteur): void
    {
        $bp = $j->bon;
        $j->update(['statut' => 'validee', 'etape' => null, 'validee_le' => now()]);
        $bp->regulariser($auteur->id, 'Justification visée : ' . Format::montant($j->montant_justifie) . ' justifiés'
            . ((float) $j->montant_a_retourner > 0 ? ', reliquat de ' . Format::montant($j->montant_a_retourner) . ' à rétrocéder en caisse' : '')
            . ((float) $j->trop_justifie > 0 ? ', complément de ' . Format::montant($j->trop_justifie) . ' à verser' : '') . '.');

        if ((float) $j->trop_justifie > 0) {
            $emettrice = $bp->caisseEmettrice ?? Caisse::deRattachement($bp->demandeur);
            $complement = BonCaisse::create([
                'numero' => NumeroteurBon::prochain($emettrice),
                'type_bon' => 'BD',
                'statut' => 'EN_ATTENTE_DAF',
                'site' => $bp->site,
                'service' => $bp->service,
                'code_analytique' => $bp->code_analytique,
                'demandeur_id' => $bp->demandeur_id,
                'initiateur_id' => $j->initiateur_id,
                'type_beneficiaire' => $bp->type_beneficiaire,
                'beneficiaire_id' => $bp->beneficiaire_id,
                'visiteur_id' => $bp->visiteur_id,
                'tiers_id' => $bp->tiers_id,
                'beneficiaire' => $bp->beneficiaire,
                'telephone_beneficiaire' => $bp->telephone_beneficiaire,
                'motif' => 'Complément de régularisation du BP ' . $bp->numero . ' (trop justifié)',
                'categorie_depense' => $bp->categorie_depense,
                'montant' => (float) $j->trop_justifie,
                'mode_paiement' => 'especes',
                'niveau_urgence' => 'normale',
                'date_demande' => today(),
                'date_soumission' => now(),
                'devise' => 'GNF',
                'version' => 1,
                'bp_regularise_id' => $bp->id,
                'caisse_emettrice_id' => $emettrice?->id,
            ]);
            $complement->update(['caisse_id' => $complement->caissePayeuse()?->id]);

            /* §8.2 : aucun décaissement sans visa de la Finance. Les visas N+1 et CDG sont déjà portés par la
             * justification : leurs étapes sont notées sautées, le complément part directement à la Finance (puis
             * au Directeur Pays si son montant dépasse le seuil). */
            $complement->creerEtapesValidation();
            $complement->validations()->whereIn('role', ['responsable_service', 'controle_gestion'])->update([
                'statut' => 'saute',
                'commentaire' => "Visa déjà porté par la justification du BP {$bp->numero} (initiateur, N+1, CDG, Comptabilité).",
                'date_validation' => now(),
                'date_attribution' => now(),
            ]);
            $complement->validations()->where('role', 'daf')->update(['date_attribution' => now()]);

            HistoriqueAction::enregistrer($complement, HistoriqueAction::ACTION_CREATION, null, 'EN_ATTENTE_DAF', $auteur->id,
                "Bon de complément créé à la régularisation du BP {$bp->numero}, en attente du visa de la Finance.");
            $j->update(['bon_complement_id' => $complement->id]);
            NotificationService::notifierSoumission($complement->fresh(['demandeur']), $auteur);
        }

        NotificationService::notifierJustification($j, $auteur, collect([$j->initiateur, $bp->demandeur])->filter()->unique('id'), 'BP régularisé',
            "La justification du BP {$bp->numero} est visée : le BP est régularisé."
            . ((float) $j->montant_a_retourner > 0 ? ' Rendez le reliquat de ' . Format::montant($j->montant_a_retourner) . ' à la caisse.' : '')
            . ((float) $j->trop_justifie > 0 ? ' Le complément de ' . Format::montant($j->trop_justifie) . ' sera versé par la caisse.' : ''));
    }

    /** RG-M07-08 : le caissier encaisse le reliquat rendu (rétrocession en caisse) */
    public static function encaisserReliquat(JustificationBp $j, User $caissier): JustificationBp
    {
        $bp = $j->bon;
        if (!$j->reliquatAEncaisser()) {
            throw new ErreurMetier('RELIQUAT_ABSENT', 'MSG-APP-074', [], 'RG-M07-08', null, 409);
        }
        /* Le reliquat est rendu en espèces : caisse espèces qui paie les bons de la caisse émettrice du BP */
        $caisse = $bp->caissePayeuse('especes') ?? ($bp->caisse_id ? Caisse::find($bp->caisse_id) : null);
        $refus = $caisse ? ControleCaissier::refus($bp, $caissier, $caisse) : 'Aucune caisse espèces ne reçoit ce reliquat.';
        if ($refus) {
            throw new ErreurMetier('ENCAISSEMENT_REFUSE', 'MSG-APP-076', ['motif' => $refus], 'RG-M06-01', null, 403);
        }

        return DB::transaction(function () use ($j, $bp, $caissier, $caisse) {
            $caisse->crediter((float) $j->montant_a_retourner, 'retrocession_bp', [
                'bon_caisse_id' => $bp->id,
                'utilisateur_id' => $caissier->id,
                'libelle' => "Reliquat du BP {$bp->numero} — {$bp->beneficiaire}",
            ]);
            $j->update(['reliquat_encaisse_le' => now(), 'reliquat_encaisse_par_id' => $caissier->id]);
            HistoriqueAction::enregistrer($bp, HistoriqueAction::ACTION_REGULARISATION, $bp->statut, $bp->statut, $caissier->id,
                'Reliquat de ' . Format::montant($j->montant_a_retourner) . " rétrocédé en caisse ({$caisse->libelle}).");

            return $j->fresh();
        });
    }

    /** Valideur de l'étape en cours, ou qui a déjà visé : il consulte le BP (visibilité de la fiche) */
    public static function concerne(BonCaisse $bp, User $utilisateur): bool
    {
        $j = JustificationBp::where('bon_caisse_id', $bp->id)->first();
        if (!$j) {
            /* Pour un visiteur, le directeur d'accueil ouvre la justification (RG-M07-01) */
            return $bp->statut === 'EN_ATTENTE_REGULARISATION' && self::initiateurDe($bp)?->id === $utilisateur->id;
        }

        return self::peutViser($j, $utilisateur) !== null || $j->visas()->where('valideur_id', $utilisateur->id)->exists()
            || $j->initiateur_id === $utilisateur->id;
    }

    private static function visa(JustificationBp $j, string $etape, string $decision, ?User $auteur, ?string $commentaire = null, ?User $auTitreDe = null): void
    {
        JustificationVisa::create([
            'justification_id' => $j->id,
            'version' => $j->version,
            'etape' => $etape,
            'decision' => $decision,
            'valideur_id' => $auteur?->id,
            'au_titre_de_id' => $auTitreDe?->id,
            'commentaire' => $commentaire,
        ]);
    }
}
